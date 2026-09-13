<?php

declare(strict_types=1);

/**
 * 路由优先级回归测试：静态路由（路径中不含 {param}）必须优先于参数路由匹配。
 *
 * 真实案例：/api/admin/students/check-id 必须先于 /api/admin/students/{id} 命中，
 * 否则 check-id 会被 {id} 正则([^/]+) 吞掉，路由到 StudentController::show 并报「考生不存在」。
 * 修复位于 core/Router.php：dispatch/match 改为「静态优先、组内保序」两遍匹配。
 */

require __DIR__ . '/../../core/helpers.php';

use Test\Harness;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';

bootstrap();

$t = new Harness();
echo "== 路由优先级测试 ==\n";

$router = new Core\Router();
$routes = require $base . '/config/routes.php';
foreach ($routes as $route) {
    if (!is_array($route) || count($route) < 3) {
        continue;
    }
    [$method, $path, $handler] = $route;
    $router->add($method, $path, $handler, $route[3] ?? []);
}

// 核心断言：静态子路径优先于同前缀的参数路由
$t->assertSame(
    '静态路由优先于参数路由 (students/check-id)',
    '/api/admin/students/check-id',
    $router->match('GET', '/api/admin/students/check-id')
);
$t->assertSame(
    '静态路由优先于参数路由 (students/import)',
    '/api/admin/students/import',
    $router->match('POST', '/api/admin/students/import')
);

// 反向断言：真实 id 仍应命中参数路由，证明参数路由未被破坏
$t->assertSame(
    '参数路由仍匹配真实 id',
    '/api/admin/students/{id}',
    $router->match('GET', '/api/admin/students/5')
);

$t->finish();
