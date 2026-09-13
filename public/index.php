<?php
declare(strict_types=1);

/**
 * 单一入口（前端控制器）
 * Web 服务器需将不存在的静态资源请求重写到本文件。
 */

require dirname(__DIR__) . '/core/helpers.php';

// 会话：必须在任何输出之前启动（三套登录态共用同一 Session，靠 key 前缀区分）
start_session();

// 统一引导：加载 .env → 配置 → 按 config/middleware.php 注册全局中间件
bootstrap()->run();
