<?php

declare(strict_types=1);

/**
 * 正式考试全流程测试（考生端）—— 自包含夹具，不依赖库中既有业务数据。
 *
 * 重点验证 P0-4：考试进行中任何响应都不得包含 quiz_key。
 * 覆盖：登录取卷 -> 逐题作答 -> 交卷判分 -> 查答案 -> 幂等/越权。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

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
echo "== 考试流程测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('考试全流程', '数据库不可用');
    exit($t->finish());
}

Fixture::cleanup();
$fx = Fixture::createExam();
if ($fx === null) {
    $t->skip('考试全流程', '题库无可用题目');
    exit($t->finish());
}
$examId = (int) $fx['exam_id'];
echo "  夹具考试 #{$examId}（科目题量充足，每题型 1 题 / 5 分）\n";

/* ---------- 1. 未登录无法取卷 ---------- */
$t->guard('未登录取卷被拒', function () use ($t) {
    $res = Http::get('/api/exam/paper');
    $t->assertSame('未登录取卷 -> 401', 401, $res['status']);
});

/* ---------- 2. 错误的考场口令被拒 ---------- */
$t->guard('错误考场口令被拒', function () use ($t, $examId, $fx) {
    $res = Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $fx['stu_a'],
        'password' => Fixture::PWD,
        'exam_pwd' => '000000',
    ]);
    $t->assertSame('错误口令 -> 403', 403, $res['status']);
});

/* ---------- 3. 正确凭据登录考场 ---------- */
$t->guard('考场登录成功', function () use ($t, $examId, $fx) {
    $res = Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $fx['stu_a'],
        'password' => Fixture::PWD,
        'exam_pwd' => $fx['exam_pwd'],
    ]);
    $t->assertSame('登录成功 -> 200', 200, $res['status']);
    $data = Http::data($res);
    $t->assertTrue('返回组卷警告数组', is_array($data['warnings'] ?? null));
    $t->assertSame('无缺题警告', [], $data['warnings'] ?? null);

    // 组卷应生成 4 道题（每题型 1 题）
    $cnt = (int) (\Core\Database::fetch(
        'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $fx['stu_a']]
    )['c'] ?? 0);
    $t->assertSame('组卷生成 4 题', 4, $cnt);
});

/* ---------- 4. 每个题型都不能泄露答案（P0-4） ---------- */
$t->guard('全卷不泄露答案', function () use ($t) {
    for ($pid = 1; $pid <= 4; $pid++) {
        $res = Http::get("/api/exam/paper?paper_id={$pid}");
        $t->assertSame("第 {$pid} 题取卷成功", 200, $res['status']);
        $t->assertTrue(
            "第 {$pid} 题响应不含 quiz_key",
            !str_contains($res['raw'], 'quiz_key'),
            substr($res['raw'], 0, 200)
        );
    }
});

/* ---------- 5. 题目字段完整性 ---------- */
$t->guard('题目字段完整', function () use ($t) {
    $res = Http::get('/api/exam/paper?paper_id=1');
    $q = Http::data($res)['question'] ?? [];
    $t->assertTrue('含题干', !empty($q['quiz_title']));
    $t->assertTrue('含题型中文名', !empty($q['quiz_type_label']));
    $t->assertTrue('含选项数组', isset($q['quiz_option_list']) && is_array($q['quiz_option_list']));
    $t->assertTrue('含题号', ($q['paper_id'] ?? 0) === 1);
    $nav = Http::data($res)['navigation'] ?? [];
    $t->assertSame('答题卡总题数 4', 4, (int) ($nav['total'] ?? 0));
    $t->assertSame('答题卡未答 0', 0, (int) ($nav['done'] ?? -1));
});

/* ---------- 6. 越界题号 ---------- */
$t->guard('越界题号被拒', function () use ($t) {
    $res = Http::get('/api/exam/paper?paper_id=999');
    $t->assertSame('题号越界 -> 400', 400, $res['status']);
});

/* ---------- 7. CSRF：无令牌不能保存 ---------- */
$t->guard('保存答案需 CSRF 令牌', function () use ($t) {
    $res = Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A']);
    $t->assertSame('无 CSRF -> 419', 419, $res['status']);
});

