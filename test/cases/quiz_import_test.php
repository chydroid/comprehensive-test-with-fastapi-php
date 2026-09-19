<?php

declare(strict_types=1);

/**
 * 题库批量导入测试（B3）
 *
 * 覆盖：
 * - 路由：POST /api/admin/quizzes/import 静态优先命中（不被 /{id} 吞掉）
 * - 鉴权：未登录 401、缺 quiz.import 的角色 403、持权角色放行
 * - 解析：中文题型 / 题型代码、科目名或科目 ID、选项分隔符归一、
 *         判断题口语答案（对/错）映射、多选答案去重排序、难度中文或代码、
 *         表头自动跳过、空行跳过、录题人回落当前账号
 * - 知识点（A3 遗留 gap）：导入可写 quiz_kp，且单题新增/修改也写 quiz_kp
 * - 容错：单行失败不影响整批；全失败返回 400 并携带 errors
 * - 审计：导入写 quiz.import
 *
 * 说明：本测试会真正写题库，全部测试数据以 __TEST_IMP__ 前缀命名并在结束时回收。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Services\AuthSession;
use App\Services\Password;
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
echo "== 题库批量导入测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('题库批量导入', '数据库不可用');
    exit($t->finish());
}

const IMP = '__TEST_IMP__';
const IMP_ADDER = '__TEST_IMP__adder';
const IMP_ADDER_PWD = 'qaPwd123456';
const IMP_OPER = '__TEST_IMP__oper';
const IMP_OPER_PWD = 'qoPwd123456';

/** LIKE 转义（_ 是通配符，不转义会误伤） */
$like = static fn (string $prefix): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
$impLike = $like(IMP);

/** 回收历史脏数据 + 本次数据 */
function impCleanup(string $impLike): void
{
    \Core\Database::query('DELETE FROM `quizlib` WHERE quiz_title LIKE ?', [$impLike]);
    \Core\Database::query('DELETE FROM `subject` WHERE subj_name LIKE ?', [$impLike]);
    \Core\Database::query('DELETE FROM `admininfo` WHERE username LIKE ?', [$impLike]);
    \Core\Database::query('DELETE FROM `admin_log` WHERE action = ?', ['quiz.import']);
}

impCleanup($impLike);

/** 按题干取最新一条 */
$quizOf = static function (string $title): ?array {
    return \Core\Database::fetch('SELECT * FROM `quizlib` WHERE quiz_title = ? ORDER BY id DESC LIMIT 1', [$title]);
};

/* ---------- 0. 路由优先级：静态 import 必须优先于 /{id} ---------- */
$t->guard('路由静态优先', function () use ($t, $base) {
    $router = new Core\Router();
    foreach (require $base . '/config/routes.php' as $route) {
        if (!is_array($route) || count($route) < 3) continue;
        $router->add($route[0], $route[1], $route[2], $route[3] ?? []);
    }
    $t->assertSame('POST /api/admin/quizzes/import 命中静态路由', '/api/admin/quizzes/import', $router->match('POST', '/api/admin/quizzes/import'));
    $t->assertSame('POST /api/admin/quizzes/batch-delete 仍命中静态路由', '/api/admin/quizzes/batch-delete', $router->match('POST', '/api/admin/quizzes/batch-delete'));
});

/* ---------- 夹具：科目 + 两个不同权限的角色 ---------- */
$subjModel = new \App\Models\Subject();
$subjId = (int) $subjModel->create(['subj_name' => IMP . '科目', 'subj_info' => '']);
$t->assertTrue('夹具科目创建成功', $subjId > 0);

foreach ([[IMP_ADDER, IMP_ADDER_PWD, 'quizAdder'], [IMP_OPER, IMP_OPER_PWD, 'quizOperator']] as [$u, $p, $power]) {
    \Core\Database::query(
        'INSERT INTO `admininfo` (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)',
        [$u, \App\Services\Password::hash($p), $power, '']
    );
}

