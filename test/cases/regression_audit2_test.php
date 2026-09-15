<?php

declare(strict_types=1);

/**
 * 第二轮全面审查 —— 回归测试
 *
 * 锁定本轮修复的缺陷（对应 docs/BUG_REPORT.md 追补条目）：
 *
 *   BUG-101 练习抽题返回的题目缺少 quiz_id 字段（SELECT q.id 未起别名），
 *           导致前端题号显示 "#undefined"、提交答案恒 400
 *   BUG-102 正式考试进行中，/api/exercise/answer 仍按 quiz_id 下发正确答案
 *           —— 可反查正在考的题目答案（击穿 P0-4）
 *   BUG-103 模拟考试以 exam_status='testing' 落库，被误判为「正式考试进行中」，
 *           学生自己开一场模拟考试就会把练习入口错误暂停
 *   BUG-104 正式考试进行中可自由组模拟考试并立即交卷，再经 review() 整卷
 *           导出 quiz_key（批量答案泄露）
 *   BUG-105 /api/health 从不下发 X-CSRF-Token，前端 419 自动恢复形同虚设
 *   BUG-106 /api/exam/status 不下发 csrf_token，答题页刷新后保存 / 交卷恒 419
 *   BUG-107 成绩 CSV 导出未防公式注入（姓名等以 = + - @ 开头会被 Excel 当公式执行）
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Services\InvigilationService;
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
echo "== 第二轮审查回归测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('第二轮回归', '数据库不可用');
    exit($t->finish());
}

/* ============================ 夹具 ============================ */
Fixture::cleanup();

$fx = Fixture::createExam();
if ($fx === null) {
    $t->skip('第二轮回归', '题库无可用题目');
    exit($t->finish());
}
$examId = (int) $fx['exam_id'];
$stuA   = (string) $fx['stu_a'];

// 挑一个「确实有题」的科目 + 题型组合，供练习接口使用
$pick = Database::fetch(
    "SELECT subj_id, quiz_class, COUNT(*) AS c FROM `quizlib`
     GROUP BY subj_id, quiz_class ORDER BY c DESC LIMIT 1"
);
if ($pick === null) {
    Fixture::cleanup();
    $t->skip('第二轮回归', '题库为空');
    exit($t->finish());
}
$subjId    = (int) $pick['subj_id'];
$quizClass = (string) $pick['quiz_class'];

// 把库中既有的「进行中正式考试」临时挪开，使 hasOngoingFormalExam() 确定为 false，
// 以便验证「无正式考试时功能正常」；脚本结束前原样恢复。
$parked = Database::fetchAll(
    "SELECT id, exam_status FROM `examinfo`
     WHERE exam_status = 'testing' AND COALESCE(exam_class, '') <> ?",
    [Exam::MOCK_CLASS]
);
Database::query(
    "UPDATE `examinfo` SET exam_status = 'paper'
     WHERE exam_status = 'testing' AND COALESCE(exam_class, '') <> ?",
    [Exam::MOCK_CLASS]
);

echo "  夹具考试 #{$examId}；练习用科目 {$subjId} / 题型 {$quizClass}\n";

/** 恢复被临时挪开的进行中考试 */
$restore = static function () use ($parked): void {
    foreach ($parked as $r) {
        Database::query(
            'UPDATE `examinfo` SET exam_status = ? WHERE id = ?',
            [(string) $r['exam_status'], (int) $r['id']]
        );
    }
};

/* ============ BUG-101 练习题目必须携带 quiz_id ============ */
$t->guard('BUG-101 练习抽题返回 quiz_id（不再是 undefined）', function () use ($t, $subjId, $quizClass, $stuA) {
    $_SESSION = [];
    $login = Http::post('/api/student/login', ['username' => $stuA, 'password' => Fixture::PWD]);
    $t->assertSame('考生登录 -> 200', 200, $login['status']);

    $res = Http::get("/api/exercise?subj_id={$subjId}&quiz_class={$quizClass}");
    $t->assertSame('练习抽题 -> 200', 200, $res['status']);

    $q = (array) (Http::data($res)['question'] ?? []);
    $t->assertTrue('返回了题目', $q !== []);

    $quizId = $q['quiz_id'] ?? null;
    $t->assertTrue(
        '题目携带整数 quiz_id',
        is_int($quizId) && $quizId > 0,
        'quiz_id = ' . var_export($quizId, true)
    );
    // 练习阶段绝不带答案
    $t->assertTrue('题目不含 quiz_key', !array_key_exists('quiz_key', $q));
});

