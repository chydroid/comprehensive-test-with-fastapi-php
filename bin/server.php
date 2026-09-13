<?php

declare(strict_types=1);

/**
 * Swoole 常驻内存 HTTP 服务器入口
 *
 * 用法:
 *   php bin/server.php            # 0.0.0.0:9501
 *   php bin/server.php 0.0.0.0 9501 4   # host port worker数
 *
 * 前置: 需安装 swoole 扩展 (pecl install swoole) 并启用
 */

require dirname(__DIR__) . '/core/helpers.php';

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "[SwooleAdapter] 未安装 swoole 扩展，无法启动常驻服务器（php -m | grep swoole）\n");
    exit(1);
}

// 只引导一次：Router 编译缓存、DB/缓存连接、App 单例在常驻进程中复用，这是性能收益的来源
bootstrap();

Core\SwooleAdapter::start([
    'host'    => $argv[1] ?? '0.0.0.0',
    'port'    => (int) ($argv[2] ?? 9501),
    'workers' => (int) ($argv[3] ?? 4),
]);
