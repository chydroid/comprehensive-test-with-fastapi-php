<?php
declare(strict_types=1);

namespace App\Middlewares;

use Core\Middleware;
use Core\Request;
use Core\Response;
use Core\Redis;

/**
 * 限流中间件：固定窗口计数，按 IP+路径 限流
 *
 * 驱动（rate_limit.driver）：
 * - redis：基于 Redis INCR + EXPIRE，原子、跨进程共享、自动过期（无竞态）
 * - file（默认）：文件缓存计数，零依赖，适合中小流量
 * 配置为 redis 但 phpredis 不可用时自动降级 file 并记警告，避免服务不可用。
 *
 * 登录类接口（路径以 /login 结尾）使用更严格的独立配额，防暴力破解。
 */
class RateLimitMiddleware implements Middleware
{
    /** 登录接口专属配额（比全局更严） */
    private const LOGIN_MAX = 10;
    private const LOGIN_WINDOW = 300;

    private string $driver;

    public function __construct(?string $driver = null)
    {
        $this->driver = $driver ?? (string) (config('rate_limit.driver') ?? 'file');
    }

    public function handle(Request $request, callable $next): Response
    {
        $cfg = (array) config('rate_limit', []);
        if (!($cfg['enabled'] ?? false)) {
            return $next($request);
        }

        $isLogin = str_ends_with($request->path(), '/login');
        $window = $isLogin ? self::LOGIN_WINDOW : (int) ($cfg['window_seconds'] ?? 60);
        $max = $isLogin ? self::LOGIN_MAX : (int) ($cfg['max_requests'] ?? 60);
        $rawKey = md5($request->ip() . '|' . $request->path());

        $count = $this->driver === 'redis' && Redis::available()
            ? $this->incrementRedis($rawKey, $window)
            : $this->incrementFile($rawKey, $window);

        if ($count > $max) {
            $response = (new Response())->error(42900, '请求过于频繁，请稍后再试', 429);
            $response->header('Retry-After', (string) $window);
            return $response;
        }
        return $next($request);
    }

    /** Redis 原子计数：INCR，首次设置 TTL；连接/命令失败时降级 file，避免 500 */
    private function incrementRedis(string $key, int $window): int
    {
        try {
            $redis = Redis::connection();
            $k = 'rl:' . $key;
            $count = $redis->incr($k);
            if ((int) $count === 1) {
                $redis->expire($k, $window);
            }
            return (int) $count;
        } catch (\Throwable $e) {
            log_message('Redis 限流不可用，已降级 file 驱动: ' . $e->getMessage(), 'warning');
            $this->driver = 'file';
            return $this->incrementFile($key, $window);
        }
    }

    /** 文件计数：flock 独占锁包裹读-改-写，保证并发原子 */
    private function incrementFile(string $key, int $window): int
    {
        $dir = BASE_PATH . '/storage/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return 0; // 无法写入缓存时放行，避免误伤
        }
        $this->cleanup($dir, $window);

        $file = $dir . '/rl_' . $key . '.json';
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return 0; // fail-open
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return 0; // fail-open
            }
            $raw = stream_get_contents($fp);
            $data = $raw !== '' && $raw !== false ? (array) json_decode($raw, true) : [];
            if (($data['ts'] ?? 0) < time() - $window) {
                $data = ['ts' => time(), 'count' => 0];
            }
            $data['count']++;
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
            return (int) $data['count'];
        } finally {
            fclose($fp);
        }
    }

    /** 每 10 个窗口清理一次过期计数文件 */
    private function cleanup(string $dir, int $window): void
    {
        $marker = $dir . '/_last_cleanup';
        $last = is_file($marker) ? (int) @file_get_contents($marker) : 0;
        $interval = $window * 10;
        if (time() - $last < $interval) {
            return;
        }
        // 多进程并发下用 flock 抢标记，避免同时进入清理
        $fp = @fopen($marker, 'c+');
        if ($fp === false) {
            return;
        }
        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                return;
            }
            foreach (glob($dir . '/rl_*.json') ?: [] as $f) {
                $mtime = @filemtime($f);
                if ($mtime !== false && time() - (int) $mtime > $interval) {
                    @unlink($f);
                }
            }
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, (string) time());
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }
}
