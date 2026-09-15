<?php

declare(strict_types=1);

/**
 * P0 缺陷回归测试 —— 锁定本轮全面审查中修复的阻断 / 安全缺陷：
 *
 *   BUG-001 独立考场入口（/exam）不下发 CSRF 令牌，考生无法保存答案与交卷（419）
 *   BUG-002 GET /api/student/exams 把考场口令 exam_pwd 下发给考生本人
 *   BUG-003 教师端 {id} 接口无归属校验，教师之间可水平越权
 *   BUG-005 监考「解锁」可把已交卷状态回退，成绩可被事后覆盖
 *   BUG-012 交卷判分无幂等门禁，重复判分会覆盖成绩
 *   BUG-016 成绩名单逐条返回考场口令 stu_pwd
 *
 * 每个用例都对应 docs/BUG_REPORT.md 中的编号，失败即代表缺陷回归。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\StuScore;
use App\Services\ExamEngine;
use App\Services\Password;
use Core\Database;
use Test\Fixture;
use Test\Harness;
use Test\Http;

if (ob_get_level() === 0) {
    ob_start();
}

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

$t = new Harness();
echo "== P0 缺陷回归测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('P0 回归', '数据库不可用');
    exit($t->finish());
}

/* ============================ 夹具 ============================ */
Fixture::cleanup();
Database::query("DELETE FROM `teainfo` WHERE tea_name LIKE ?", [Fixture::PREFIX . '%']);

$teaA = Fixture::PREFIX . '教师A';
$teaB = Fixture::PREFIX . '教师B';
foreach ([$teaA, $teaB] as $name) {
    Database::query(
        'INSERT INTO `teainfo` (tea_name, tea_pwd, avatar) VALUES (?, ?, ?)',
        [$name, Password::hash(Fixture::PWD), '']
    );
}

$fx = Fixture::createExam(['exam_tea' => $teaA]);
if ($fx === null) {
    $t->skip('P0 回归', '题库无可用题目');
    exit($t->finish());
}
$examId = (int) $fx['exam_id'];
$stuA   = (string) $fx['stu_a'];
$pwd    = (string) $fx['exam_pwd'];
echo "  夹具考试 #{$examId}（归属教师：{$teaA}）\n";

/* ============ BUG-001 独立考场入口的 CSRF 令牌 ============ */
$t->guard('BUG-001 独立考场入口下发 CSRF 令牌，写操作不再被 419 拦截', function () use ($t, $examId, $stuA, $pwd) {
    // 模拟全新浏览器：从未登录个人中心，直接从 /exam 入场
    $_SESSION = [];

    $res = Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $stuA,
        'password' => Fixture::PWD,
        'exam_pwd' => $pwd,
    ]);
    $t->assertSame('独立入口入场 -> 200', 200, $res['status']);

    $csrf = (string) (Http::data($res)['csrf_token'] ?? '');
    $t->assertTrue('登录响应下发 csrf_token', $csrf !== '', $csrf === '' ? '响应中没有 csrf_token' : '');

    // 反证：不带令牌时确实会被拦截，说明上面的通过是令牌生效而非校验失效
    $noTok = Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A']);
    $t->assertSame('不带令牌 -> 419（校验确实生效）', 419, $noTok['status']);

    $withTok = Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf]);
    $t->assertTrue('带令牌的写请求不被 419 拦截', $withTok['status'] !== 419, '实际 ' . $withTok['status']);
});

/* ============ BUG-002 学生列表不得泄露考场口令 ============ */
$t->guard('BUG-002 /api/student/exams 不再下发考场口令', function () use ($t, $examId, $stuA) {
    $_SESSION = [];
    $login = Http::post('/api/student/login', ['username' => $stuA, 'password' => Fixture::PWD]);
    $t->assertSame('考生登录 -> 200', 200, $login['status']);

    $res = Http::get('/api/student/exams');
    $t->assertSame('列表请求 -> 200', 200, $res['status']);

    $list = (array) (Http::data($res)['list'] ?? []);
    $leaked = array_values(array_filter(
        $list,
        static fn ($e): bool => array_key_exists('exam_pwd', (array) $e) || array_key_exists('stu_pwd', (array) $e)
    ));
    $t->assertSame('没有任何一行包含 exam_pwd / stu_pwd', 0, count($leaked));
});

