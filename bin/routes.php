<?php
declare(strict_types=1);

/**
 * 路由列表命令行工具
 * 用法: php bin/routes.php
 * 输出：方法、路径、处理器、路由级中间件
 */

require dirname(__DIR__) . '/core/helpers.php';

$app = bootstrap();
$rows = [];
foreach ($app->router()->all() as $method => $routes) {
    foreach ($routes as $route) {
        $handler = is_array($route['handler'])
            ? implode('::', $route['handler'])
            : 'closure';
        $mw = implode(',', array_map(
            static fn ($m) => is_string($m) ? $m : get_class($m),
            $route['middleware']
        ));
        $rows[] = sprintf('%-7s %-28s %-46s %s', $method, $route['path'], $handler, $mw);
    }
}

echo "METHOD  PATH                         HANDLER                                        MIDDLEWARE\n";
echo str_repeat('-', 100) . "\n";
echo $rows === [] ? "（无路由）\n" : implode("\n", $rows) . "\n";
