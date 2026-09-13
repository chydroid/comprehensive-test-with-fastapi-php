<?php

declare(strict_types=1);

namespace Core;

/**
 * Redis 连接封装（基于 phpredis 扩展）
 *
 * - 零 Composer 依赖：依赖的是 PECL 的 phpredis 扩展（phpredis），非第三方包
 * - available() 探测扩展是否可用；connection() 返回进程内单例连接
 * - 扩展未安装或连接失败时抛 \RuntimeException，由调用方决定降级策略
 */
final class Redis
{
    private static ?\Redis $conn = null;

    /** phpredis 扩展是否可用 */
    public static function available(): bool
    {
        return class_exists(\Redis::class);
    }

    /** 获取进程内单例连接（未配置/不可用时抛异常） */
    public static function connection(): \Redis
    {
        if (self::$conn === null) {
            if (!self::available()) {
                throw new \RuntimeException('phpredis 扩展未安装，无法使用 Redis');
            }
            $cfg = (array) config('redis', []);
            $r = new \Redis();
            $ok = $r->connect(
                (string) ($cfg['host'] ?? '127.0.0.1'),
                (int) ($cfg['port'] ?? 6379),
                (float) ($cfg['timeout'] ?? 2.0)
            );
            if (!$ok) {
                throw new \RuntimeException('无法连接 Redis: ' . ($cfg['host'] ?? '127.0.0.1') . ':' . ($cfg['port'] ?? 6379));
            }
            $auth = (string) ($cfg['auth'] ?? '');
            if ($auth !== '') {
                $r->auth($auth);
            }
            if (!empty($cfg['db'])) {
                $r->select((int) $cfg['db']);
            }
            self::$conn = $r;
        }
        return self::$conn;
    }

    /** 重置连接（测试或重连用） */
    public static function reset(): void
    {
        self::$conn = null;
    }
}
