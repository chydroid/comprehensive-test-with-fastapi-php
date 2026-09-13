<?php

declare(strict_types=1);

/**
 * 全局中间件注册表（按顺序执行，外层先执行）
 * 返回 null 的项会被跳过，可据此按配置动态开关。
 *
 * 顺序说明：
 *   1. SecureHeaders  安全响应头
 *   2. SessionAuth    会话鉴权（校验登录态 + RBAC 权限点，核心 P0-1 修复点）
 *   3. RateLimit      限流（按配置开关）
 */
return [
    \App\Middlewares\SecureHeadersMiddleware::class,
    \App\Middlewares\SessionAuthMiddleware::class,
    config('rate_limit.enabled') ? \App\Middlewares\RateLimitMiddleware::class : null,
];
