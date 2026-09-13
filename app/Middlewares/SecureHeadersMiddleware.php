<?php

declare(strict_types=1);

namespace App\Middlewares;

use Core\Middleware;
use Core\Request;
use Core\Response;

/**
 * 安全响应头中间件：为所有响应附加安全相关 HTTP 头。
 * 具体取值由 config('security_headers.headers') 控制，值为空/未配置的头不输出。
 *
 * 同源 Web 应用额外附加 CSP：本站前端不引外部 CDN（Swagger UI 除外，
 * 其路径 /api/docs 在下方白名单中放宽）。
 */
class SecureHeadersMiddleware implements Middleware
{
    /** Swagger 文档页需要加载 unpkg CDN，单独放宽 CSP */
    private const DOC_PATHS = ['/api/docs'];

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        foreach ((array) config('security_headers.headers', []) as $name => $value) {
            if ($value !== null && $value !== '') {
                $response->header((string) $name, (string) $value);
            }
        }

        // CSP：默认仅允许同源资源；文档页额外允许 unpkg（脚本/样式）
        $isDoc = in_array($request->path(), self::DOC_PATHS, true);
        $response->header('Content-Security-Policy', $isDoc ? $this->docCsp() : $this->appCsp());

        return $response;
    }

    private function appCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }

    private function docCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://unpkg.com",
            "img-src 'self' data: https://unpkg.com",
            "font-src 'self' data: https://unpkg.com",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
        ]);
    }
}
