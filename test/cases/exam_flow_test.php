<?php

declare(strict_types=1);

/**
 * 正式考试全流程测试（考生端）—— 自包含夹具，不依赖库中既有业务数据。
 *
 * 生命周期（与需求一致）：
 *   开放入场（生成口令，状态仍为未开考）
 *     → 考生在「开考前 10 分钟内」凭口令入场（标记 online，不出题）
 *     → 监考出题（为每位考生随机组卷，状态 paper）
 *     → 到点惰性自动开考 或 监考手动开考（状态 testing）
 *     → 考生进入答题
 *
 * 重点验证 P0-4：考试进行中任何响应都不得包含 quiz_key。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Services\ExamEngine;
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

/* =================== 阶段一：入场 =================== */

/* ---------- 1. 未登录无法取卷 ---------- */
$t->guard('未登录取卷被拒', function () use ($t) {
    $res = Http::get('/api/exam/paper');
    $t->assertSame('未登录取卷 -> 401', 401, $res['status']);
});

/* ---------- 2. 未开放入场（无口令）的考试拒绝入场 ---------- */
$t->guard('未开放入场时拒绝入场', function () use ($t) {
    // exam_pwd 为整型列，未开放入场时取 0（视为无口令）
    $noPwd = Fixture::createExam(['exam_pwd' => '0']);
    if ($noPwd === null) {
        $t->skip('未开放入场时拒绝入场', '夹具创建失败');
        return;
    }
    $res = Http::post('/api/exam/login', [
        'exam_id'  => $noPwd['exam_id'],
        'stu_id'   => $noPwd['stu_a'],
        'password' => Fixture::PWD,
    ]);
    $t->assertSame('未开放入场 -> 403', 403, $res['status']);
    $t->assertTrue('提示未开放入场', str_contains($res['raw'], '尚未开放入场'));
});

/* ---------- 3. 错误的考场口令被拒 ---------- */
$t->guard('错误考场口令被拒', function () use ($t, $examId, $fx) {
    $res = Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $fx['stu_a'],
        'password' => Fixture::PWD,
        'exam_pwd' => '000000',
    ]);
    $t->assertSame('错误口令 -> 403', 403, $res['status']);
});

/* ---------- 4. 正确的准考证号 + 密码 + 口令入场成功（等待室阶段） ---------- */
$t->guard('窗口内凭口令入场成功', function () use ($t, $examId, $fx) {
    $res = Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $fx['stu_a'],
        'password' => Fixture::PWD,
        'exam_pwd' => $fx['exam_pwd'],
    ]);
    $t->assertSame('登录成功 -> 200', 200, $res['status']);
    $data = Http::data($res);
    $t->assertSame('未开考 → 处于等待室', 'waiting', (string) ($data['phase'] ?? ''));

    // 入场不组卷：此时不应有试卷
    $cnt = (int) (\Core\Database::fetch(
        'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $fx['stu_a']]
    )['c'] ?? 0);
    $t->assertSame('入场阶段尚未组卷', 0, $cnt);

    // 入场后标记为在考场
    $row = \Core\Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $fx['stu_a']]
    );
    $t->assertSame('入场后状态 online', 'online', (string) ($row['stu_status'] ?? ''));
});

/* ---------- 5. 开考后未入场者被拒（新规则） ---------- */
$t->guard('开考后无法再入场', function () use ($t) {
    // exam_start 已过 → 入场窗口关闭
    $closed = Fixture::createExam([
        'exam_status' => 'paper',
        'exam_start'  => date('Y-m-d H:i:s', time() - 60),
        'exam_end'    => date('Y-m-d H:i:s', time() + 3600),
    ]);
    if ($closed === null) {
        $t->skip('开考后无法再入场', '夹具创建失败');
        return;
    }
    $res = Http::post('/api/exam/login', [
        'exam_id'  => $closed['exam_id'],
        'stu_id'   => $closed['stu_a'],
        'password' => Fixture::PWD,
        'exam_pwd' => $closed['exam_pwd'],
    ]);
    $t->assertSame('开考后入场 -> 403', 403, $res['status']);
    $t->assertTrue('提示考试已开始', str_contains($res['raw'], '无法进入考场'));
});

