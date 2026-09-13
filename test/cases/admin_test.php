<?php

declare(strict_types=1);

/**
 * 管理后台端到端测试
 *
 * 覆盖：
 * - P0-1 越权防护：未登录 / 低权角色访问管理接口一律被拒
 * - P0-2 CSRF：写操作缺 token 被拒，带 token 放行
 * - 各资源模块的 CRUD 主路径（科目 / 类别 / 单位 / 班级 / 教师 / 管理员 / 公告 / 配置）
 * - 题库写入时答案自动归一化（多选排序、大写）
 * - 系统初始化需确认口令
 *
 * 所有测试数据带 __TEST__ 前缀并在结束时清理。
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
echo "== 管理后台测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('管理后台', '数据库不可用');
    exit($t->finish());
}

const T_PREFIX = '__TEST__';
const ADMIN_USER = '__TEST__sysadmin';
const ADMIN_PASS = 'testAdminPwd123';
const LOW_USER = '__TEST__quizadder';
const LOW_PASS = 'testAdderPwd123';

/** 清理历史遗留测试数据 */
function adminCleanup(): void
{
    \Core\Database::query('DELETE FROM `admininfo` WHERE username LIKE ?', [T_PREFIX . '%']);
    \Core\Database::query('DELETE FROM `teainfo` WHERE tea_name LIKE ?', [T_PREFIX . '%']);
    \Core\Database::query('DELETE FROM `subject` WHERE subj_name LIKE ?', [T_PREFIX . '%']);
    \Core\Database::query('DELETE FROM `exam_category` WHERE category_name LIKE ?', [T_PREFIX . '%']);
    \Core\Database::query('DELETE FROM `gradeinfo` WHERE grade_name LIKE ?', [T_PREFIX . '%']);
    \Core\Database::query('DELETE FROM `classinfo` WHERE class_name LIKE ?', [T_PREFIX . '%']);
    \Core\Database::query('DELETE FROM `examnews` WHERE news_title LIKE ?', [T_PREFIX . '%']);
}

adminCleanup();

/* ---------- 准备两个管理员账号：超级 + 低权 ---------- */
$hash = \App\Services\Password::hash(ADMIN_PASS);
\Core\Database::query(
    'INSERT INTO `admininfo` (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)',
    [ADMIN_USER, $hash, 'systemAdmin', '']
);
\Core\Database::query(
    'INSERT INTO `admininfo` (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)',
    [LOW_USER, \App\Services\Password::hash(LOW_PASS), 'quizAdder', '']
);
echo "  已创建测试管理员：systemAdmin + quizAdder\n";

/* ---------- 1. 未登录：管理接口必须 401 ---------- */
$t->guard('未登录访问 dashboard -> 401', function () use ($t) {
    $res = Http::get('/api/admin/dashboard');
    $t->assertSame('未登录 dashboard', 401, $res['status']);
});

$t->guard('未登录写接口 -> 401', function () use ($t) {
    $res = Http::post('/api/admin/subjects', ['subj_name' => T_PREFIX . 'x']);
    $t->assertSame('未登录写科目', 401, $res['status']);
});

