<?php
declare(strict_types=1);

namespace Core;

/**
 * HTTP 响应封装
 * 统一 JSON 结构 {code, message, data}；send() 时输出
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private mixed $body = null;
    private bool $raw = false;
    private bool $rawHtml = false;
    private ?string $rawContentType = null;
    private ?string $downloadPath = null;
    private bool $noBody = false;

    /** 捕获模式：不真正输出，改为收集 status/headers/body（供 Swoole 等常驻适配层取走） */
    private static bool $capturing = false;
    private static int $capturedStatus = 200;
    private static array $capturedHeaders = [];
    private static string $capturedBody = '';

    public static function capture(bool $enabled): void
    {
        self::$capturing = $enabled;
        if ($enabled) {
            self::$capturedStatus = 200;
            self::$capturedHeaders = [];
            self::$capturedBody = '';
        }
    }

    /** 取出捕获的响应：[status, headers, body] */
    public static function captured(): array
    {
        return ['status' => self::$capturedStatus, 'headers' => self::$capturedHeaders, 'body' => self::$capturedBody];
    }

    public function status(int $code): static
    {
        $this->statusCode = $code;
        return $this;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): mixed
    {
        return $this->body;
    }

    /** 返回 envelope 中的 data 字段（非 envelope 时返回 body 本身） */
    public function data(): mixed
    {
        return is_array($this->body) ? ($this->body['data'] ?? null) : $this->body;
    }

    /** 返回 envelope 中的 message 字段 */
    public function message(): ?string
    {
        return is_array($this->body) ? ($this->body['message'] ?? null) : null;
    }

    public function header(string $name, string $value): static
    {
        // 防御 HTTP 响应头注入：CR/LF 会触发响应拆分，name/value 一律拒绝
        if (str_contains($name, "\r") || str_contains($name, "\n")
            || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new \InvalidArgumentException("响应头不允许包含 CR/LF: {$name}");
        }
        $this->headers[$name] = $value;
        return $this;
    }

    /** 输出任意 JSON 结构 */
    public function json(mixed $data, int $status = 200): static
    {
        $this->body = $data;
        $this->statusCode = $status;
        return $this;
    }

    /** 成功响应：{code:0, message, data} */
    public function success(mixed $data = null, string $message = 'ok'): static
    {
        return $this->json([
            'code'    => 0,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /** 失败响应：{code, message, data} */
    public function error(int $code, string $message, int $status = 400, mixed $data = null): static
    {
        return $this->json([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /** 无内容响应（如 CORS 预检 204） */
    public function noContent(int $status = 204): static
    {
        $this->body = null;
        $this->statusCode = $status;
        return $this;
    }

    /** 输出原始 HTML（如文档页），不走 JSON 编码 */
    public function html(string $html, int $status = 200): static
    {
        $this->body = $html;
        $this->statusCode = $status;
        $this->rawHtml = true;
        $this->rawContentType = null; // 默认 text/html
        return $this;
    }

    /** 输出纯文本（如 Prometheus /metrics），不走 JSON 编码 */
    public function text(string $body, int $status = 200, string $contentType = 'text/plain; version=0.0.4; charset=utf-8'): static
    {
        $this->body = $body;
        $this->statusCode = $status;
        $this->rawHtml = true;
        $this->rawContentType = $contentType;
        return $this;
    }

    /** HEAD 请求：只输出响应头，不输出 body（HTTP 规范） */
    public function skipBody(): static
    {
        $this->noBody = true;
        return $this;
    }

    /** 文件下载响应（流式输出，不走 JSON 编码） */
    public function download(string $filePath, ?string $name = null, string $contentType = 'application/octet-stream'): static
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("文件不存在或不可读: {$filePath}");
        }
        $this->downloadPath = $filePath;
        $this->raw = true;
        $this->statusCode = 200;
        $this->headers = array_merge($this->headers, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($name ?? basename($filePath)) . '"',
            'Content-Length'      => (string) filesize($filePath),
        ]);
        return $this;
    }

    /**
     * 直接把字符串作为附件下载（用于 CSV 导出等动态内容）。
     * 与 download() 的区别：不落盘、可被 CLI 测试捕获。
     */
    public function downloadText(string $content, string $name, string $contentType = 'text/csv; charset=utf-8'): static
    {
        $this->body = $content;
        $this->rawHtml = true;
        $this->rawContentType = $contentType;
        $this->statusCode = 200;
        $this->headers = array_merge($this->headers, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($name) . '"',
            'Content-Length'      => (string) strlen($content),
        ]);
        return $this;
    }

    public function send(): void
    {
        self::emitStatus($this->statusCode);
        foreach ($this->headers as $name => $value) {
            self::emitHeader($name, $value);
        }

        // 下载：流式输出文件（HEAD 时不输出 body，Content-Length 已由 download() 设置）
        if ($this->raw && $this->downloadPath !== null) {
            if (!$this->noBody) {
                self::emitBody((string) file_get_contents($this->downloadPath));
            }
            return;
        }

        // 空响应（如 204）：不设 Content-Type
        if ($this->body === null) {
            return;
        }

        // 原始 HTML/文本（如文档页、/metrics）：直接输出，不 JSON 编码
        if ($this->rawHtml) {
            self::emitHeader('Content-Type', $this->rawContentType ?? 'text/html; charset=utf-8');
            if (!$this->noBody) {
                self::emitBody((string) $this->body);
            }
            return;
        }

        $json = json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            // 编码失败（如数据含无效 UTF-8）→ 输出统一错误，避免无效 JSON
            self::emitStatus(500);
            $this->statusCode = 500; // 同步，保证日志/调用方读取的状态码一致
            $json = json_encode(
                ['code' => 50000, 'message' => '响应编码失败', 'data' => null],
                JSON_UNESCAPED_UNICODE
            );
        }
        self::emitHeader('Content-Type', 'application/json; charset=utf-8');
        if ($this->noBody) {
            // HEAD：与 GET 相同的头部（含 Content-Length），但不输出 body（RFC 7231）
            self::emitHeader('Content-Length', (string) strlen($json));
            return;
        }
        self::emitBody($json);
    }

    private static function emitStatus(int $code): void
    {
        if (self::$capturing) {
            self::$capturedStatus = $code;
            return;
        }
        // CLI / 已输出场景下 http_response_code() 会抛 ErrorException，把真正的
        // 业务异常埋成一条无从定位的 "headers already sent"。状态码是尽力而为的
        // 事，丢掉它远好过丢掉异常本身。
        if (headers_sent()) {
            return;
        }
        http_response_code($code);
    }

    private static function emitHeader(string $name, string $value): void
    {
        if (self::$capturing) {
            self::$capturedHeaders[$name] = $value;
            return;
        }
        if (headers_sent()) {
            return;
        }
        header("$name: $value");
    }

    private static function emitBody(string $s): void
    {
        if (self::$capturing) {
            self::$capturedBody .= $s;
        } else {
            echo $s;
        }
    }
}
