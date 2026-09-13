<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\App;
use Core\Controller;
use Core\OpenApi;
use Core\Response;

/**
 * API 文档：暴露自动生成的 OpenAPI 规范与 Swagger UI 页面
 */
class DocController extends Controller
{
    /** GET /api/openapi.json —— 返回原始 OpenAPI 3.0 规范 JSON（非 envelope 包装） */
    public function openapi(): Response
    {
        $app = App::instance();
        return $this->response->json(OpenApi::spec($app->router(), $app->config()));
    }

    /** GET /api/docs —— Swagger UI 在线调试页面 */
    public function swagger(): Response
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>API 文档 · 网上理论考核系统</title>
            <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist/swagger-ui.css">
            <style>html,body{height:100%;margin:0}#swagger-ui{height:100%}</style>
        </head>
        <body>
            <div id="swagger-ui"></div>
            <script src="https://unpkg.com/swagger-ui-dist/swagger-ui-bundle.js"></script>
            <script>
              SwaggerUIBundle({
                url: "/api/openapi.json",
                dom_id: "#swagger-ui",
                deepLinking: true,
                persistAuthorization: true,
                withCredentials: true
              });
            </script>
        </body>
        </html>
        HTML;
        return $this->response->html($html);
    }
}
