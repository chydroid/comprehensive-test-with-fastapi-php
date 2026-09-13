<?php
declare(strict_types=1);

namespace Core;

/**
 * 路由注册与分发
 * 支持 {id} 路径参数、五种 HTTP 方法；handler 为 [类, 方法] 或闭包
 */
class Router
{
    /** @var array<string, array<int, array{pattern:string, handler:callable|array, middleware:array}>> */
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->routes[strtoupper($method)][] = [
            'path'       => $path,
            'pattern'    => $this->compile($path),
            'handler'    => $handler,
            'middleware' => $middlewares,
        ];
    }

    public function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $path, $handler, $middlewares);
    }

    public function patch(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('PATCH', $path, $handler, $middlewares);
    }

    /** 分发：返回 [handler, 路径参数, 路由级中间件列表]；未匹配抛 404，方法不匹配抛 405 */
    public function dispatch(Request $request): array
    {
        $path = $request->path();
        $method = $request->method();

        // 先尝试当前方法匹配（静态路由优先于参数路由，避免 /users/{id} 抢匹配 /users/check-id）
        $hit = $this->matchInMethod($method, $path);
        if ($hit !== null) {
            return $hit;
        }

        // HEAD 无专用路由时回退到 GET（HTTP 语义：HEAD 应返回与 GET 相同的头）
        if ($method === 'HEAD') {
            $hit = $this->matchInMethod('GET', $path);
            if ($hit !== null) {
                return $hit;
            }
        }

        // 路径存在于其他方法 → 405 Method Not Allowed
        $allowed = [];
        foreach ($this->routes as $m => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $path)) {
                    $allowed[] = $m;
                    break;
                }
            }
        }
        if ($allowed !== []) {
            throw (new HttpException(405, '请求方法不被允许', 40500))
                ->setAllowedMethods(array_unique($allowed));
        }

        throw new HttpException(404, '路由不存在', 40400);
    }

    /**
     * 在指定方法的路由集合中查找首个匹配项。
     * 匹配策略：静态路由（路径中不含 {param}）优先于参数路由，组内保持注册顺序。
     * 这样 /api/admin/students/check-id 不会被先注册的 /api/admin/students/{id} 抢匹配。
     *
     * @return array{0:callable|array,1:array,2:array}|null
     */
    private function matchInMethod(string $method, string $path): ?array
    {
        $routes = $this->routes[$method] ?? [];
        // 第一遍：静态路由优先
        foreach ($routes as $route) {
            if (strpos($route['path'], '{') !== false) {
                continue;
            }
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_map(
                    'urldecode',
                    array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)
                );
                return [$route['handler'], $params, $route['middleware']];
            }
        }
        // 第二遍：参数路由
        foreach ($routes as $route) {
            if (strpos($route['path'], '{') === false) {
                continue;
            }
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_map(
                    'urldecode',
                    array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)
                );
                return [$route['handler'], $params, $route['middleware']];
            }
        }
        return null;
    }

    /** 列出全部已注册路由（方法 => 路由定义），供 CLI/调试使用 */
    public function all(): array
    {
        return $this->routes;
    }

    /** 匹配路径：返回命中路由的 path 模式（如 /api/users/{id}）；未命中返回 null（供 RBAC 按模式校验） */
    public function match(string $method, string $path): ?string
    {
        $method = strtoupper($method);
        $routes = $this->routes[$method] ?? [];
        // 静态路由优先
        foreach ($routes as $route) {
            if (strpos($route['path'], '{') !== false) {
                continue;
            }
            if (preg_match($route['pattern'], $path)) {
                return $route['path'];
            }
        }
        foreach ($routes as $route) {
            if (strpos($route['path'], '{') === false) {
                continue;
            }
            if (preg_match($route['pattern'], $path)) {
                return $route['path'];
            }
        }
        // HEAD 回退到 GET
        if ($method === 'HEAD' && isset($this->routes['GET'])) {
            foreach ($this->routes['GET'] as $route) {
                if (strpos($route['path'], '{') !== false) {
                    continue;
                }
                if (preg_match($route['pattern'], $path)) {
                    return $route['path'];
                }
            }
            foreach ($this->routes['GET'] as $route) {
                if (strpos($route['path'], '{') === false) {
                    continue;
                }
                if (preg_match($route['pattern'], $path)) {
                    return $route['path'];
                }
            }
        }
        return null;
    }

    /** 将 {name} 编译为正则命名捕获组；字面部分转义，防止正则元字符误匹配 */
    private function compile(string $path): string
    {
        // 跨请求缓存编译结果（APCu）：避免每个请求对同一路径重复拼正则，提升高并发下路由开销
        if (Apcu::available()) {
            $cacheKey = 'route:' . md5($path);
            $cached = apcu_fetch($cacheKey);
            if (is_string($cached)) {
                return $cached;
            }
            $pattern = $this->doCompile($path);
            apcu_store($cacheKey, $pattern);
            return $pattern;
        }
        return $this->doCompile($path);
    }

    private function doCompile(string $path): string
    {
        $parts = preg_split('#\{(\w+)\}#', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $pattern = '';
        $isParam = false;
        foreach ($parts as $part) {
            if ($isParam) {
                // 参数名必须是合法标识符，否则生成非法命名组导致每次分发报错/404
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $part)) {
                    throw new \LogicException("非法路由参数名: {{$part}}（须以字母或下划线开头）");
                }
                $pattern .= '(?P<' . $part . '>[^/]+)';
            } else {
                $pattern .= preg_quote($part, '#');
            }
            $isParam = !$isParam;
        }
        return '#^' . $pattern . '$#';
    }
}
