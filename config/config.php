<?php
declare(strict_types=1);

/**
 * comprehensive-test-with-fastapi-php 主配置
 * 所有可调项集中于此，支持 .env 环境变量覆盖
 */
return [
    'app' => [
        // 产品名（唯一来源）：门户标题、各端 document.title 后缀、登录页页脚、
        // API 文档标题都取自这里（服务端）或 PageController 注入的 <html data-app-name>（前端）。
        // 改名只需改这一行，不要在视图/脚本里再写死产品名。
        'name'          => '深蓝网上考试系统',
        'version'       => '2.0.0',
        'debug'         => (bool) env('APP_DEBUG', false),
        'timezone'      => 'Asia/Shanghai',
        // 请求体上限（字节），超限返回 413，防止超大请求拖垮服务。
        // 必须大于 upload.max_bytes：图片经 base64 传输会膨胀约 1.34 倍，
        // 2MB 的上传实际请求体接近 2.8MB。此前默认 1MB，导致 UploadController
        // 里 2MB 的大小校验永远执行不到，图片上传恒被 413 拒绝。
        'max_body_bytes'=> (int) env('MAX_BODY_BYTES', 6291456), // 6MB
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
            // 年级/班级/教师此前只有 view/edit，缺少 add（delete 同样缺失），
            // 导致该角色无法新建年级、班级与教师，与「考试/考生/成绩相关」的定位不符。
            'grade.view', 'grade.add', 'grade.edit', 'grade.delete',
            'class.view', 'class.add', 'class.edit', 'class.delete',
            'monitor.view', 'monitor.control',
            'score.view', 'score.backup', 'score.export',
            'quiz.view',
            // 批量导入题库：与 student.import 同构，独立于单题增删（testAdmin 本就不管录题）
            'quiz.import',
            // 与 student.* 对齐：既能查看也需要维护授课教师
            'teacher.view', 'teacher.add', 'teacher.edit', 'teacher.delete',
            'news.view',
            // C3 学习资料库：testAdmin 负责教学资料的维护（与其「考试/教学相关」定位一致）
            'material.view', 'material.add', 'material.delete',
            // 兜底权限点：所有管理端接口都要求的最小权限（见 SessionAuthMiddleware）
            'admin.access',
        ],
        'quizOperator' => [
            'dashboard.view',
            'quiz.view', 'quiz.clean',
            'subject.view',
            'category.view',
            'admin.access',
        ],
        'quizAdder' => [
            'dashboard.view',
            'quiz.view', 'quiz.add', 'quiz.edit', 'quiz.delete',
            // 录题员的核心工作方式就是从表格灌题，导入权限必须给
            'quiz.import',
            'subject.view',
            'admin.access',
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
            'image/bmp'  => 'bmp',
        ],
        // C3 学习资料库：课件文档单独一套上限与白名单。
        // 文档不是图片，不能走 getimagesize 校验；但若不加限制，任何人都能往
        // public/uploads 里扔 .php 并直接执行 —— 因此这里只放行「浏览器不会
        // 当作脚本执行」的文档/压缩包类型，且保存时用随机文件名 + 固定扩展名。
        'doc_max_bytes' => (int) env('UPLOAD_DOC_MAX_BYTES', 20971520), // 20MB
        'allowed_doc_ext' => [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'txt', 'md', 'csv', 'zip', 'rar', '7z', 'mp4', 'mp3',
        ],
    ],
];
