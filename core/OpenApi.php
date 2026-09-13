<?php

declare(strict_types=1);

namespace Core;

/**
 * 从路由表自动生成 OpenAPI 3.0.3 规范
 * 用于暴露 /openapi.json，配合 /docs（Swagger UI）在线调试
 *
 * 无需逐条手写注解：由 Router 的 method/path/handler 推导出
 * 路径参数、operationId、tags、通用请求/响应体。
 */
final class OpenApi
{
    /** @var array<string, array> 控制器 schemas() 结果缓存 */
    private static array $schemaCache = [];

    public static function spec(Router $router, array $appConfig): array
    {
        $info = [
            'title'       => (string) ($appConfig['app']['name'] ?? 'FastAPI PHP'),
            'version'     => (string) ($appConfig['app']['version'] ?? '1.0.0'),
            'description' => '自动生成的 OpenAPI 3.0 规范（由路由表推导）',
        ];

        $paths = [];
        foreach ($router->all() as $method => $routes) {
            foreach ($routes as $route) {
                $paths[$route['path']][strtolower($method)] = self::operation($route, $method);
            }
        }

        return [
            'openapi'    => '3.0.3',
            'info'       => $info,
            'paths'      => $paths,
            'components' => self::components(),
        ];
    }

    private static function operation(array $route, string $method): array
    {
        $path = $route['path'];
        $opId = self::operationId($method, $path, $route['handler']);
        $meta = self::controllerSchemas($route['handler'])[$opId] ?? [];

        $op = [
            'operationId' => $opId,
            'summary'     => (string) ($meta['summary'] ?? "{$method} {$path}"),
            'tags'        => self::tags($route['handler']),
            'parameters'  => array_map(static fn (string $n): array => [
                'name'     => $n,
                'in'       => 'path',
                'required' => true,
                'schema'   => ['type' => 'string'],
            ], self::pathParams($path)),
            'responses'   => self::responses($method, $meta['response'] ?? null),
        ];

        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            $op['requestBody'] = [
                'required' => false,
                'content'  => [
                    'application/json' => [
                        'schema' => self::requestSchema($meta),
                    ],
                ],
            ];
        }
        return $op;
    }

    /** 取控制器的声明式 schemas()（按类缓存）；未定义则返回空 */
    private static function controllerSchemas(callable|array $handler): array
    {
        if (!(is_array($handler) && isset($handler[0]) && is_string($handler[0]))) {
            return [];
        }
        $class = $handler[0];
        if (!method_exists($class, 'schemas')) {
            return [];
        }
        if (!isset(self::$schemaCache[$class])) {
            self::$schemaCache[$class] = (array) $class::schemas();
        }
        return self::$schemaCache[$class];
    }

    private static function requestSchema(array $meta): array
    {
        if (!isset($meta['request'])) {
            return ['type' => 'object'];
        }
        return [
            'type'       => 'object',
            'required'   => $meta['request']['required'] ?? [],
            'properties' => $meta['request']['properties'] ?? [],
        ];
    }

    private static function operationId(string $method, string $path, callable|array $handler): string
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
            $cls = (new \ReflectionClass($handler[0]))->getShortName();
            return strtolower($cls) . '.' . $handler[1];
        }
        // 闭包：用 method+path 保证 operationId 唯一
        return strtolower($method) . '_' . rtrim(preg_replace('#[^a-zA-Z0-9]#', '_', $path), '_');
    }

    /** @return list<string> */
    private static function tags(callable|array $handler): array
    {
        if (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
            return [(new \ReflectionClass($handler[0]))->getShortName()];
        }
        return [];
    }

    /** @return list<string> */
    private static function pathParams(string $path): array
    {
        preg_match_all('#\{(\w+)\}#', $path, $m);
        return $m[1] ?? [];
    }

    private static function responses(string $method, ?array $responseSchema = null): array
    {
        $ok = strtoupper($method) === 'POST' ? '201' : '200';
        $codes = [$ok, '400', '401', '404', '405', '409', '422', '500'];
        $map = [];
        foreach ($codes as $c) {
            $map[$c] = $c === $ok
                ? ($responseSchema !== null
                    ? ['description' => '成功', 'content' => ['application/json' => ['schema' => $responseSchema]]]
                    : ['description' => '成功'])
                : ['$ref' => '#/components/responses/Error'];
        }
        return $map;
    }

    private static function components(): array
    {
        return [
            'responses' => [
                'Error' => [
                    'description' => '统一错误响应',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'code'       => ['type' => 'integer'],
                                    'message'    => ['type' => 'string'],
                                    'data'       => ['type' => 'null'],
                                    'request_id' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
