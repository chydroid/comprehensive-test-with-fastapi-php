<?php

declare(strict_types=1);

namespace Test;

/**
 * CLI 测试用的伪 HTTP 客户端：把请求注入超全局变量与 Request 静态输入，
 * 经由框架 App::run() 走完整的中间件 + 路由 + 控制器链路，
 * 再用 Response::capture() 取回状态码与响应体。
 *
 * 这样测试覆盖的是真实请求路径（含鉴权、CSRF、校验），而非直接调控制器方法。
 */
final class Http
{
    private static array $cookies = [];

    /** 发起一次请求，返回 ['status'=>int, 'body'=>array|null, 'raw'=>string, 'headers'=>array] */
    public static function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        $method = strtoupper($method);
        $parts = parse_url($path);
        $uriPath = $parts['path'] ?? $path;
        $query = $parts['query'] ?? '';

        // 重置超全局
        $_GET = [];
        if ($query !== '') {
            parse_str($query, $_GET);
        }
        $_POST = [];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['CONTENT_LENGTH'] = (string) strlen(json_encode($body));
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        foreach ($headers as $k => $v) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
            $_SERVER[$key] = (string) $v;
        }

        $raw = $body === [] ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
        \Core\Request::setInputBody($raw);

        ob_start();
        \Core\Response::capture(true);
        try {
            \Core\App::instance()->run();
        } finally {
            $cap = \Core\Response::captured();
            \Core\Response::capture(false);
            \Core\Request::clearInputBody();
            ob_end_clean();
        }

        return [
            'status' => $cap['status'],
            'raw'    => $cap['body'],
            'body'   => json_decode($cap['body'], true),
            'headers'=> $cap['headers'],
        ];
    }

    public static function get(string $path, array $headers = []): array
    {
        return self::request('GET', $path, [], $headers);
    }

    public static function post(string $path, array $body = [], array $headers = []): array
    {
        return self::request('POST', $path, $body, $headers);
    }

    public static function put(string $path, array $body = [], array $headers = []): array
    {
        return self::request('PUT', $path, $body, $headers);
    }

    public static function delete(string $path, array $headers = []): array
    {
        return self::request('DELETE', $path, [], $headers);
    }

    /** 取响应 envelope 中的 data */
    public static function data(array $res): mixed
    {
        return $res['body']['data'] ?? null;
    }

    /** 取响应 envelope 中的 message */
    public static function message(array $res): ?string
    {
        return $res['body']['message'] ?? null;
    }
}
