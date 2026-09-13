<?php
declare(strict_types=1);

/**
 * comprehensive-test-with-fastapi-php 主配置
 * 所有可调项集中于此，支持 .env 环境变量覆盖
 */
return [
    'app' => [
        'name'          => '网上理论考核系统',
        'version'       => '2.0.0',
        'debug'         => (bool) env('APP_DEBUG', false),
        'timezone'      => 'Asia/Shanghai',
        // 请求体上限（字节），超限返回 413，防止超大请求拖垮服务
        'max_body_bytes'=> (int) env('MAX_BODY_BYTES', 1048576),
    ],

    'database' => [
        'host'       => env('DB_HOST', '127.0.0.1'),
        'port'       => (int) env('DB_PORT', 3306),
        'name'       => env('DB_NAME', 'csip_exam'),
        'user'       => env('DB_USER', 'csip_exam'),
        'pass'       => env('DB_PASS', ''),
        'charset'    => 'utf8mb4',
        'persistent' => (bool) env('DB_PERSISTENT', true),
        'read' => [
            'enabled'   => (bool) env('DB_READ_ENABLED', false),
            'host'      => env('DB_READ_HOST', ''),
            'port'      => (int) env('DB_READ_PORT', 3306),
            'name'      => env('DB_READ_NAME', ''),
            'user'      => env('DB_READ_USER', ''),
            'pass'      => env('DB_READ_PASS', ''),
            'persistent'=> (bool) env('DB_READ_PERSISTENT', true),
        ],
        'pool' => [
            'enabled' => (bool) env('DB_POOL_ENABLED', false),
            'size'    => (int) env('DB_POOL_SIZE', 8),
        ],
    ],

    'redis' => [
        'enabled' => (bool) env('REDIS_ENABLED', false),
        'host'    => env('REDIS_HOST', '127.0.0.1'),
        'port'    => (int) env('REDIS_PORT', 6379),
        'auth'    => env('REDIS_PASSWORD', ''),
        'db'      => (int) env('REDIS_DB', 0),
        'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
    ],

    'memcached' => [
        'enabled' => (bool) env('MEMCACHED_ENABLED', false),
        'host'    => env('MEMCACHED_HOST', '127.0.0.1'),
        'port'    => (int) env('MEMCACHED_PORT', 11211),
        'servers' => [],
    ],

    'apcu' => [
        'enabled' => (bool) env('APCU_ENABLED', false),
    ],

    'array' => [
        'enabled' => (bool) env('ARRAY_CACHE_ENABLED', false),
    ],

    'cors' => [
        'allowed_origins' => ['*'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Request-ID'],
        'max_age'         => 86400,
    ],

    /*
     * 鉴权：本站为「同源 Web 应用」，不使用 Bearer Token，
     * 而是基于服务端 Session（会话 Cookie）的三套独立登录态：
     *   - admin   → admininfo 表（4 种 admin_power 角色）
     *   - student → stuinfo 表
     *   - teacher → teainfo 表
     * 因此框架自带的 Bearer 鉴权关闭，改由 App\Middlewares\SessionAuthMiddleware 承担。
     */
    'auth' => [
        'enabled' => false,
        'tokens'  => [],
        'jwt_secret' => env('JWT_SECRET', ''),
        'jwt_ttl'    => 3600,
        'refresh_ttl'=> 604800,
        'issuer'     => 'csip',
        'client_id'  => '',
        'client_secret' => '',
        'except'     => [],
        'roles'      => [],
        'rbac'       => [],
    ],

    // 会话配置（服务端 Session）
    'session' => [
        'name'          => 'CSIPSESSION',
        'lifetime'      => 7200,      // 秒
        'cookie_path'   => '/',
        'cookie_httponly'=> true,
        'cookie_samesite'=> 'Lax',
        // 生产启用 HTTPS 后置为 true
        'cookie_secure'  => (bool) env('SESSION_SECURE', false),
    ],

    /*
     * 后台角色 → 权限点映射（P0-1 修复核心）
     * 权限点命名：<模块>.<动作>，例如 quiz.add / exam.edit
     *  '*' 表示全部权限。
     * 四种内置角色：
     *   systemAdmin  → 全部权限
     *   testAdmin    → 考试/考生/成绩/监控相关
     *   quizOperator → 题库查看与清理
     *   quizAdder    → 题库录入
     */
    'rbac' => [
        'systemAdmin' => ['*'],
        'testAdmin' => [
            'dashboard.view',
            'subject.view',
            'exam.view', 'exam.add', 'exam.edit', 'exam.delete', 'exam.start', 'exam.generate',
            'category.view', 'category.add', 'category.edit', 'category.delete',
            'student.view', 'student.add', 'student.edit', 'student.delete', 'student.import',
            'grade.view', 'grade.edit',
            'class.view', 'class.edit',
            'monitor.view', 'monitor.control',
            'score.view', 'score.backup', 'score.export',
            'quiz.view',
            'teacher.view',
            'news.view',
        ],
        'quizOperator' => [
            'dashboard.view',
            'quiz.view', 'quiz.clean',
            'subject.view',
            'category.view',
        ],
        'quizAdder' => [
            'dashboard.view',
            'quiz.view', 'quiz.add', 'quiz.edit', 'quiz.delete',
            'subject.view',
        ],
    ],

    'rate_limit' => [
        'enabled'        => (bool) env('RATE_LIMIT_ENABLED', false),
        'max_requests'   => (int) env('RATE_LIMIT_MAX', 60),
        'window_seconds' => 60,
        'driver'         => env('RATE_LIMIT_DRIVER', 'file'),
    ],

    'security_headers' => [
        'headers' => [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
            'X-XSS-Protection'       => '1; mode=block',
        ],
    ],

    'log' => [
        'path'    => BASE_PATH . '/storage/logs',
        'level'   => env('LOG_LEVEL', 'info'),
        'slow_ms' => (float) env('LOG_SLOW_MS', 1000),
    ],

    // 上传配置
    'upload' => [
        'path'       => BASE_PATH . '/public/uploads',
        'url'        => '/uploads',
        'max_bytes'  => (int) env('UPLOAD_MAX_BYTES', 2097152), // 2MB
        // 允许的图片 MIME → 扩展名
        'allowed_mime' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ],
    ],
];