/* ---------- 2. 登录（超级管理员） ---------- */
$csrf = '';
$t->guard('超级管理员登录成功', function () use ($t, &$csrf) {
    $res = Http::post('/api/admin/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS]);
    $t->assertSame('登录状态码', 200, $res['status']);
    $t->assertSame('登录业务码', 0, $res['body']['code'] ?? -1);
    $data = Http::data($res) ?? [];
    $t->assertTrue('返回 csrf_token', !empty($data['csrf_token']));
    $t->assertTrue('返回权限列表', !empty($data['permissions']));
    $csrf = (string) ($data['csrf_token'] ?? '');
});

$t->guard('错误密码 -> 401', function () use ($t) {
    $res = Http::post('/api/admin/login', ['username' => ADMIN_USER, 'password' => 'wrong-pass']);
    $t->assertSame('错误密码', 401, $res['status']);
});

/* ---------- 3. CSRF（P0-2）---------- */
$t->guard('写操作缺少 CSRF token -> 419', function () use ($t) {
    $res = Http::post('/api/admin/subjects', ['subj_name' => T_PREFIX . 'csrf']);
    $t->assertSame('无 token 写操作', 419, $res['status']);
});

$t->guard('携带 CSRF token 放行', function () use ($t, $csrf) {
    $res = Http::post('/api/admin/subjects', ['subj_name' => T_PREFIX . 'csrf'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('带 token 写操作', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $GLOBALS['__t_subj_csrf'] = (int) ($data['id'] ?? 0);
});

/* ---------- 4. 科目 CRUD ---------- */
$subjId = 0;
$t->guard('创建科目', function () use ($t, $csrf, &$subjId) {
    $res = Http::post(
        '/api/admin/subjects',
        ['subj_name' => T_PREFIX . '科目A', 'subj_info' => '测试科目'],
        ['X-CSRF-Token' => $csrf]
    );
    $t->assertSame('创建科目状态', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $subjId = (int) ($data['id'] ?? 0);
    $t->assertTrue('返回新科目 id', $subjId > 0);
});

$t->guard('科目重名 -> 409', function () use ($t, $csrf) {
    $res = Http::post('/api/admin/subjects', ['subj_name' => T_PREFIX . '科目A'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('重名科目', 409, $res['status']);
});

$t->guard('科目缺参数 -> 400', function () use ($t, $csrf) {
    $res = Http::post('/api/admin/subjects', ['subj_info' => '无名称'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('缺 subj_name', 400, $res['status']);
});

$t->guard('科目列表可读', function () use ($t) {
    $res = Http::get('/api/admin/subjects');
    $t->assertSame('科目列表状态', 200, $res['status']);
    $list = Http::data($res)['list'] ?? [];
    $t->assertTrue('列表非空', is_array($list) && count($list) > 0);
});

$t->guard('更新科目', function () use ($t, $csrf, $subjId) {
    $res = Http::put(
        "/api/admin/subjects/{$subjId}",
        ['subj_name' => T_PREFIX . '科目A改', 'subj_info' => '已更新'],
        ['X-CSRF-Token' => $csrf]
    );
    $t->assertSame('更新科目状态', 200, $res['status']);
    $t->assertSame('名称已更新', T_PREFIX . '科目A改', Http::data($res)['subj_name'] ?? '');
});

/* ---------- 5. 类别 / 单位 / 班级 / 公告 ---------- */
$catId = 0;
$t->guard('创建考试类别', function () use ($t, $csrf, &$catId) {
    $res = Http::post(
        '/api/admin/exam-categories',
        ['category_name' => T_PREFIX . '类别A', 'sort_order' => 5],
        ['X-CSRF-Token' => $csrf]
    );
    $t->assertSame('创建类别状态', 200, $res['status']);
    $catId = (int) (Http::data($res)['id'] ?? 0);
    $t->assertTrue('返回类别 id', $catId > 0);
});

$gradeId = 0;
$t->guard('创建单位', function () use ($t, $csrf, &$gradeId) {
    $res = Http::post(
        '/api/admin/grades',
        ['grade_name' => T_PREFIX . '单位A'],
        ['X-CSRF-Token' => $csrf]
    );
    $t->assertSame('创建单位状态', 200, $res['status']);
    $gradeId = (int) (Http::data($res)['id'] ?? 0);
    $t->assertTrue('返回单位 id', $gradeId > 0);
});

$classId = 0;
$t->guard('创建班级', function () use ($t, $csrf, &$classId) {
    $res = Http::post(
        '/api/admin/classes',
        ['class_name' => T_PREFIX . '班级A'],
        ['X-CSRF-Token' => $csrf]
    );
    $t->assertSame('创建班级状态', 200, $res['status']);
    $classId = (int) (Http::data($res)['id'] ?? 0);
    $t->assertTrue('返回班级 id', $classId > 0);
});

$t->guard('删除被引用的单位 -> 409', function () use ($t, $csrf, $gradeId) {
    // 造一个考生挂在该单位下
    \Core\Database::query(
        'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        ['9000099', T_PREFIX . '考生A', \App\Services\Password::hash('pwd12345'), '男', (string) $gradeId, '']
    );
    $res = Http::delete("/api/admin/grades/{$gradeId}", ['X-CSRF-Token' => $csrf]);
    $t->assertSame('单位被引用不可删', 409, $res['status']);
});

$t->guard('发布公告', function () use ($t, $csrf) {
    $res = Http::post(
        '/api/admin/news',
        ['news_title' => T_PREFIX . '公告A', 'news_info' => '内容'],
        ['X-CSRF-Token' => $csrf]
    );
    $t->assertSame('发布公告状态', 200, $res['status']);
    $body = Http::data($res) ?? [];
    $t->assertSame('作者取自会话', ADMIN_USER, $body['news_writer'] ?? '');
    $t->assertTrue('发布时间已填', !empty($body['news_time']));
});

/* ---------- 6. 题库：答案归一化 ---------- */
$t->guard('新建多选题答案自动排序并大写', function () use ($t, $csrf, $subjId) {
    $res = Http::post('/api/admin/quizzes', [
        'subj_id'      => $subjId,
        'quiz_title'   => T_PREFIX . '多选题A',
        'quiz_class'    => 'checkbox',
        'quiz_diff'    => 'Z',
        'quiz_option'  => '选项一|选项二|选项三|选项四',
        'quiz_key'     => 'dcba',
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('新建多选题状态', 200, $res['status']);
    $key = Http::data($res)['quiz_key'] ?? '';
    $t->assertSame('答案已排序并大写', 'ABCD', $key);
});

$t->guard('填空题不保存选项', function () use ($t, $csrf, $subjId) {
    $res = Http::post('/api/admin/quizzes', [
        'subj_id'     => $subjId,
        'quiz_title'  => T_PREFIX . '填空题A',
        'quiz_class'  => 'text',
        'quiz_diff'   => 'Y',
        'quiz_option' => '不该保留的选项',
        'quiz_key'    => '答案甲',
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('新建填空题状态', 200, $res['status']);
    $t->assertSame('填空题选项已清空', '', (string) (Http::data($res)['quiz_option'] ?? 'x'));
});

$t->guard('题库列表筛选', function () use ($t, $subjId) {
    $res = Http::get("/api/admin/quizzes?subj_id={$subjId}&quiz_class=checkbox");
    $t->assertSame('筛选状态', 200, $res['status']);
    $list = Http::data($res)['list'] ?? [];
    $t->assertTrue('筛选有结果', count($list) > 0);
    foreach ($list as $row) {
        if (($row['quiz_class'] ?? '') !== 'checkbox') {
            $t->register('筛选结果题型一致', false, '出现非 checkbox 的记录');
            return;
        }
    }
    $t->register('筛选结果题型一致', true);
});

$t->guard('题库非法题型参数 -> 400', function () use ($t) {
    $res = Http::get('/api/admin/quizzes?quiz_class=not-a-type');
    $t->assertSame('非法题型', 400, $res['status']);
});

/* ---------- 7. 权限隔离（低权角色） ---------- */
$t->guard('切换为低权角色', function () use ($t) {
    Http::post('/api/admin/logout');
    $res = Http::post('/api/admin/login', ['username' => LOW_USER, 'password' => LOW_PASS]);
    $t->assertSame('低权登录状态', 200, $res['status']);
    $GLOBALS['__t_low_csrf'] = (string) (Http::data($res)['csrf_token'] ?? '');
});

$t->guard('低权角色可读题库', function () use ($t) {
    $res = Http::get('/api/admin/quizzes');
    $t->assertSame('低权读题库', 200, $res['status']);
});

$t->guard('低权角色越权删考试类别 -> 403', function () use ($t, $catId) {
    $lowCsrf = (string) ($GLOBALS['__t_low_csrf'] ?? '');
    $res = Http::delete("/api/admin/exam-categories/{$catId}", ['X-CSRF-Token' => $lowCsrf]);
    $t->assertSame('低权删类别应 403', 403, $res['status']);
});

$t->guard('低权角色越权访问系统管理 -> 403', function () use ($t) {
    $res = Http::get('/api/admin/system');
    $t->assertSame('低权访问系统管理应 403', 403, $res['status']);
});

$t->guard('低权角色越权读考生 -> 403', function () use ($t) {
    $res = Http::get('/api/admin/students');
    $t->assertSame('低权读考生应 403', 403, $res['status']);
});

/* ---------- 8. 系统管理需确认口令 ---------- */
$t->guard('切回超级管理员', function () use ($t) {
    Http::post('/api/admin/logout');
    $res = Http::post('/api/admin/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS]);
    $t->assertSame('重新登录', 200, $res['status']);
    $GLOBALS['__t_csrf2'] = (string) (Http::data($res)['csrf_token'] ?? '');
});

$t->guard('系统初始化缺少确认口令 -> 400', function () use ($t) {
    $csrf = (string) ($GLOBALS['__t_csrf2'] ?? '');
    $res = Http::post('/api/admin/system/initialize', [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('无确认口令', 400, $res['status']);
});

$t->guard('系统初始化错误口令 -> 400', function () use ($t) {
    $csrf = (string) ($GLOBALS['__t_csrf2'] ?? '');
    $res = Http::post('/api/admin/system/initialize', ['confirm' => 'YES'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('错误确认口令', 400, $res['status']);
});

/* ---------- 9. 仪表盘与配置 ---------- */
$t->guard('仪表盘返回统计', function () use ($t) {
    $res = Http::get('/api/admin/dashboard');
    $t->assertSame('仪表盘状态', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertTrue('含题库统计', isset($data['quiz']['total']));
    $t->assertTrue('含实体计数', isset($data['counts']['students']));
});

$t->guard('配置读写', function () use ($t) {
    $csrf = (string) ($GLOBALS['__t_csrf2'] ?? '');
    $res = Http::put('/api/admin/config', ['site_title' => '管理后台测试站点'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('更新配置状态', 200, $res['status']);
    $t->assertSame('配置已生效', '管理后台测试站点', Http::data($res)['site_title'] ?? '');
});

$t->guard('配置拒绝非白名单键', function () use ($t) {
    $csrf = (string) ($GLOBALS['__t_csrf2'] ?? '');
    $res = Http::put('/api/admin/config', ['hacked_key' => 'x'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('非白名单键 -> 400', 400, $res['status']);
});

/* ---------- 10. 自我保护：不能删除自己 ---------- */
$meId = 0;
$t->guard('读取当前管理员 id', function () use ($t, &$meId) {
    $res = Http::get('/api/admin/me');
    $meId = (int) (Http::data($res)['admin']['id'] ?? 0);
    $t->assertTrue('拿到自身 id', $meId > 0);
});

$t->guard('不能删除当前登录管理员 -> 409', function () use ($t, $meId) {
    $csrf = (string) ($GLOBALS['__t_csrf2'] ?? '');
    $res = Http::delete("/api/admin/admins/{$meId}", ['X-CSRF-Token' => $csrf]);
    $t->assertSame('删除自己应 409', 409, $res['status']);
});

$t->guard('管理员新建密码强度校验 -> 400', function () use ($t) {
    $csrf = (string) ($GLOBALS['__t_csrf2'] ?? '');
    $res = Http::post('/api/admin/admins', [
        'username'    => T_PREFIX . 'weakadmin',
        'password'    => '123456',
        'admin_power' => 'quizAdder',
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('弱密码应 400', 400, $res['status']);
});

/* ---------- 清理 ---------- */
adminCleanup();
\Core\Database::query('DELETE FROM `stuinfo` WHERE id = ?', [9000099]);
\Core\Database::query('DELETE FROM `quizlib` WHERE quiz_title LIKE ?', [T_PREFIX . '%']);
\Core\Database::query('DELETE FROM `siteconfig` WHERE config_key = ? AND config_value = ?', ['site_title', '管理后台测试站点']);
echo "  测试数据已清理\n";

exit($t->finish());
