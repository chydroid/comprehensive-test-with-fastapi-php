<?php
declare(strict_types=1);

namespace Core;

/**
 * HTTP 请求封装
 * 统一读取方法、路径、查询参数、请求体(含 JSON)、请求头、路径参数
 */
class Request
{
    private array $query;
    private array $body;
    private array $files;
    private array $headers;
    private array $params = [];
    /** JSON 请求体解析失败时记录的错误（供 App::run 返回清晰的 400） */
    private ?HttpException $bodyError = null;
    /** 注入的请求体原始内容：Swoole 等 SAPI 无 php://input 时由适配层注入；null 表示回退 php://input */
    private static ?string $inputBody = null;

    public static function setInputBody(?string $body): void
    {
        self::$inputBody = $body;
    }

    public static function clearInputBody(): void
    {
        self::$inputBody = null;
    }

    public function __construct()
    {
        $this->query   = $_GET;
        $this->files   = $_FILES;
        $this->headers = $this->collectHeaders();
        $this->body    = $this->parseBody();
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** 当前路径（去掉 query string 与末尾斜杠，根路径为 /） */
    public function path(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    /** 读取查询参数 GET ?key=value */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    /** 读取请求体（JSON body 已自动解析为数组） */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->body : ($this->body[$key] ?? $default);
    }

    /** 合并全部输入（body + query），body 优先 */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function file(string $key): mixed
    {
        return $this->files[$key] ?? null;
    }

    /** 读取请求头（大小写不敏感） */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** 路由路径参数，如 /users/{id} 中的 id */
    public function params(): array
    {
        return $this->params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** JSON 请求体解析失败时返回对应错误，正常为 null */
    public function bodyError(): ?HttpException
    {
        return $this->bodyError;
    }

    private function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }

    private function parseBody(): array
    {
        $contentType = (string) $this->header('content-type', '');

        // multipart/form-data：PHP 已填充 $_POST/$_FILES，php://input 不可读
        if (str_contains($contentType, 'multipart/form-data')) {
            return $_POST;
        }

        $raw = self::$inputBody ?? file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $_POST;
        }

        // JSON body
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // 畸形 JSON 不应静默当作空 body，否则校验报错误导、payload 被丢弃
                $this->bodyError = new HttpException(400, '请求体不是合法的 JSON', 40000);
                return [];
            }
            return is_array($decoded) ? $decoded : [];
        }

        // 表单编码（PUT/PATCH/DELETE 时 $_POST 为空，需手动解析）
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $parsed);
            return is_array($parsed) ? $parsed : [];
        }

        // 其他 Content-Type：尝试按 JSON 解析
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $_POST;
    }
}
