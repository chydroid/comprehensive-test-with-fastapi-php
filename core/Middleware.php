<?php
declare(strict_types=1);

namespace Core;

/**
 * 中间件接口
 * handle() 中可检查/修改请求，调用 $next($request) 继续链，或直接返回响应中断
 */
interface Middleware
{
    public function handle(Request $request, callable $next): Response;
}
