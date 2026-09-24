<?php

declare(strict_types=1);

namespace Core;

/**
 * 通用缓存：多后端抽象，按可用性选择优先级
 *   redis（phpredis）> memcached（memcached 扩展）> apcu（apcu 扩展）> array（进程内）> file（零依赖兜底）
 *
 * - 按各后端 enabled 配置且对应扩展可用来选择后端
 * - 未显式配置后端时，APCu 可用则自动启用（内存兜底，避免落到慢速文件）
 * - 连接失败时降级到文件后端并记录警告，保证服务不中断
 * - 值统一 JSON 序列化（Redis/文件），Memcached/APCu/Array 由后端自行处理
 */
final class Cache
{
    private const PREFIX = 'cache:';
    private static string $backend = '';

    /** 探测并缓存当前后端 */
    private static function backend(): string
    {
        if (self::$backend === '') {
            if (Redis::available() && (bool) config('redis.enabled', false)) {
                self::$backend = 'redis';
            } elseif (Memcached::available() && (bool) config('memcached.enabled', false)) {
                self::$backend = 'memcached';
            } elseif (Apcu::available() && (bool) config('apcu.enabled', false)) {
                self::$backend = 'apcu';
            } elseif ((bool) config('array.enabled', false)) {
                self::$backend = 'array';
            } elseif (Apcu::available()) {
                // 智能默认：未显式配置任何内存/外部后端时，APCu 可用则用内存，避免落到慢速文件缓存
                self::$backend = 'apcu';
            } else {
                self::$backend = 'file';
            }
        }
        return self::$backend;
    }

    /** 重置后端选择（连接失败降级后，测试/重连时重新探测用） */
    public static function reset(): void
    {
        self::$backend = '';
    }

    /** 读取缓存，不存在/已过期返回 $default */
    public static function get(string $key, mixed $default = null): mixed
    {
        $key = self::PREFIX . $key;
        switch (self::backend()) {
            case 'redis':
                try {
                    $raw = Redis::connection()->get($key);
                    if ($raw === false || !is_string($raw)) {
                        return $default;
                    }
                    $decoded = json_decode($raw, true);
                    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
                } catch (\Throwable $e) {
                    self::$backend = 'file'; // 连接失败，后续不再反复尝试 redis
                    log_message('Redis 不可用，Cache::get 降级文件: ' . $e->getMessage(), 'warning');
                    return self::fileGet($key, $default);
                }
            case 'memcached':
                try {
                    $m = Memcached::connection();
                    $v = $m->get($key);
                    // getResultCode()==RES_SUCCESS 判定"存在"，正确处理 false/null 值
                    return $m->getResultCode() === \Memcached::RES_SUCCESS ? $v : $default;
                } catch (\Throwable $e) {
                    self::$backend = 'file';
                    log_message('Memcached 不可用，Cache::get 降级文件: ' . $e->getMessage(), 'warning');
                    return self::fileGet($key, $default);
                }
            case 'apcu':
                try {
                    $success = false;
                    $v = apcu_fetch($key, $success);
                    return $success ? $v : $default;
                } catch (\Throwable $e) {
                    self::$backend = 'file';
                    log_message('APCu 不可用，Cache::get 降级文件: ' . $e->getMessage(), 'warning');
                    return self::fileGet($key, $default);
                }
            case 'array':
                return ArrayCache::get($key, $default);
            default:
                return self::fileGet($key, $default);
        }
    }

