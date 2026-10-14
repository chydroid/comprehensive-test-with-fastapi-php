<?php
declare(strict_types=1);

/**
 * 上线前环境自检（CLI）
 *
 * 用站点实际使用的 PHP 二进制执行（宝塔的 `php` 命令未必等于站点的 PHP 版本）：
 *   /www/server/php/83/bin/php deploy/precheck.php
 * 或先确认版本：
 *   php -v    # 必须 >= 8.1，否则 core/Controller.php 的 readonly 会 Parse error
 *
 * 退出码：有 FAIL 时为 1，只有 WARN 时为 0。
 */

require dirname(__DIR__) . '/core/helpers.php';

$fails = 0;
$warns = 0;

/** 输出一条检查结果 */
function chk(string $label, bool $ok, string $detail = '', string $level = 'FAIL'): void
{
    global $fails, $warns;
    if ($ok) {
        printf("  [PASS] %-42s %s\n", $label, $detail);
        return;
    }
    if ($level === 'WARN') {
        $warns++;
        printf("  [WARN] %-42s %s\n", $label, $detail);
    } else {
        $fails++;
        printf("  [FAIL] %-42s %s\n", $label, $detail);
    }
}

/** 把 25M / 512K / 1G 之类的 ini 值转成字节 */
function toBytes(string $v): int
{
    $v = strtolower(trim($v));
    if ($v === '' || $v === '-1') {
        return -1; // -1 = 无限制
    }
    $unit = substr($v, -1);
    $num  = (float) $v;
    return match ($unit) {
        'g' => (int) ($num * 1073741824),
        'm' => (int) ($num * 1048576),
        'k' => (int) ($num * 1024),
        default => (int) $num,
    };
}

echo "=== 部署环境自检 " . date('Y-m-d H:i:s') . " ===\n";
echo "PHP 二进制: " . PHP_BINARY . "\n";
echo "项目根:     " . BASE_PATH . "\n\n";