/* ============ BUG-103 模拟考试不得触发「考试进行中」 ============ */
$t->guard('BUG-103 模拟考试不被计入「进行中的正式考试」', function () use ($t, $subjId) {
    $before = Exam::hasOngoingFormalExam();

    Database::query(
        "INSERT INTO `examinfo`
            (exam_name, exam_class, subj_id, exam_start, exam_end, exam_tea, stu_class, exam_status, exam_score)
         VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), '', '', 'testing', 0)",
        [Fixture::PREFIX . '模拟考试', Exam::MOCK_CLASS, $subjId]
    );
    $mockId = (int) Database::lastInsertId();

    $after = Exam::hasOngoingFormalExam();
    $t->assertSame('插入一场 exam_status=testing 的模拟考试后，「正式考试进行中」判定不变', $before, $after);
    $t->assertTrue('该模拟考试确实以 testing 落库（前置条件）', $mockId > 0);

    Database::query('DELETE FROM `examinfo` WHERE id = ?', [$mockId]);
});

/* ============ BUG-105 /api/health 下发 X-CSRF-Token ============ */
$t->guard('BUG-105 /api/health 下发 X-CSRF-Token 响应头', function () use ($t) {
    $res = Http::get('/api/health');
    $t->assertSame('健康检查 -> 200', 200, $res['status']);

    $token = (string) ($res['headers']['X-CSRF-Token'] ?? '');
    $t->assertTrue('响应头含 X-CSRF-Token', $token !== '', '未下发该响应头');
    $t->assertSame('令牌为 32 字节随机数（64 位十六进制）', 64, strlen($token));
});

/* ============ BUG-106 /api/exam/status 下发 csrf_token ============ */
$t->guard('BUG-106 答题页可从 /api/exam/status 重新取得 csrf_token', function () use ($t, $examId, $stuA, $fx) {
    $_SESSION = [];

    $login = Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $stuA,
        'password' => Fixture::PWD,
        'exam_pwd' => (string) $fx['exam_pwd'],
    ]);
    $t->assertSame('考场入场 -> 200', 200, $login['status']);
    $loginToken = (string) (Http::data($login)['csrf_token'] ?? '');
    $t->assertTrue('入场响应含 csrf_token', $loginToken !== '');

    // 模拟「刷新答题页」：前端模块状态清空，只能靠 /status 重新拿到令牌
    $status = Http::get('/api/exam/status');
    $t->assertSame('状态接口 -> 200', 200, $status['status']);
    $statusToken = (string) (Http::data($status)['csrf_token'] ?? '');
    $t->assertTrue('状态响应含 csrf_token', $statusToken !== '');
    $t->assertSame('与入场令牌一致', $loginToken, $statusToken);
});

/* ============ BUG-107 CSV 导出防公式注入 ============ */
$t->guard('BUG-107 成绩 CSV 对 = 开头的姓名做转义，正常姓名不受影响', function () use ($t, $examId, $stuA) {
    $svc = new InvigilationService();

    // 危险姓名：以 = 开头，Excel 会当公式执行
    Database::query('UPDATE `stuinfo` SET stu_name = ? WHERE id = ?', ['=1+1', $stuA]);
    $csv = $svc->csv($examId, true);
    $t->assertTrue('危险姓名被前置单引号转义', str_contains($csv, "'=1+1"), '未转义：' . substr($csv, 0, 200));

    // 正常姓名不应被画蛇添足地加引号
    Database::query('UPDATE `stuinfo` SET stu_name = ? WHERE id = ?', ['自动测试甲', $stuA]);
    $csv2 = $svc->csv($examId, true);
    $t->assertTrue('正常姓名原样导出', str_contains($csv2, '自动测试甲'));
    $t->assertTrue('正常姓名未被加引号', !str_contains($csv2, "'自动测试甲"));
});

