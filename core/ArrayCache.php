<?php

declare(strict_types=1);

namespace Core;

/**
 * 进程内（单请求）内存缓存
 *
 * - 零依赖、无跨进程/跨请求共享，仅当前 PHP 进程内有效
 * - 适合请求作用域内的短时缓存；作为缓存后端时优先级最低（仅在文件之上）
 * - 值原样保存（不做序列化），支持 TTL（0 表示不过期）
 */
final class ArrayCache
{
    /** @var array<string, array{value:mixed, exp:?int}> */
    private static array $store = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$store)) {
            return $default;
        }
        $item = self::$store[$key];
        if ($item['exp'] !== null && $item['exp'] < time()) {
            unset(self::$store[$key]);
            return $default;
        }
        return $item['value'];
    }

    public static function set(string $key, mixed $value, int $ttl): void
    {
        self::$store[$key] = [
            'value' => $value,
            'exp'   => $ttl > 0 ? time() + $ttl : null,
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$store);
    }

    public static function delete(string $key): void
    {
        unset(self::$store[$key]);
    }

    /** 清空进程内缓存（测试隔离用） */
    public static function clear(): void
    {
        self::$store = [];
    }
}
