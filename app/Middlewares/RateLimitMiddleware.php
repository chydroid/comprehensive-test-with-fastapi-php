<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Services\Setting;
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
 *
 * 阈值来源：优先读取后台「系统设置 → 安全策略」（siteconfig 表），
 * 读不到时回落到 config 文件中的 rate_limit.*，最后使用内置默认值。
 * 因此运维调整限流强度无需改代码或重启。
 */
class RateLimitMiddleware implements Middleware
{
    /** 登录接口专属配额的兜底值（后台设置缺失时使用） */
    private const DEFAULT_LOGIN_MAX = 10;

    private string $driver;

    public function __construct(?string $driver = null)
    {
        $this->driver = $driver ?? (string) (config('rate_limit.driver') ?? 'file');
    }

    public function handle(Request $request, callable $next): Response
    {
        // 总开关：管理员显式配置过则以后台设置为准，否则沿用 config 文件
        $enabled = Setting::stored('rate_limit_enabled') === null
            ? (bool) config('rate_limit.enabled', false)
            : Setting::bool('rate_limit_enabled');

        if (!$enabled) {
            return $next($request);
        }

        $cfg = (array) config('rate_limit', []);
        $isLogin = str_ends_with($request->path(), '/login');

        if ($isLogin) {
            // 登录接口配额（后台按分钟配置，窗口至少 1 分钟）
            $window = max(60, Setting::int('login_window_minutes', 5) * 60);
            $max = max(1, Setting::int('login_max_attempts', self::DEFAULT_LOGIN_MAX));
        } else {
            $window = max(1, Setting::int('rate_limit_window_seconds', (int) ($cfg['window_seconds'] ?? 60)));
            $max = max(1, Setting::int('rate_limit_max_requests', (int) ($cfg['max_requests'] ?? 60)));
        }

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