/* ==================================================================
 * 以下用例需要「正式考试进行中」，故在此插入一场 testing 的正式考试
 * ================================================================== */
$busyExam = Fixture::createExam(['exam_status' => 'testing']);
$t->assertTrue('已建立进行中的正式考试（前置条件）', $busyExam !== null && Exam::hasOngoingFormalExam());

/* ============ BUG-102 开考期间练习答案校验必须被拒 ============ */
$t->guard('BUG-102 正式考试进行中 /api/exercise/answer 被拒绝', function () use ($t, $subjId, $quizClass, $stuA) {
    $_SESSION = [];
    $login = Http::post('/api/student/login', ['username' => $stuA, 'password' => Fixture::PWD]);
    $t->assertSame('考生登录 -> 200', 200, $login['status']);
    $csrf = (string) (Http::data($login)['csrf_token'] ?? '');

    // 抽题接口同步进入「已暂停」态（既有约定）
    $list = Http::get("/api/exercise?subj_id={$subjId}&quiz_class={$quizClass}");
    $t->assertSame('练习列表 -> 200', 200, $list['status']);
    $t->assertSame('has_ongoing_exam = true', true, (bool) (Http::data($list)['has_ongoing_exam'] ?? false));

    // 关键：直接打答案校验接口也必须被拒（此前无任何拦截）
    $row = Database::fetch(
        'SELECT id FROM `quizlib` WHERE subj_id = ? AND quiz_class = ? LIMIT 1',
        [$subjId, $quizClass]
    );
    $quizId = (int) ($row['id'] ?? 0);
    $t->assertTrue('取到一道样例题（前置条件）', $quizId > 0);

    $hit = Http::post('/api/exercise/answer', ['quiz_id' => $quizId, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('开考期间取答案 -> 403', 403, $hit['status']);
});

/* ============ BUG-104 开考期间不得经模拟考试导出整卷答案 ============ */
$t->guard('BUG-104 正式考试进行中模拟考试被拒绝', function () use ($t, $subjId, $stuA) {
    $_SESSION = [];
    $login = Http::post('/api/student/login', ['username' => $stuA, 'password' => Fixture::PWD]);
    $t->assertSame('考生登录 -> 200', 200, $login['status']);
    $csrf = (string) (Http::data($login)['csrf_token'] ?? '');

    $start = Http::post('/api/exercise/mock/start', [
        'subj_id' => $subjId, 'radio1_count' => 1,
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('开考期间组模拟卷 -> 403', 403, $start['status']);
});

$t->guard('BUG-104 正式考试进行中错题回顾被拒绝', function () use ($t, $subjId, $stuA) {
    $_SESSION = [];

    // 先造一场「已交卷」的模拟考试，确保若非拦截本应能复盘（否则测不出守卫）
    Database::query(
        "INSERT INTO `examinfo`
            (exam_name, exam_class, subj_id, exam_start, exam_end, exam_tea, stu_class, exam_status, exam_score)
         VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), '', '', 'over', 0)",
        [Fixture::PREFIX . '模拟考试复盘', Exam::MOCK_CLASS, $subjId]
    );
    $mockId = (int) Database::lastInsertId();
    Database::query(
        "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
         VALUES (?, ?, 0, 'over', '')",
        [$mockId, $stuA]
    );

    $login = Http::post('/api/student/login', ['username' => $stuA, 'password' => Fixture::PWD]);
    $t->assertSame('考生登录 -> 200', 200, $login['status']);

    $review = Http::get("/api/exercise/mock/review?exam_id={$mockId}");
    $t->assertSame('开考期间复盘 -> 403', 403, $review['status']);

    Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$mockId]);
    Database::query('DELETE FROM `examinfo` WHERE id = ?', [$mockId]);
});

/* ============================ 清理 ============================ */
$restore();
Fixture::cleanup();

exit($t->finish());
