<?php

declare(strict_types=1);

/**
 * 内置开发服务器路由脚本（php -S）
 *
 * 用法: php -S 127.0.0.1:8080 -t public public/router.php
 *
 * 规则：
 * - 真实存在的静态文件（css/js/svg/png/...）直接交给内置服务器返回；
 * - 其余请求一律重写到 index.php（前端控制器），与生产 Nginx 的 try_files 等价。
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $uri;

// 目录请求回落到 index.php（避免目录列举）
if ($uri !== '/' && is_file($file)) {
    return false; // 交给内置服务器处理静态文件
}

require __DIR__ . '/index.php';
