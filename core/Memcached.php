<?php

declare(strict_types=1);

namespace Core;

/**
 * Memcached 连接封装（基于 Memcached 扩展，libmemcached）
 *
 * - 零 Composer 依赖：依赖的是 PECL 的 memcached 扩展，非第三方包
 * - available() 探测扩展；connection() 返回进程内单例连接
 * - 扩展未安装时抛 \RuntimeException，由调用方决定降级策略
 */
final class Memcached
{
    private static ?\Memcached $conn = null;

    /** memcached 扩展是否可用 */
    public static function available(): bool
    {
        return class_exists(\Memcached::class);
    }

    /** 获取进程内单例连接（未配置/不可用时抛异常） */
    public static function connection(): \Memcached
    {
        if (self::$conn === null) {
            if (!self::available()) {
                throw new \RuntimeException('memcached 扩展未安装，无法使用 Memcached');
            }
            $cfg = (array) config('memcached', []);
            $m = new \Memcached();
            $hosts = (array) ($cfg['servers'] ?? [[
                'host' => (string) ($cfg['host'] ?? '127.0.0.1'),
                'port' => (int) ($cfg['port'] ?? 11211),
                'weight' => (int) ($cfg['weight'] ?? 1),
            ]]);
            // servers 支持 [[host,port,weight], ...] 或 [['host'=>..,'port'=>..], ...]
            $normalized = [];
            foreach ($hosts as $server) {
                if (is_array($server) && isset($server[0])) {
                    $normalized[] = [(string) $server[0], (int) ($server[1] ?? 11211), (int) ($server[2] ?? 1)];
                } elseif (is_array($server)) {
                    $normalized[] = [
                        (string) ($server['host'] ?? '127.0.0.1'),
                        (int) ($server['port'] ?? 11211),
                        (int) ($server['weight'] ?? 1),
                    ];
                }
            }
            if ($normalized === []) {
                $normalized = [['127.0.0.1', 11211, 1]];
            }
            if ($m->addServers($normalized) === false) {
                throw new \RuntimeException('Memcached 服务器配置无效');
            }
            self::$conn = $m;
        }
        return self::$conn;
    }

    /** 重置连接（测试或重连用） */
    public static function reset(): void
    {
        self::$conn = null;
    }
}