$login = static function (string $user, string $pwd): string {
    AuthSession::logout();
    $res = Http::post('/api/admin/login', ['username' => $user, 'password' => $pwd]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
};

$adderCsrf = $login(IMP_ADDER, IMP_ADDER_PWD);
$t->assertTrue('录题员登录并取得 CSRF', $adderCsrf !== '');

/* ---------- 1. 未登录 -> 401 ---------- */
$t->guard('未登录 401', function () use ($t) {
    AuthSession::logout();
    $res = Http::post('/api/admin/quizzes/import', ['content' => IMP . 'x']);
    $t->assertSame('未登录导入 -> 401', 401, $res['status']);
});

/* ---------- 2. 无 quiz.import 的角色 -> 403 ---------- */
$t->guard('无权限角色 403', function () use ($t, $login) {
    $operCsrf = $login(IMP_OPER, IMP_OPER_PWD);
    $t->assertTrue('运维员登录成功', $operCsrf !== '');
    $res = Http::post('/api/admin/quizzes/import', ['content' => IMP . 'x'], ['X-CSRF-Token' => $operCsrf]);
    $t->assertSame('quizOperator 无 quiz.import -> 403', 403, $res['status']);
});

/* ---------- 3. 空内容 -> 400 ---------- */
// 上一段把会话切成了运维员（登录会轮换 csrf_token），必须重新以录题员登录，
// 否则后续带的是失效的旧 token，全部请求会以 419 拒绝而非进入业务校验。
$adderCsrf = $login(IMP_ADDER, IMP_ADDER_PWD);
$t->assertTrue('重新登录录题员取得有效 CSRF', $adderCsrf !== '');

$t->guard('首行数据含「科目」二字不被当表头吞掉', function () use ($t, $adderCsrf, $quizOf) {
    $res = Http::post('/api/admin/quizzes/import', [
        'content' => IMP . '科目,单选题,' . IMP . '首行科目名,A.甲|B.乙,A,易,,',
    ], ['X-CSRF-Token' => $adderCsrf]);
    $t->assertSame('单行导入 -> 200', 200, $res['status']);
    $t->assertSame('首行数据未被当表头丢弃', 1, (int) ((Http::data($res) ?? [])['imported'] ?? 0));
    $t->assertTrue('该题确实落库', $quizOf(IMP . '首行科目名') !== null);
});

$t->guard('空内容 400', function () use ($t, $adderCsrf) {
    $res = Http::post('/api/admin/quizzes/import', ['content' => "   \n  "], ['X-CSRF-Token' => $adderCsrf]);
    $t->assertSame('空内容 -> 400', 400, $res['status']);
    $t->assertSame('空内容业务码 40000', 40000, (int) ($res['body']['code'] ?? 0));
});

/* ---------- 4. 全部失败 -> 400 且携带 errors ---------- */
$t->guard('全部失败 400', function () use ($t, $adderCsrf) {
    $content = IMP . '不存在科目,单选题,' . IMP . '孤儿题,A.甲|B.乙,A,易,,';
    $res = Http::post('/api/admin/quizzes/import', ['content' => $content], ['X-CSRF-Token' => $adderCsrf]);
    $t->assertSame('科目不存在 -> 400', 400, $res['status']);
    $t->assertSame('业务码 40001', 40001, (int) ($res['body']['code'] ?? 0));
    $errs = Http::data($res)['errors'] ?? [];
    $t->assertTrue('errors 非空且提示科目不存在', is_array($errs) && $errs !== [] && str_contains((string) $errs[0], '科目'));
});

/* ---------- 5. 正常导入：逐项校验解析结果 ---------- */
$content = implode("\n", [
    // 表头应被自动跳过
    '科目,题型,题干,选项,答案,难度,录题人,知识点',
    // 中文题型 + 绝对科目名 + 显式录题人 + 知识点
    IMP . '科目,单选题,' . IMP . '单选,A.甲|B.乙|C.丙|D.丁,C,易,' . IMP . '老王,' . IMP . 'KP甲',
    // 多选：分号分选项 + 乱序答案 CA 应去重排序为 AC + 录题人留空回落账号
    IMP . '科目,多选题,' . IMP . '多选,甲;乙;丙,CA,中,,   ' . IMP . 'KP乙  ',
    // 判断：口语答案「错」-> B；难度留空 -> Z
    IMP . '科目,判断题,' . IMP . '判断,对|错,错,,,',
    // 填空：选项列忽略，答案保留原文
    IMP . '科目,填空题,' . IMP . '填空,A.干扰|B.干扰,答案一|答案二,难,,',
    // 问答题：无选项无答案
    IMP . '科目,问答题,' . IMP . '问答,,,,,',
    // 题型代码 + 科目 ID（数字）
    $subjId . ',radio2,' . IMP . '代号题型,A.甲|B.乙,B,,,',
    '',
    '   ',
]);

$t->guard('正常导入 200', function () use ($t, $content, $adderCsrf) {
    $res = Http::post('/api/admin/quizzes/import', ['content' => $content], ['X-CSRF-Token' => $adderCsrf]);
    $t->assertSame('导入 -> 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertSame('导入 6 条', 6, (int) ($data['imported'] ?? 0));
    $t->assertSame('无失败行', 0, (int) ($data['failed'] ?? 0));
});

/** 按题干取最新一条 */
$t->guard('单选题字段解析', function () use ($t, $quizOf, $subjId) {
    $r = $quizOf(IMP . '单选');
    $t->assertTrue('单选题已落库', $r !== null);
    if ($r === null) return;
    $t->assertSame('科目名归一为科目 ID', $subjId, (int) $r['subj_id']);
    $t->assertSame('题型 radio2', 'radio2', (string) $r['quiz_class']);
    $t->assertSame('选项按 | 归一', 'A.甲|B.乙|C.丙|D.丁', (string) $r['quiz_option']);
    $t->assertSame('答案 C', 'C', (string) $r['quiz_key']);
    $t->assertSame('难度 易 -> Y', 'Y', (string) $r['quiz_diff']);
    $t->assertSame('录题人取显式值', IMP . '老王', (string) $r['quiz_writer']);
    $t->assertSame('知识点写入', IMP . 'KP甲', (string) $r['quiz_kp']);
});

$t->guard('多选题字段解析', function () use ($t, $quizOf, $adderCsrf) {
    $r = $quizOf(IMP . '多选');
    $t->assertTrue('多选题已落库', $r !== null);
    if ($r === null) return;
    $t->assertSame('题型 checkbox', 'checkbox', (string) $r['quiz_class']);
    $t->assertSame('分号分隔的选项归一为 |', '甲|乙|丙', (string) $r['quiz_option']);
    $t->assertSame('多选答案 CA 去重排序为 AC', 'AC', (string) $r['quiz_key']);
    $t->assertSame('录题人回落当前账号', IMP_ADDER, (string) $r['quiz_writer']);
    $t->assertSame('知识点去空白后写入', IMP . 'KP乙', (string) $r['quiz_kp']);
});

$t->guard('判断题字段解析', function () use ($t, $quizOf) {
    $r = $quizOf(IMP . '判断');
    $t->assertTrue('判断题已落库', $r !== null);
    if ($r === null) return;
    $t->assertSame('题型 radio1', 'radio1', (string) $r['quiz_class']);
    $t->assertSame('选项按 | 归一', '对|错', (string) $r['quiz_option']);
    $t->assertSame('口语答案「错」-> B', 'B', (string) $r['quiz_key']);
    $t->assertSame('难度留空回落 Z', 'Z', (string) $r['quiz_diff']);
    $t->assertSame('知识点留空为空串', '', (string) $r['quiz_kp']);
});

$t->guard('填空/问答字段解析', function () use ($t, $quizOf) {
    $fill = $quizOf(IMP . '填空');
    $t->assertTrue('填空题已落库', $fill !== null);
    if ($fill !== null) {
        $t->assertSame('填空题题型 text', 'text', (string) $fill['quiz_class']);
        $t->assertSame('非选择题选项列被忽略', '', (string) $fill['quiz_option']);
        $t->assertSame('填空答案保留原文', '答案一|答案二', (string) $fill['quiz_key']);
        $t->assertSame('难度 难 -> N', 'N', (string) $fill['quiz_diff']);
    }
    $qa = $quizOf(IMP . '问答');
    $t->assertTrue('问答题已落库', $qa !== null);
    if ($qa !== null) {
        $t->assertSame('问答题题型 longtext', 'longtext', (string) $qa['quiz_class']);
        $t->assertSame('问答题无选项', '', (string) $qa['quiz_option']);
        $t->assertSame('问答题无答案', '', (string) $qa['quiz_key']);
    }
});

$t->guard('题型代码与科目 ID 亦可', function () use ($t, $quizOf, $subjId) {
    $r = $quizOf(IMP . '代号题型');
    $t->assertTrue('按题型代码导入成功', $r !== null);
    if ($r === null) return;
    $t->assertSame('题型代码 radio2 生效', 'radio2', (string) $r['quiz_class']);
    $t->assertSame('科目 ID 直接可用', $subjId, (int) $r['subj_id']);
});

/* ---------- 6. 部分失败：成功行照常落库 ---------- */
$t->guard('部分失败容错', function () use ($t, $adderCsrf, $quizOf) {
    $content = implode("\n", [
        IMP . '科目,单选题,' . IMP . '部分成功,A.甲|B.乙,A,易,,',
        IMP . '科目,不存在的题型,' . IMP . '题型错,A.甲|B.乙,A,易,,',
        IMP . '科目,多选题,' . IMP . '缺答案,A.甲|B.乙|C.丙,,中,,',
        IMP . '不存在科目,单选题,' . IMP . '科目错,A.甲|B.乙,A,易,,',
    ]);
    $res = Http::post('/api/admin/quizzes/import', ['content' => $content], ['X-CSRF-Token' => $adderCsrf]);
    $t->assertSame('部分失败仍 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertSame('成功 1 条', 1, (int) ($data['imported'] ?? 0));
    $t->assertSame('失败 3 条', 3, (int) ($data['failed'] ?? 0));
    $t->assertSame('errors 明细 3 条', 3, count($data['errors'] ?? []));
    $t->assertTrue('成功行确实落库', $quizOf(IMP . '部分成功') !== null);
    $t->assertTrue('失败行未落库', $quizOf(IMP . '题型错') === null);
});

/* ---------- 7. 知识点写入口（补齐 A3 遗留） ---------- */
$t->guard('单题新增/修改写知识点', function () use ($t, $adderCsrf, $quizOf, $subjId) {
    $create = Http::post('/api/admin/quizzes', [
        'subj_id' => $subjId, 'quiz_title' => IMP . 'KP单题', 'quiz_class' => 'radio2',
        'quiz_diff' => 'Z', 'quiz_option' => 'A.甲|B.乙', 'quiz_key' => 'A',
        'quiz_kp' => IMP . 'KP直录',
    ], ['X-CSRF-Token' => $adderCsrf]);
    $t->assertSame('单题新增 -> 200', 200, $create['status']);
    $id = (int) ((Http::data($create) ?? [])['id'] ?? 0);
    $t->assertTrue('取得新题 ID', $id > 0);

    $row = $quizOf(IMP . 'KP单题');
    $t->assertSame('新增即写入知识点', IMP . 'KP直录', (string) ($row['quiz_kp'] ?? ''));

    $upd = Http::put('/api/admin/quizzes/' . $id, [
        'subj_id' => $subjId, 'quiz_title' => IMP . 'KP单题', 'quiz_class' => 'radio2',
        'quiz_diff' => 'Z', 'quiz_option' => 'A.甲|B.乙', 'quiz_key' => 'A',
        'quiz_kp' => IMP . 'KP改后',
    ], ['X-CSRF-Token' => $adderCsrf]);
    $t->assertSame('单题修改 -> 200', 200, $upd['status']);
    $row2 = $quizOf(IMP . 'KP单题');
    $t->assertSame('修改后知识点已更新', IMP . 'KP改后', (string) ($row2['quiz_kp'] ?? ''));
});

/* ---------- 8. 审计日志 ---------- */
$t->guard('导入写审计', function () use ($t) {
    $row = \Core\Database::fetch("SELECT * FROM `admin_log` WHERE action = 'quiz.import' ORDER BY id DESC LIMIT 1");
    $t->assertTrue('quiz.import 已落审计表', $row !== null);
    if ($row === null) return;
    $t->assertSame('审计 actor_type=admin', 'admin', (string) $row['actor_type']);
    $detail = json_decode((string) $row['detail'], true);
    $t->assertTrue('审计 detail 含 imported', is_array($detail) && isset($detail['imported']));
});

/* ---------- 9. 收尾：回收全部测试数据 ---------- */
AuthSession::logout();
impCleanup($impLike);
$t->assertSame('测试数据已回收', 0, (int) (\Core\Database::fetch('SELECT COUNT(*) AS c FROM `quizlib` WHERE quiz_title LIKE ?', [$impLike])['c'] ?? 0));

$t->finish();