/* ---------- 6. 未到入场时间（开考前 10 分钟之外）被拒 ---------- */
$t->guard('未到入场时间被拒', function () use ($t) {
    $future = Fixture::createExam([
        'exam_status' => 'exam',
        'exam_start'  => date('Y-m-d H:i:s', time() + 1800), // 30 分钟后开考
        'exam_end'    => date('Y-m-d H:i:s', time() + 5400),
    ]);
    if ($future === null) {
        $t->skip('未到入场时间被拒', '夹具创建失败');
        return;
    }
    $res = Http::post('/api/exam/login', [
        'exam_id'  => $future['exam_id'],
        'stu_id'   => $future['stu_a'],
        'password' => Fixture::PWD,
        'exam_pwd' => $future['exam_pwd'],
    ]);
    $t->assertSame('未到入场时间 -> 403', 403, $res['status']);
    $t->assertTrue('提示 10 分钟后才可入场', str_contains($res['raw'], '10 分钟'));
});

/* =================== 阶段二：监考出题 =================== */

/* ---------- 7. 出题：为参考班级每位考生随机组卷 ---------- */
$t->guard('出题后状态推进且试卷就绪', function () use ($t, $examId, $fx) {
    // 出题只面向「已进入考场」的考生：A 已凭口令入场，B 必须同样入场才会被出卷。
    \Core\Database::query(
        "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
         VALUES (?, ?, 0, 'online', '') ON DUPLICATE KEY UPDATE stu_status = 'online'",
        [$examId, $fx['stu_b']]
    );

    $exam = (new Exam())->find($examId);
    $r = ExamEngine::generateForClass($examId, $exam);
    $t->assertSame('已入场考生为 2 人', 2, $r['entered']);
    $t->assertSame('新生成 2 份试卷', 2, $r['generated']);
    $t->assertSame('无缺题警告', [], $r['warnings']);

    $cnt = (int) (\Core\Database::fetch(
        'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?',
        [$examId, Fixture::STU_A]
    )['c'] ?? 0);
    $t->assertSame('考生 A 组卷 4 题', 4, $cnt);

    $status = (string) ((new Exam())->find($examId)['exam_status'] ?? '');
    $t->assertSame('出题后状态为已排卷', Exam::STATUS_PAPER, $status);
});

/* ---------- 8. 未开考不得取题 ---------- */
$t->guard('未开考不得取题', function () use ($t) {
    $res = Http::get('/api/exam/paper?paper_id=1');
    $t->assertSame('未开考取卷 -> 409', 409, $res['status']);
    $t->assertSame('状态为等待开考', 'waiting', (string) (Http::data(Http::get('/api/exam/status'))['phase'] ?? ''));
});

/* =================== 阶段三：开考 =================== */

/* ---------- 9. 手动开考保留原口令 ---------- */
$t->guard('手动开考保留入场口令', function () use ($t, $examId, $fx) {
    $pwd = (new Exam())->start($examId);
    $t->assertSame('开考口令未突变', $fx['exam_pwd'], $pwd);
    $t->assertSame('状态置为进行中', Exam::STATUS_TESTING, (string) ((new Exam())->find($examId)['exam_status'] ?? ''));
});

/* ---------- 10. 开考后考生状态轮询进入答题阶段 ---------- */
$t->guard('开考后进入答题阶段', function () use ($t) {
    $res = Http::get('/api/exam/status');
    $t->assertSame('状态可读', 200, $res['status']);
    $data = Http::data($res);
    $t->assertSame('阶段为答题中', 'answering', (string) ($data['phase'] ?? ''));
    $t->assertSame('试卷已就绪', true, (bool) ($data['paper_ready'] ?? false));
});

/* ---------- 11. 每个题型都不能泄露答案（P0-4） ---------- */
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

/* ---------- 12. 题目字段完整性 ---------- */
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

/* ---------- 13. 越界题号 ---------- */
$t->guard('越界题号被拒', function () use ($t) {
    $res = Http::get('/api/exam/paper?paper_id=999');
    $t->assertSame('题号越界 -> 400', 400, $res['status']);
});

/* ---------- 14. CSRF：无令牌不能保存 ---------- */
$t->guard('保存答案需 CSRF 令牌', function () use ($t) {
    $res = Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A']);
    $t->assertSame('无 CSRF -> 419', 419, $res['status']);
});

