<?php

declare(strict_types=1);

/**
 * 管理统一入口 POST /api/manage/login 回归（ManageAuthController）。
 *
 * 背景：门户首页隐藏「教师端 / 管理后台」入口后，管理侧统一走隐藏入口
 * /manage：单一账号密码表单，服务端先按管理员、再按教师校验，自动识别
 * 身份并建立对应会话，前端按返回的 redirect 跳转工作台。
 *
 * 覆盖（走 Test\Http 伪 HTTP：路由 + 中间件 public 登记 + 控制器全链路）：
 *  1. 管理员凭据 → role=admin / redirect=/admin，ADMIN 会话已建立（/api/admin/me）
 *  2. 教师凭据   → role=teacher / redirect=/teacher，TEACHER 会话已建立（/api/teacher/me）
 *  3. 错误密码   → 401 / 40101
 *  4. 未知账号   → 401 且文案与错误密码一致（防账号枚举）
 *  5. GET /manage 页面外壳可渲染（data-page / 入口脚本）
 *
 * 账号为 __TEST__mg_ 前缀临时数据，结束（含异常退出）时清理。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;

if (ob_get_level() === 0) {
    ob_start();
}

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';

bootstrap();

$t = new Harness();
echo "== 管理统一入口测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('管理统一入口', '数据库不可用');
    exit($t->finish());
}

const T_ADMIN   = '__TEST__mg_admin';
const T_TEACHER = '__TEST__mg_teacher';
const T_PASS    = 'mgPass_123456';

/** 清理本用例临时账号（管理员/教师） */
function mgCleanup(): void
{
    \Core\Database::query('DELETE FROM `admininfo` WHERE `username` LIKE ?', ['__TEST__mg_%']);
    \Core\Database::query('DELETE FROM `teainfo` WHERE `tea_name` LIKE ?', ['__TEST__mg_%']);
}

mgCleanup();
\Core\Database::query(
    'INSERT INTO `admininfo` (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)',
    [T_ADMIN, \App\Services\Password::hash(T_PASS), 'systemAdmin', '']
);
\Core\Database::query(
    'INSERT INTO `teainfo` (tea_name, tea_pwd, avatar) VALUES (?, ?, ?)',
    [T_TEACHER, \App\Services\Password::hash(T_PASS), '']
);
register_shutdown_function('mgCleanup');

\App\Services\AuthSession::logout(\App\Services\AuthSession::ADMIN);
\App\Services\AuthSession::logout(\App\Services\AuthSession::TEACHER);

/* ---------- 1. 管理员凭据 ---------- */
$t->guard('管理员凭据 → role=admin / redirect=/admin', function () use ($t) {
    $res = Http::post('/api/manage/login', ['username' => T_ADMIN, 'password' => T_PASS]);
    $t->assertSame('  HTTP 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertSame('  role=admin', 'admin', $data['role'] ?? null);
    $t->assertSame('  redirect=/admin', '/admin', $data['redirect'] ?? null);

    $me = Http::get('/api/admin/me');
    $t->assertTrue('  ADMIN 会话已建立（/api/admin/me）', (bool) (Http::data($me)['logged_in'] ?? false));
});

/* ---------- 2. 教师凭据 ---------- */
$t->guard('教师凭据 → role=teacher / redirect=/teacher', function () use ($t) {
    Http::post('/api/admin/logout'); // 显式清管理员会话，模拟用户切换身份

    $res = Http::post('/api/manage/login', ['username' => T_TEACHER, 'password' => T_PASS]);
    $t->assertSame('  HTTP 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertSame('  role=teacher', 'teacher', $data['role'] ?? null);
    $t->assertSame('  redirect=/teacher', '/teacher', $data['redirect'] ?? null);

    $me = Http::get('/api/teacher/me');
    $t->assertTrue('  TEACHER 会话已建立（/api/teacher/me）', (bool) (Http::data($me)['logged_in'] ?? false));
});

/* ---------- 3/4. 失败分支 ---------- */
$t->guard('错误密码 → 401 / 40101', function () use ($t) {
    $res = Http::post('/api/manage/login', ['username' => T_ADMIN, 'password' => 'wrong-password-123']);
    $t->assertSame('  HTTP 401', 401, $res['status']);
    $body = $res['body'] ?? [];
    $t->assertSame('  信封 code=40101', 40101, $body['code'] ?? null);
    $t->assertSame('  文案与既有登录口一致', '账号或密码不正确', $body['message'] ?? null);
});

$t->guard('未知账号 → 401 且与错误密码同文案（防账号枚举）', function () use ($t) {
    $res = Http::post('/api/manage/login', ['username' => '__TEST__mg_nobody', 'password' => 'whatever']);
    $t->assertSame('  HTTP 401', 401, $res['status']);
    $t->assertSame('  文案与错误密码一致', '账号或密码不正确', $res['body']['message'] ?? null);
});

/* ---------- 5. 页面外壳 ---------- */
$t->guard('GET /manage 页面外壳可渲染', function () use ($t) {
    $res = Http::get('/manage');
    $t->assertSame('  HTTP 200', 200, $res['status']);
    $raw = $res['raw'];
    $t->assertTrue('  data-page=manage', str_contains($raw, 'data-page="manage"'));
    $t->assertTrue('  引用 manage.js 入口脚本', str_contains($raw, '/assets/js/apps/manage.js'));
    $t->assertTrue('  闪屏内联 LOGO', preg_match('/class="boot-logo">\s*<svg/u', $raw) === 1);
});

exit($t->finish());
