<?php
declare(strict_types=1);

/**
 * fastapi-php 引导文件
 * 提供：路径常量、PSR-4 自动加载、环境变量/配置/日志等全局辅助函数
 *
 * 幂等保护：重复 require（例如测试运行器 include 多个套件、或多入口共存）时
 * 直接返回，避免 "Cannot redeclare load_env()" 之类的致命错误。
 */
if (defined('FASTAPI_PHP_BOOTSTRAPPED')) {
    return;
}
define('FASTAPI_PHP_BOOTSTRAPPED', true);

define('BASE_PATH', dirname(__DIR__));

/* ---------------------------------------------------------------------------
 * PSR-4 自动加载（零依赖，映射 Core\ -> core/，App\ -> app/）
 * ------------------------------------------------------------------------- */
spl_autoload_register(static function (string $class): void {
    $map = [
        'Core\\' => BASE_PATH . '/core/',
        'App\\'  => BASE_PATH . '/app/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

/* ---------------------------------------------------------------------------
 * 环境变量
 * ------------------------------------------------------------------------- */
$GLOBALS['__env'] = [];

/** 解析 .env 文件（KEY=VALUE，支持 # 注释），不存在则静默跳过 */
function load_env(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $GLOBALS['__env'][trim($key)] = trim($value);
    }
}

/** 读取环境变量（优先级：系统环境 > $_ENV > .env 文件），支持布尔/数字字面量转换 */
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? $GLOBALS['__env'][$key] ?? null;
    }
    if ($value === null) {
        return $default;
    }
    return match (strtolower((string) $value)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        // 无前导零的整数字符串转 int；前导零（如 "007"）与浮点保持字符串，避免值被破坏
        default => preg_match('/^-?(?:0|[1-9]\d*)$/', (string) $value) ? (int) $value : $value,
    };
}

/* ---------------------------------------------------------------------------
 * 配置
 * ------------------------------------------------------------------------- */
$GLOBALS['__config'] = [];

/** 读取配置：config('database.host') 支持点号层级；config() 返回全部 */
function config(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['__config'];
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

/* ---------------------------------------------------------------------------
 * 日志（追加写入 storage/logs/app.log）
 * 按 config('log.level') 过滤（debug<info<warning<error<critical），并自动附带请求 ID
 * ------------------------------------------------------------------------- */
function log_message(string $message, string $level = 'info'): void
{
    $levels = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5];
    $threshold = $levels[strtolower((string) config('log.level', 'debug'))] ?? 0;
    if (($levels[strtolower($level)] ?? 0) < $threshold) {
        return;
    }
    $dir = (string) config('log.path', BASE_PATH . '/storage/logs');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }
    $rid = $GLOBALS['__request_id'] ?? null;
    $line = sprintf(
        "[%s] [%s] %s%s%s",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $rid !== null ? " rid={$rid}" : '',
        PHP_EOL
    );
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

/* ---------------------------------------------------------------------------
 * 统一引导：加载 .env → 构建配置 → 注册中间件（config/middleware.php）
 * 供 public/index.php 与测试共用，避免注册逻辑重复
 * ------------------------------------------------------------------------- */
function bootstrap(array $overrides = []): \Core\App
{
    load_env(BASE_PATH . '/.env');
    $config = require BASE_PATH . '/config/config.php';
    foreach ($overrides as $k => $v) {
        if (is_array($v) && isset($config[$k]) && is_array($config[$k])) {
            $config[$k] = array_merge($config[$k], $v);
        } else {
            $config[$k] = $v;
        }
    }
    $GLOBALS['__config'] = $config;

    $app = new \Core\App($config);
    $middlewares = require BASE_PATH . '/config/middleware.php';
    foreach ($middlewares as $mw) {
        if ($mw === null) {
            continue;
        }
        $app->middleware(is_string($mw) ? new $mw() : $mw);
    }
    return $app;
}

/* ---------------------------------------------------------------------------
 * 应用实例 / 数据库快捷访问
 * ------------------------------------------------------------------------- */
function app(): \Core\App
{
    return \Core\App::instance();
}

function db(): \PDO
{
    return \Core\Database::connection();
}

/* ---------------------------------------------------------------------------
 * 会话（服务端 Session）——三套登录态共用，靠 key 前缀区分
 *   session_start_app()  → 按 config('session') 安全启动会话
 *   sess_get / sess_set / sess_forget / sess_regenerate / sess_destroy
 * 注意：必须在任何输出之前调用 start_session()。
 * ------------------------------------------------------------------------- */

/** 幂等启动会话；CLI（测试）下不启动，返回 false，读写走内存数组以免报错 */
function start_session(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    if (PHP_SAPI === 'cli') {
        // CLI 测试环境：用内存数组模拟会话，避免 headers already sent / 无 cookie
        $GLOBALS['__cli_session'] ??= [];
        return false;
    }
    $cfg = config('session');
    $lifetime = (int) ($cfg['lifetime'] ?? 7200);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => (string) ($cfg['cookie_path'] ?? '/'),
        'httponly' => (bool) ($cfg['cookie_httponly'] ?? true),
        'samesite' => (string) ($cfg['cookie_samesite'] ?? 'Lax'),
        'secure'   => (bool) ($cfg['cookie_secure'] ?? false),
    ]);
    session_name((string) ($cfg['name'] ?? 'CSIPSESSION'));
    session_start();
    return true;
}

/** 读取会话值（CLI 下读内存模拟数组） */
function sess_get(string $key, mixed $default = null): mixed
{
    if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
        return $GLOBALS['__cli_session'][$key] ?? $default;
    }
    return $_SESSION[$key] ?? $default;
}

function sess_set(string $key, mixed $value): void
{
    if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
        $GLOBALS['__cli_session'][$key] = $value;
        return;
    }
    $_SESSION[$key] = $value;
}

function sess_forget(string $key): void
{
    if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
        unset($GLOBALS['__cli_session'][$key]);
        return;
    }
    unset($_SESSION[$key]);
}

/** 会话固定攻击防护：登录成功后调用 */
function sess_regenerate(): void
{
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function sess_destroy(): void
{
    if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
        $GLOBALS['__cli_session'] = [];
        return;
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