/* ---------- 15. 逐题保存 + 答题卡状态 ---------- */
$t->guard('保存答案并更新答题卡', function () use ($t) {
    $csrf = \App\Services\AuthSession::csrfToken();
    $r1 = Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('第 1 题保存成功', 200, $r1['status']);
    $t->assertSame('返回下一题号 2', 2, (int) (Http::data($r1)['next_paper_id'] ?? 0));

    $r2 = Http::post('/api/exam/paper/save', ['paper_id' => 2, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('第 2 题保存成功', 200, $r2['status']);
    $nav = Http::data($r2)['navigation'] ?? [];
    $t->assertSame('答题卡已答 2 题', 2, (int) ($nav['done'] ?? -1));
});

/* ---------- 16. 多选题数组形式提交（归一化） ---------- */
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

/* =================== 阶段四：交卷 =================== */

/* ---------- 17. 交卷判分（指定正确答案，验证计分准确） ---------- */
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

    $row = \Core\Database::fetch(
        'SELECT stu_score, stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $fx['stu_a']]
    );
    $t->assertSame('成绩已写入 stuscore', $expected, (int) ($row['stu_score'] ?? -1));
    $t->assertSame('状态已置为 over', 'over', (string) ($row['stu_status'] ?? ''));
});

/* ---------- 18. 交卷后可见答案 ---------- */
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

/* ---------- 19. 重复交卷幂等 ---------- */
$t->guard('重复交卷幂等', function () use ($t) {
    $csrf = \App\Services\AuthSession::csrfToken();
    $res = Http::post('/api/exam/paper/submit', [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('重复交卷仍 200', 200, $res['status']);
    $t->assertTrue('标记已交卷', (Http::data($res)['already_submitted'] ?? false) === true);
});

/* ---------- 20. 交卷后不能再取卷 ---------- */
$t->guard('交卷后禁止取卷', function () use ($t) {
    $res = Http::get('/api/exam/paper?paper_id=1');
    $t->assertSame('交卷后取卷 -> 409', 409, $res['status']);
});

/* ---------- 21. 已交卷考生不能重复入场 ---------- */
$t->guard('已交卷考生不能重复入场', function () use ($t, $examId, $fx) {
    $res = Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $fx['stu_a'],
        'password' => Fixture::PWD,
        'exam_pwd' => $fx['exam_pwd'],
    ]);
    $t->assertSame('已交卷入场 -> 409', 409, $res['status']);
});

/* =================== 阶段五：惰性自动开考 =================== */

/* ---------- 22. 到点自动开考（幂等） ---------- */
$t->guard('到点惰性自动开考', function () use ($t) {
    $due = Fixture::createExam([
        'exam_status' => 'paper',
        'exam_start'  => date('Y-m-d H:i:s', time() - 5),
        'exam_end'    => date('Y-m-d H:i:s', time() + 3600),
    ]);
    if ($due === null) {
        $t->skip('到点惰性自动开考', '夹具创建失败');
        return;
    }
    $id = (int) $due['exam_id'];
    $t->assertSame('自动开考前状态为已排卷', Exam::STATUS_PAPER, (string) ((new Exam())->find($id)['exam_status'] ?? ''));
    $changed = Exam::autoStartIfDue($id);
    $t->assertSame('触发自动开考', true, $changed);
    $t->assertSame('状态推进为进行中', Exam::STATUS_TESTING, (string) ((new Exam())->find($id)['exam_status'] ?? ''));
    $t->assertSame('重复调用幂等', false, Exam::autoStartIfDue($id));
});

/* ---------- 23. 未到点不自动开考 ---------- */
$t->guard('未到点不自动开考', function () use ($t) {
    $notYet = Fixture::createExam([
        'exam_status' => 'paper',
        'exam_start'  => date('Y-m-d H:i:s', time() + 600),
        'exam_end'    => date('Y-m-d H:i:s', time() + 3600),
    ]);
    if ($notYet === null) {
        $t->skip('未到点不自动开考', '夹具创建失败');
        return;
    }
    $t->assertSame('未到点不推进', false, Exam::autoStartIfDue((int) $notYet['exam_id']));
});

/* ---------- 24. 考生 B 未登录不能读 A 的答卷 ---------- */
$t->guard('未登录不能跨考生读卷', function () use ($t) {
    \App\Services\AuthSession::logout();
    $res = Http::get('/api/exam/answer');
    $t->assertSame('登出后查答案 -> 401', 401, $res['status']);
});

Fixture::cleanup();
exit($t->finish());