    /** 写入缓存；$ttl 为秒，0 表示不过期 */
    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        $key = self::PREFIX . $key;
        switch (self::backend()) {
            case 'redis':
                $payload = json_encode($value);
                if ($payload === false) {
                    // 值无法 JSON 序列化（如含非法 UTF-8）→ 记录告警，避免静默丢缓存
                    log_message('Cache::set 值无法 JSON 序列化，写入失败: ' . $key, 'warning');
                    return;
                }
                try {
                    $r = Redis::connection();
                    if ($ttl > 0) {
                        $r->setex($key, $ttl, $payload);
                    } else {
                        $r->set($key, $payload);
                    }
                    return;
                } catch (\Throwable $e) {
                    self::$backend = 'file';
                    log_message('Redis 不可用，Cache::set 降级文件: ' . $e->getMessage(), 'warning');
                    self::fileSet($key, $value, $ttl);
                    return;
                }
            case 'memcached':
                try {
                    $m = Memcached::connection();
                    $ok = $m->set($key, $value, $ttl > 0 ? $ttl : 0);
                    if ($ok !== true) {
                        // Memcached 失败返回 false 而非抛异常，须显式降级，避免静默丢数据
                        self::$backend = 'file';
                        log_message('Memcached 写入失败，Cache::set 降级文件: ' . $m->getResultMessage(), 'warning');
                        self::fileSet($key, $value, $ttl);
                        return;
                    }
                    return;
                } catch (\Throwable $e) {
                    self::$backend = 'file';
                    log_message('Memcached 不可用，Cache::set 降级文件: ' . $e->getMessage(), 'warning');
                    self::fileSet($key, $value, $ttl);
                    return;
                }
            case 'apcu':
                try {
                    // ttl=0 表示不过期，与文件/Redis 语义一致
                    apcu_store($key, $value, $ttl > 0 ? $ttl : 0);
                    return;
                } catch (\Throwable $e) {
                    self::$backend = 'file';
                    log_message('APCu 不可用，Cache::set 降级文件: ' . $e->getMessage(), 'warning');
                    self::fileSet($key, $value, $ttl);
                    return;
                }
            case 'array':
                ArrayCache::set($key, $value, $ttl);
                return;
            default:
                self::fileSet($key, $value, $ttl);
        }
    }

    public static function has(string $key): bool
    {
        $sentinel = new \stdClass();
        return self::get($key, $sentinel) !== $sentinel;
    }

    public static function delete(string $key): void
    {
        $key = self::PREFIX . $key;
        switch (self::backend()) {
            case 'redis':
                try {
                    Redis::connection()->del($key);
                    return;
                } catch (\Throwable $e) {
                    break; // 降级文件
                }
            case 'memcached':
                try {
                    Memcached::connection()->delete($key);
                    return;
                } catch (\Throwable $e) {
                    break; // 降级文件
                }
            case 'apcu':
                try {
                    apcu_delete($key);
                    return;
                } catch (\Throwable $e) {
                    break; // 降级文件
                }
            case 'array':
                ArrayCache::delete($key);
                return;
        }
        @unlink(self::cacheDir() . '/' . md5($key) . '.cache');
    }

    /* ---- 文件后端 ---- */

    private static function cacheDir(): string
    {
        return BASE_PATH . '/storage/cache';
    }

    private static function fileGet(string $key, mixed $default): mixed
    {
        $file = self::cacheDir() . '/' . md5($key) . '.cache';
        if (!is_file($file)) {
            return $default;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) {
            return $default;
        }
        if (isset($data['exp']) && (int) $data['exp'] < time()) {
            @unlink($file);
            return $default;
        }
        // 用 array_key_exists，避免缓存 false/null 值时误判为不存在
        return array_key_exists('value', $data) ? $data['value'] : $default;
    }

    private static function fileSet(string $key, mixed $value, int $ttl): void
    {
        $dir = self::cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return;
        }
        $data = ['value' => $value];
        if ($ttl > 0) {
            $data['exp'] = time() + $ttl;
        }
        $encoded = json_encode($data);
        if ($encoded === false) {
            // 值无法 JSON 序列化（如含非法 UTF-8）→ 记警告并跳过写入，避免产生损坏的 .cache 文件
            log_message('Cache::fileSet 值无法 JSON 序列化，写入失败: ' . $key, 'warning');
            return;
        }
        // 原子写：写临时文件 + rename，避免并发读到半截文件（与 RateLimitMiddleware flock 语义一致但更简单可靠）
        $target = $dir . '/' . md5($key) . '.cache';
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $encoded, LOCK_EX) !== false) {
            @rename($tmp, $target);
        } else {
            @unlink($tmp);
            log_message('Cache::fileSet 写入失败: ' . $target, 'warning');
        }
    }
}
