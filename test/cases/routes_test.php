<?php

declare(strict_types=1);

/**
 * 路由完整性测试：确认 config/routes.php 中声明的每一个处理器
 * 都指向「真实存在的类 + 真实存在的 public 方法」。
 *
 * 为什么需要它：控制器缺失时，框架只在路由被真正命中时才 autoload 失败，
 * 平时 bootstrap 一切正常 —— 这类问题会一直潜伏到线上。
 * 本测试在启动阶段就把缺失暴露出来。
 */

require __DIR__ . '/../../core/helpers.php';

use Test\Harness;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';

bootstrap();

$t = new Harness();
echo "== 路由完整性测试 ==\n";

$routes = require $base . '/config/routes.php';
$t->assertTrue('路由表非空', $routes !== [], 'routes.php 返回空数组');

$missingClass = [];
$missingMethod = [];
$duplicates = [];
$seen = [];

foreach ($routes as $i => $route) {
    if (!is_array($route) || count($route) < 3) {
        $t->register("路由 #{$i} 结构合法", false, '数组元素不足 3 项');
        continue;
    }
    [$method, $path, $handler] = $route;

    // 重复路由（同方法 + 同路径）
    $key = strtoupper((string) $method) . ' ' . $path;
    if (isset($seen[$key])) {
        $duplicates[] = $key;
    }
    $seen[$key] = true;

    if (is_callable($handler)) {
        continue; // 闭包处理器
    }
    if (!is_array($handler) || count($handler) !== 2) {
        $t->register("路由 {$method} {$path} 处理器合法", false, '处理器格式不正确');
        continue;
    }

    [$class, $action] = $handler;
    if (!class_exists($class)) {
        $missingClass[$class][] = "{$method} {$path}";
        continue;
    }
    if (!method_exists($class, $action)) {
        $missingMethod["{$class}::{$action}"][] = "{$method} {$path}";
    }
}

$t->assertTrue(
    '所有路由的控制器类均存在',
    $missingClass === [],
    $missingClass === [] ? '' : implode('; ', array_map(
        static fn (string $c, array $paths): string => "{$c} (被 " . count($paths) . ' 条路由引用)',
        array_keys($missingClass),
        array_values($missingClass)
    ))
);

$t->assertTrue(
    '所有路由的控制器方法均存在',
    $missingMethod === [],
    $missingMethod === [] ? '' : implode('; ', array_map(
        static fn (string $m, array $paths): string => "{$m} (" . implode(', ', array_slice($paths, 0, 3)) . ')',
        array_keys($missingMethod),
        array_values($missingMethod)
    ))
);

$t->assertTrue(
    '无重复路由定义',
    $duplicates === [],
    $duplicates === [] ? '' : implode(', ', array_slice($duplicates, 0, 10))
);

$t->register('路由总数 (' . count($routes) . ')', count($routes) > 100, '路由数量异常偏少');

/* ---------- 关键安全路由的鉴权归属检查 ---------- */
$publicPrefixes = ['/api/health', '/api/public/', '/api/openapi.json', '/api/docs', '/api/metrics'];
$writeMethodRoutes = [];
foreach ($routes as [$method, $path, $handler]) {
    if (in_array(strtoupper((string) $method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $writeMethodRoutes[$path] = true;
    }
}

// 所有 /api/admin/** 写路由都不应落在公开前缀里
$leaked = [];
foreach (array_keys($writeMethodRoutes) as $path) {
    if (str_starts_with((string) $path, '/api/admin/')) {
        foreach ($publicPrefixes as $prefix) {
            if (str_starts_with((string) $path, $prefix)) {
                $leaked[] = $path;
            }
        }
    }
}
$t->assertTrue('管理端写接口未暴露为公开路由', $leaked === [], implode(', ', $leaked));

exit($t->finish());