/* ============ BUG-003 教师端归属校验 ============ */
$t->guard('BUG-003 教师只能访问自己负责的考试', function () use ($t, $examId, $teaA, $teaB) {
    // 正向：负责人本人可访问（证明归属校验没有误伤合法使用）
    $_SESSION = [];
    $la = Http::post('/api/teacher/login', ['username' => $teaA, 'password' => Fixture::PWD]);
    $t->assertSame('教师A登录 -> 200', 200, $la['status']);
    $ok = Http::get("/api/teacher/exams/{$examId}");
    $t->assertSame('教师A读取自己的考试 -> 200', 200, $ok['status']);

    // 反向：无关教师不得访问
    $_SESSION = [];
    $lb = Http::post('/api/teacher/login', ['username' => $teaB, 'password' => Fixture::PWD]);
    $t->assertSame('教师B登录 -> 200', 200, $lb['status']);

    $show = Http::get("/api/teacher/exams/{$examId}");
    $t->assertTrue('教师B读取他人考试被拒', $show['status'] !== 200, '实际 ' . $show['status']);

    $students = Http::get("/api/teacher/exams/{$examId}/students");
    $t->assertTrue('教师B读取他人考试考生名单被拒', $students['status'] !== 200, '实际 ' . $students['status']);
});

/* ============ BUG-016 成绩名单不得返回考场口令 ============ */
$t->guard('BUG-016 成绩名单不再返回 stu_pwd', function () use ($t, $examId) {
    $rows = (new StuScore())->byExam($examId, 'stuid', 'asc');
    $t->assertTrue('名单非空（前置条件）', $rows !== []);
    $hit = array_values(array_filter($rows, static fn ($r): bool => array_key_exists('stu_pwd', (array) $r)));
    $t->assertSame('没有任何一行包含 stu_pwd', 0, count($hit));
});

/* ============ BUG-005 已交卷状态不可被迁出 ============ */
$t->guard('BUG-005 已交卷考生不会被「解锁」回退状态', function () use ($t, $examId, $stuA) {
    $scores = new StuScore();
    Database::query(
        "UPDATE `stuscore` SET stu_status = 'over' WHERE exam_id = ? AND stu_id = ?",
        [$examId, $stuA]
    );

    $affected = $scores->updateStatus($examId, $stuA, 'online');
    $t->assertSame('解锁已交卷考生 -> 影响 0 行', 0, $affected);

    $row = Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $stuA]
    );
    $t->assertSame('状态仍为 over', 'over', (string) ($row['stu_status'] ?? ''));

    // 显式声明允许时仍可操作（保留管理员回收场景的能力）
    $forced = $scores->updateStatus($examId, $stuA, 'online', true);
    $t->assertSame('显式放行时可修改', 1, $forced);

    Database::query(
        "UPDATE `stuscore` SET stu_status = 'over' WHERE exam_id = ? AND stu_id = ?",
        [$examId, $stuA]
    );
});

/* ============ BUG-012 判分幂等 ============ */
$t->guard('BUG-012 重复判分不会覆盖已封存的成绩', function () use ($t, $examId, $stuA) {
    $exam = (new \App\Models\Exam())->find($examId);
    ExamEngine::generateForClass($examId, (array) $exam);

    Database::query(
        "UPDATE `stuscore` SET stu_status = 'online' WHERE exam_id = ? AND stu_id = ?",
        [$examId, $stuA]
    );

    $first = ExamEngine::autoGrade($examId, $stuA);
    $row1 = Database::fetch(
        'SELECT stu_score, stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $stuA]
    );
    $t->assertSame('首次判分后状态为 over', 'over', (string) ($row1['stu_status'] ?? ''));

    // 人为改一个不同的成绩，再判一次：不应被覆盖
    Database::query(
        'UPDATE `stuscore` SET stu_score = 999 WHERE exam_id = ? AND stu_id = ?',
        [$examId, $stuA]
    );
    $second = ExamEngine::autoGrade($examId, $stuA);
    $row2 = Database::fetch(
        'SELECT stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $stuA]
    );
    $t->assertSame('重复判分返回既有成绩', 999, (int) ($row2['stu_score'] ?? -1));
    $t->assertSame('重复判分返回值与库中一致', 999, $second);
    $t->assertSame('首次判分分数有效', true, is_int($first));
});

/* ============================ 清理 ============================ */
Fixture::cleanup();
Database::query("DELETE FROM `teainfo` WHERE tea_name LIKE ?", [Fixture::PREFIX . '%']);

exit($t->finish());
