<?php

declare(strict_types=1);

namespace Core;

/**
 * APCu 支持封装（基于 PECL 的 apcu 扩展）
 *
 * - 零 Composer 依赖：依赖的是 PECL 的 apcu 扩展（本机进程内共享内存用户缓存）
 * - available() 探测扩展是否可用；CLI 下需 apcu.enable_cli=1 才可用
 * - APCu 为单机进程内共享存储，不做跨主机；优先级低于 Redis/Memcached
 */
final class Apcu
{
    /** apcu 扩展是否启用（含 CLI 的 apcu.enable_cli 判断） */
    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