/* ---------------- 1. PHP 版本与扩展 ---------------- */
echo "[1] PHP 运行时\n";
chk('PHP 版本 >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION);
chk('扩展 pdo_mysql', extension_loaded('pdo_mysql'), '', 'FAIL');
chk('扩展 mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? '' : '建议安装', 'WARN');
chk('扩展 json', extension_loaded('json'), '', 'FAIL');
chk(
    'open_basedir 不阻断上级目录',
    (static function (): bool {
        $ob = (string) ini_get('open_basedir');
        if ($ob === '') {
            return true; // 未开启限制
        }
        foreach (explode(PATH_SEPARATOR, $ob) as $p) {
            if ($p !== '' && str_starts_with(BASE_PATH . DIRECTORY_SEPARATOR, rtrim($p, '/\\') . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }
        return false;
    })(),
    '宝塔防跨站开着会挡住 require ../core → 全站 500',
    'WARN'
);

/* ---------------- 2. 可写目录 ---------------- */
echo "\n[2] 运行时目录（clone 后需手工创建）\n";
foreach (['storage/cache', 'storage/logs', 'public/uploads'] as $d) {
    $abs = BASE_PATH . '/' . $d;
    if (!is_dir($abs)) {
        chk($d, false, '目录不存在 → bash deploy/init_dirs.sh');
        continue;
    }
    chk($d . ' 可写', is_writable($abs), is_writable($abs) ? substr(sprintf('%o', fileperms($abs)), -4) : '属主应为 www');
}
$sessDir = (string) (ini_get('session.save_path') ?: sys_get_temp_dir());
chk('session 目录可写', is_writable($sessDir), $sessDir, 'WARN');

/* ---------------- 3. 配置与入口 ---------------- */
echo "\n[3] 配置与入口\n";
$envFile = BASE_PATH . '/.env';
chk('.env 已创建', is_file($envFile), is_file($envFile) ? '' : 'cp .env.example .env 并填写数据库凭据');

try {
    bootstrap();
} catch (Throwable $e) {
    chk('bootstrap()', false, $e->getMessage());
    echo "\n引导失败，后续检查跳过。\n";
    exit(1);
}

chk('.env / 配置加载', true, 'app.name=' . (string) config('app.name'));
chk('生产环境 APP_DEBUG=false', config('app.debug') !== true, config('app.debug') ? '当前 true，异常会带堆栈外泄' : '', 'WARN');

// 宝塔建站生成的默认页会抢在 index.php 前面
$stray = BASE_PATH . '/public/index.html';
chk('无 nginx 默认页残留', !is_file($stray), is_file($stray) ? 'public/index.html 存在 → rm -f 掉' : '', 'WARN');
chk('public/index.php 存在', is_file(BASE_PATH . '/public/index.php'));

// 请求体上限：图片走 base64，2MB 的图实际约 2.8MB
$maxBody = (int) config('app.max_body_bytes');
$postMax = toBytes((string) ini_get('post_max_size'));
chk(
    'post_max_size >= MAX_BODY_BYTES',
    $postMax === -1 || $postMax >= $maxBody,
    sprintf('post_max_size=%s, MAX_BODY_BYTES=%s', ini_get('post_max_size'), number_format($maxBody)),
    'WARN'
);
chk(
    'MAX_BODY_BYTES >= 6MB',
    $maxBody >= 6291456,
    $maxBody >= 6291456 ? '' : '当前 ' . number_format($maxBody) . ' → 2MB 图片(base64 后约 2.8MB)上传恒被 413',
    'WARN'
);

/* ---------------- 4. 数据库 ---------------- */
echo "\n[4] 数据库\n";
$db = config('database');
printf("  目标: %s@%s:%d/%s\n", (string) $db['user'], (string) $db['host'], (int) $db['port'], (string) $db['name']);

/**
 * 关键：这里必须走 Core\Database（应用真实使用的连接），而不是自己 new PDO。
 * 因为会话时区是在 Core\Database::newPdo() 里强制 SET time_zone='+08:00' 的，
 * 裸连会拿到 MySQL 服务器默认时区（通常是 SYSTEM/UTC），从而误报。
 */
try {
    chk('数据库连接', true, (string) \Core\Database::query('SELECT DATABASE()')->fetchColumn());

    $tz = (string) \Core\Database::query('SELECT @@session.time_zone')->fetchColumn();
    chk('会话时区 +08:00', $tz === '+08:00', $tz);

    $now = (string) \Core\Database::query('SELECT NOW()')->fetchColumn();
    printf("        数据库 NOW() = %s（应与北京时间基本一致）\n", $now);

    $tables = (int) \Core\Database::query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?',
        [(string) $db['name']]
    )->fetchColumn();
    chk('表数量 = 27', $tables === 27, (string) $tables . ' 张', 'WARN');

    // 关键业务表抽查
    $need = ['admininfo', 'teainfo', 'stuinfo', 'quizlib', 'examinfo', 'stuscore', 'siteconfig'];
    $missing = [];
    foreach ($need as $t) {
        $c = (int) \Core\Database::query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [(string) $db['name'], $t]
        )->fetchColumn();
        if ($c === 0) {
            $missing[] = $t;
        }
    }
    chk('核心业务表齐全', $missing === [], $missing === [] ? '' : '缺失: ' . implode(',', $missing));

    $admins = (int) \Core\Database::query('SELECT COUNT(*) FROM admininfo')->fetchColumn();
    chk('存在管理员账号', $admins > 0, $admins . ' 个', 'WARN');

    $quiz = (int) \Core\Database::query('SELECT COUNT(*) FROM quizlib')->fetchColumn();
    printf("        题库题目数: %s\n", number_format($quiz));
} catch (Throwable $e) {
    chk('数据库连接', false, $e->getMessage());
    echo "        → 检查 .env 的 DB_* 与 MySQL 账号授权\n";
}

/* ---------------- 5. 结论 ---------------- */
echo "\n=== 结论 ===\n";
printf("  FAIL: %d   WARN: %d\n", $fails, $warns);
if ($fails > 0) {
    echo "  ❌ 存在必须修复的问题，修完再重跑本脚本。\n";
    exit(1);
}
echo "  ✅ 无阻断项。接着验证：curl -i https://<域名>/api/health  期望 db:true\n";
exit(0);