/* ---------- 8. 逐题保存 + 答题卡状态 ---------- */
$t->guard('保存答案并更新答题卡', function () use ($t) {
    $csrf = \App\Services\AuthSession::csrfToken();
    // 第 1 题（判断题）提交 A；第 2 题（单选）提交 A
    $r1 = Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('第 1 题保存成功', 200, $r1['status']);
    $t->assertSame('返回下一题号 2', 2, (int) (Http::data($r1)['next_paper_id'] ?? 0));

    $r2 = Http::post('/api/exam/paper/save', ['paper_id' => 2, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('第 2 题保存成功', 200, $r2['status']);
    $nav = Http::data($r2)['navigation'] ?? [];
    $t->assertSame('答题卡已答 2 题', 2, (int) ($nav['done'] ?? -1));
});

/* ---------- 9. 多选题数组形式提交（归一化） ---------- */
$t->guard('多选题数组答案归一化', function () use ($t, $examId) {
    $csrf = \App\Services\AuthSession::csrfToken();
    // 第 3 题应为多选（checkbox），提交乱序数组 "C","A" -> 归一为 "AC"
    $res = Http::post('/api/exam/paper/save', ['paper_id' => 3, 'stu_key' => ['C', 'A']], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('数组答案保存成功', 200, $res['status']);
    $row = \Core\Database::fetch(
        'SELECT stu_key FROM `stupaper` WHERE exam_id = ? AND stu_id = ? AND paper_id = 3',
        [$examId, Fixture::STU_A]
    );
    $t->assertSame('多选题答案已排序归一', 'AC', (string) ($row['stu_key'] ?? ''));
});

/* ---------- 10. 交卷判分（指定正确答案，验证计分准确） ---------- */
$t->guard('交卷判分准确', function () use ($t, $examId, $fx) {
    $csrf = \App\Services\AuthSession::csrfToken();

    // 先读取正确答案，按正确答案回填，验证满分逻辑
    $keys = \Core\Database::fetchAll(
        'SELECT sp.paper_id, sp.quiz_class, q.quiz_key
         FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND sp.stu_id = ?
         ORDER BY sp.paper_id ASC',
        [$examId, $fx['stu_a']]
    );
    $expected = 0;
    foreach ($keys as $k) {
        $type = (string) $k['quiz_class'];
        $key = (string) $k['quiz_key'];
        if ($key === '') {
            continue;
        }
        Http::post('/api/exam/paper/save', ['paper_id' => (int) $k['paper_id'], 'stu_key' => $key], ['X-CSRF-Token' => $csrf]);
        $expected += 5;
    }

    $res = Http::post('/api/exam/paper/submit', [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('交卷成功', 200, $res['status']);
    $got = (int) (Http::data($res)['score'] ?? -1);
    $t->assertSame("全对得分应为 {$expected}", $expected, $got);

    // 成绩已落库
    $row = \Core\Database::fetch(
        'SELECT stu_score, stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $fx['stu_a']]
    );
    $t->assertSame('成绩已写入 stuscore', $expected, (int) ($row['stu_score'] ?? -1));
    $t->assertSame('状态已置为 over', 'over', (string) ($row['stu_status'] ?? ''));
});

/* ---------- 11. 交卷后可见答案 ---------- */
$t->guard('交卷后可查看答案', function () use ($t) {
    $res = Http::get('/api/exam/answer');
    $t->assertSame('查看答案成功', 200, $res['status']);
    $papers = Http::data($res)['papers'] ?? [];
    $t->assertSame('返回答卷明细 4 题', 4, count($papers));
    $t->assertTrue('此时应下发正确答案', str_contains($res['raw'], 'quiz_key'));
    $t->assertTrue('含判对标记', array_key_exists('is_correct', $papers[0] ?? []));
    $t->assertSame('首题判定为正确', true, $papers[0]['is_correct'] ?? null);
    $summary = Http::data($res)['summary'] ?? [];
    $t->assertTrue('含分题型统计', is_array($summary) && count($summary) > 0);
});

/* ---------- 12. 重复交卷幂等 ---------- */
$t->guard('重复交卷幂等', function () use ($t) {
    $csrf = \App\Services\AuthSession::csrfToken();
    $res = Http::post('/api/exam/paper/submit', [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('重复交卷仍 200', 200, $res['status']);
    $t->assertTrue('标记已交卷', (Http::data($res)['already_submitted'] ?? false) === true);
});

/* ---------- 13. 交卷后不能再取卷 ---------- */
$t->guard('交卷后禁止取卷', function () use ($t) {
    $res = Http::get('/api/exam/paper?paper_id=1');
    $t->assertSame('交卷后取卷 -> 409', 409, $res['status']);
});

/* ---------- 14. 考生 B 未登录不能读 A 的答卷 ---------- */
$t->guard('未登录不能跨考生读卷', function () use ($t) {
    \App\Services\AuthSession::logout();
    $res = Http::get('/api/exam/answer');
    $t->assertSame('登出后查答案 -> 401', 401, $res['status']);
});

Fixture::cleanup();
exit($t->finish());
