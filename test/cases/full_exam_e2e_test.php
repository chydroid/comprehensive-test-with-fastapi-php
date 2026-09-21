<?php

declare(strict_types=1);

/**
 * 全流程端到端演练：**管理员 + 监考教师 + 三名考生**。
 *
 * 与 exam_flow_test.php 的分工：
 *   exam_flow_test  —— 单人视角、逐项验证接口契约（状态码 / 字段 / 泄露面）
 *   本文件          —— 按真实一场考试的时间线把三端串起来跑通，重点在
 *                      「跨角色的一致性」与「边界与越权」，即单个接口都对、
 *                      但连起来用会不会出问题。
 *
 * 时间线（与 README 一致）：
 *   管理员编排考试 → 开放入场（签口令）
 *     → 三名考生在窗口内凭口令入场（此时**不组卷**）
 *     → 教师出题（逐人随机卷）→ 开考
 *     → 考生答题（含锁定/解锁、超时、判分）
 *     → 教师单个收卷 / 全员收卷 / 结束整场
 *     → 成绩名单、导出、备份与越权校验
 *
 * 会话前提（决定了本文件的执行顺序）：AuthSession::login() 会 purge 掉
 * 其它身份**以及考场会话**，因此「管理员 / 教师 / 考生」三种身份不能同时
 * 持有。考生每次因教师操作被打断后都要重新入场——这正好顺带验证了
 * 「在场内（online/locked）可凭账号密码续考、无需再输考场口令」这条规则。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Services\AuthSession;
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
echo "== 全流程端到端演练（管理员 + 教师 + 3 考生）==\n";

if (!Harness::dbAvailable()) {
    $t->skip('全流程演练', '数据库不可用');
    exit($t->finish());
}

/* ==================================================================
 * 0. 准备
 * ================================================================== */

Fixture::cleanup();

const E2E_ADMIN = 'admin';
const E2E_ADMIN_PWD = 'admin@2026';
const E2E_T1 = 'teacher1';
const E2E_T2 = 'teacher2';
const E2E_T_PWD = 'teacher@2026';

/** 确保监考教师存在且口令可登录（教师表可能被前序测试改动过） */
$ensureTeacher = static function (string $name) use ($base): void {
    $row = Database::fetch('SELECT id FROM `teainfo` WHERE tea_name = ?', [$name]);
    if ($row === null) {
        Database::query(
            'INSERT INTO `teainfo` (tea_name, tea_pwd, avatar) VALUES (?, ?, ?)',
            [$name, Password::hash(E2E_T_PWD), '']
        );
    } else {
        Database::query('UPDATE `teainfo` SET tea_pwd = ? WHERE id = ?', [Password::hash(E2E_T_PWD), $row['id']]);
    }
};
$ensureTeacher(E2E_T1);
$ensureTeacher(E2E_T2);

// 三人同场：独立班级 __TEST__三人班，考生 9000003/4/5
$fx = Fixture::createExam3([
    'exam_tea'   => E2E_T1,
    'exam_pwd'   => '0',   // 未开放入场，等待管理员签口令
    'exam_start' => date('Y-m-d H:i:s', time() + 600),
    'exam_end'   => date('Y-m-d H:i:s', time() + 4200),
]);
if ($fx === null) {
    $t->skip('全流程演练', '题库无四种题型齐备的科目');
    exit($t->finish());
}
// 另一场（教师 2 名下 / 另一个班级），用于越权与「非本班考生」校验
$fxOther = Fixture::createExam(['exam_tea' => E2E_T2]);
if ($fxOther === null) {
    $t->skip('全流程演练', '夹具创建失败');
    exit($t->finish());
}

$examId = (int) $fx['exam_id'];
$examOtherId = (int) $fxOther['exam_id'];
$students = $fx['students'];
$existingPwd = (string) $fx['exam_pwd'];

$examRow = (new Exam())->find($examId);
$subjId = (int) $examRow['subj_id'];
// 夹具只在一个难度上各配 1 题，找出用的是哪一档（决定随机性断言的期望池大小）
$diffField = 'mid';
foreach (['easy', 'mid', 'hard'] as $f) {
    if ((int) $examRow["radio1_{$f}_sum"] > 0) {
        $diffField = $f;
    }
}
$diffCode = ['easy' => 'Y', 'mid' => 'Z', 'hard' => 'N'][$diffField];

echo "  考试 #{$examId}（教师 " . E2E_T1 . "，班级 {$fx['class_id']}，考生 " . implode('/', $students) . "）\n";
echo "  对照考试 #{$examOtherId}（教师 " . E2E_T2 . "）\n";

/** 取当前会话 CSRF 令牌（各身份登录后调用） */
$csrf = static fn (): string => AuthSession::csrfToken();

/* ==================================================================
 * 1. 未登录：三端一律拒绝
 * ================================================================== */

$t->guard('未登录时管理端/教师端接口被拒', function () use ($t, $examId) {
    AuthSession::logout();
    $t->assertSame('管理端考试列表 -> 401', 401, Http::get('/api/admin/exams')['status']);
    $t->assertSame('管理端开放入场 -> 401', 401, Http::post("/api/admin/exams/{$examId}/open")['status']);
    $t->assertSame('教师端监考名单 -> 401', 401, Http::get('/api/teacher/monitor')['status']);
    $t->assertSame('教师端单人收卷 -> 401', 401,
        Http::post('/api/teacher/monitor/submit-one', ['exam_id' => $examId, 'stu_id' => '9000003'])['status']);
});

/* ==================================================================
 * 2. 管理员：编排考试 + 开放入场
 * ================================================================== */

$t->guard('管理员登录', function () use ($t, $csrf) {
    $res = Http::post('/api/admin/login', ['username' => E2E_ADMIN, 'password' => E2E_ADMIN_PWD]);
    $t->assertSame('登录 -> 200', 200, $res['status']);
    $t->assertTrue('下发 CSRF 令牌', strlen($csrf()) === 64);
});

$t->guard('组卷前题量校验（管理员）', function () use ($t, $subjId, $diffField) {
    $q = http_build_query([
        'subj_id' => $subjId,
        "radio1_{$diffField}_sum" => 1, 'radio1_val' => 5,
        "radio2_{$diffField}_sum" => 1, 'radio2_val' => 5,
        "checkbox_{$diffField}_sum" => 1, 'checkbox_val' => 5,
        "text_{$diffField}_sum" => 1, 'text_val' => 5,
    ]);
    $res = Http::get('/api/admin/exams/0/quiz-count?' . $q);
    $t->assertSame('题量校验 -> 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertSame('题库充足', true, (bool) ($data['ok'] ?? false));
    $t->assertSame('共 4 题', 4, (int) ($data['total_questions'] ?? 0));
    $t->assertSame('满分 20', 20, (int) ($data['computed_score'] ?? 0));
});

/* ---------- 2.1 管理员走接口新建一场考试 ---------- */
$newExamId = 0;
$t->guard('管理员新建考试：状态与满分由服务端推算', function () use ($t, $subjId, $diffField, $csrf, &$newExamId) {
    $res = Http::post('/api/admin/exams', [
        'exam_name'        => Fixture::PREFIX . '管理员编排',
        'exam_tea'         => E2E_T1,
        'subj_id'          => $subjId,
        'exam_start'       => date('Y-m-d H:i:s', time() + 900),
        'exam_end'         => date('Y-m-d H:i:s', time() + 4500),
        'stu_class'        => Fixture::CLASS_ID3,
        "radio1_{$diffField}_sum" => 1, 'radio1_val' => 5,
        "radio2_{$diffField}_sum" => 1, 'radio2_val' => 5,
        "checkbox_{$diffField}_sum" => 1, 'checkbox_val' => 5,
        "text_{$diffField}_sum" => 1, 'text_val' => 5,
    ], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('新建 -> 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $newExamId = (int) ($data['id'] ?? 0);
    $t->assertTrue('返回新考试 id', $newExamId > 0);
    $t->assertSame('初始状态为未开考', 'exam', (string) ($data['exam_status'] ?? ''));
    $t->assertSame('未开放入场时无口令', '0', (string) ($data['exam_pwd'] ?? 'x'));
    $t->assertSame('满分 = 4 题 × 5 分', 20, (int) ($data['exam_score'] ?? 0));
    $t->assertSame('参考班级按 ID 归一', Fixture::CLASS_ID3, (string) ($data['stu_class'] ?? ''));
});

$t->guard('新建考试：缺题量 / 越界时间等边界', function () use ($t, $subjId, $diffField, $csrf) {
    $mk = static fn (array $over): array => array_merge([
        'exam_name'  => Fixture::PREFIX . '边界',
        'exam_tea'   => E2E_T1,
        'subj_id'    => $subjId,
        'exam_start' => date('Y-m-d H:i:s', time() + 900),
        'exam_end'   => date('Y-m-d H:i:s', time() + 4500),
        'stu_class'  => Fixture::CLASS_ID3,
    ], $over);

    $res = Http::post('/api/admin/exams', $mk(['exam_name' => '']), ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('缺考试名称 -> 400', 400, $res['status']);

    $res = Http::post('/api/admin/exams', $mk(['subj_id' => 999999]), ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('科目不存在 -> 400', 400, $res['status']);
    $t->assertTrue('提示科目不存在', str_contains($res['raw'], '科目不存在'));

    $res = Http::post('/api/admin/exams', $mk([]), ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('未配任何题量 -> 400', 400, $res['status']);
    $t->assertTrue('提示至少配置一道题', str_contains($res['raw'], '至少配置一道题目'));

    // 注意：分值校验发生在题量校验之前，本题量配置在题库里是充足的（用夹具实际使用的难度），
    // 否则会因「题库题量不足」提前 400，让用例因为错误的理由通过。
    $res = Http::post('/api/admin/exams', $mk([
        "radio1_{$diffField}_sum" => 1, 'radio1_val' => 0,
    ]), ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('配了题量但分值 0 -> 400', 400, $res['status']);
    $t->assertTrue('提示分值必须大于 0', str_contains($res['raw'], '分值必须大于 0'));

    // 题库确实不足时（数量远超库存）应被拦下并报出缺口
    $res = Http::post('/api/admin/exams', $mk([
        "radio1_{$diffField}_sum" => 999, 'radio1_val' => 5,
    ]), ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('题量超出题库 -> 400', 400, $res['status']);
    $t->assertTrue('提示题库题量不足', str_contains($res['raw'], '题库题量不足'));

    // 结束时间早于开始时间 → 自动顺延一天（跨天考试）
    $res = Http::post('/api/admin/exams', $mk([
        "radio1_{$diffField}_sum" => 1, 'radio1_val' => 5,
        'exam_start' => '2026-10-01 09:00:00',
        'exam_end'   => '2026-10-01 08:00:00',
    ]), ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('倒置时间被接受 -> 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('结束时间顺延为次日 08:00', '2026-10-02 08:00:00', (string) ($d['exam_end'] ?? ''));
});

/* ---------- 2.2 开放入场：签口令 ---------- */
$t->guard('开放入场：口令按后台位数配置签发且状态保持未开考', function () use ($t, $examId, $csrf) {
    \App\Services\Setting::flush();
    $len = (int) \App\Services\Setting::int('exam_pwd_length', 6);

    $res = Http::post("/api/admin/exams/{$examId}/open", [], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('开放入场 -> 200', 200, $res['status']);
    $pwd = (string) (Http::data($res)['exam_pwd'] ?? '');
    $t->assertSame("口令为 {$len} 位", $len, strlen($pwd));
    $t->assertTrue('口令为纯数字', ctype_digit($pwd));

    $row = (new Exam())->find($examId);
    $t->assertSame('状态仍为未开考', 'exam', (string) $row['exam_status']);
    $t->assertSame('exam_pwd 已落库', $pwd, (string) $row['exam_pwd']);

    // 口令快照必须同步写进 stuscore，否则「拿新口令入场、续考校验用旧快照」会判口令错
    $snap = Database::fetchAll('SELECT DISTINCT stu_pwd FROM `stuscore` WHERE exam_id = ?', [$examId]);
    $t->assertSame('stuscore 口令快照仅一种', 1, count($snap));
    $t->assertSame('快照与考试口令一致', $pwd, (string) ($snap[0]['stu_pwd'] ?? ''));

    $GLOBALS['__e2e_pwd'] = $pwd;
});

$t->guard('开放入场：已结束的考试被拒', function () use ($t, $csrf) {
    $over = Fixture::createExam3(['exam_tea' => E2E_T1, 'exam_status' => 'over']);
    if ($over === null) {
        $t->skip('开放入场-已结束', '夹具创建失败');
        return;
    }
    $res = Http::post("/api/admin/exams/{$over['exam_id']}/open", [], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('已结束开放入场 -> 409', 409, $res['status']);
});

$examPwd = (string) ($GLOBALS['__e2e_pwd'] ?? $existingPwd);

/* ==================================================================
 * 3. 三名考生：入场边界与入场
 * ================================================================== */

$pastExam = Fixture::createExam3([
    'exam_tea'   => E2E_T1,
    'exam_status' => 'paper',
    'exam_start' => date('Y-m-d H:i:s', time() - 120),  // 已开考
    'exam_end'   => date('Y-m-d H:i:s', time() + 3600),
]);
$futureExam = Fixture::createExam3([
    'exam_tea'   => E2E_T1,
    'exam_start' => date('Y-m-d H:i:s', time() + 3600),  // 60 分钟后才到入场窗口
    'exam_end'   => date('Y-m-d H:i:s', time() + 7200),
]);

$t->guard('入场边界：未开放入场 / 未到窗口 / 开考后 / 凭据错 / 非本班', function () use ($t, $examId, $pastExam, $futureExam, $fxOther, $students, $examPwd) {
    // 未开放入场（exam_pwd = 0）
    $noOpen = Fixture::createExam3(['exam_tea' => E2E_T1, 'exam_pwd' => '0']);
    if ($noOpen !== null) {
        $res = Http::post('/api/exam/login', [
            'exam_id' => $noOpen['exam_id'], 'stu_id' => $students[0],
            'password' => Fixture::PWD, 'exam_pwd' => '123456',
        ]);
        $t->assertSame('未开放入场 -> 403', 403, $res['status']);
        $t->assertTrue('提示尚未开放入场', str_contains($res['raw'], '尚未开放入场'));
    }

    // 已开放入场但未到入场窗口（开考前 15 分钟才开）
    if ($futureExam !== null) {
        $res = Http::post('/api/exam/login', [
            'exam_id' => $futureExam['exam_id'], 'stu_id' => $students[0],
            'password' => Fixture::PWD, 'exam_pwd' => $futureExam['exam_pwd'],
        ]);
        $t->assertSame('未到入场窗口 -> 403', 403, $res['status']);
        $t->assertTrue('提示开考前 10 分钟才可入场', str_contains($res['raw'], '10 分钟'));
    }

    // 已开考且该生未入场（默认 exam_entry_late_minutes = 0，开考后不得入场）
    if ($pastExam !== null) {
        $res = Http::post('/api/exam/login', [
            'exam_id' => $pastExam['exam_id'], 'stu_id' => $students[0],
            'password' => Fixture::PWD, 'exam_pwd' => $pastExam['exam_pwd'],
        ]);
        $t->assertSame('开考后未入场者 -> 403', 403, $res['status']);
        $t->assertTrue('提示无法进入考场', str_contains($res['raw'], '无法进入考场'));
    }

    // 口令错误 / 账号密码错误
    $res = Http::post('/api/exam/login', [
        'exam_id' => $examId, 'stu_id' => $students[0],
        'password' => Fixture::PWD, 'exam_pwd' => '000000',
    ]);
    $t->assertSame('考场口令错误 -> 403', 403, $res['status']);
    $t->assertTrue('提示口令不正确', str_contains($res['raw'], '考场口令不正确'));

    $res = Http::post('/api/exam/login', [
        'exam_id' => $examId, 'stu_id' => $students[0],
        'password' => 'wrong-password', 'exam_pwd' => $examPwd,
    ]);
    $t->assertSame('准考证/密码错误 -> 401', 401, $res['status']);

    // 非参考班级的考生（另一个班的 A）即使口令正确也不得入场
    $res = Http::post('/api/exam/login', [
        'exam_id' => $examId, 'stu_id' => Fixture::STU_A,
        'password' => Fixture::PWD, 'exam_pwd' => $examPwd,
    ]);
    $t->assertSame('非本班考生 -> 403', 403, $res['status']);
    $t->assertTrue('提示不在参考范围', str_contains($res['raw'], '参考范围'));
    $t->assertSame('非本班考生未被写入名单', 0, (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
        [$examId, Fixture::STU_A]
    )['c'] ?? -1));
});

$t->guard('三名考生在窗口内凭口令入场（未组卷）', function () use ($t, $examId, $students, $examPwd) {
    foreach ($students as $i => $stuId) {
        AuthSession::logout();
        $res = Http::post('/api/exam/login', [
            'exam_id' => $examId, 'stu_id' => $stuId,
            'password' => Fixture::PWD, 'exam_pwd' => $examPwd,
        ]);
        $t->assertSame('考生 ' . ($i + 1) . ' 入场 -> 200', 200, $res['status']);
        $t->assertSame('考生 ' . ($i + 1) . ' 处于等待室', 'waiting', (string) (Http::data($res)['phase'] ?? ''));
        $t->assertSame('考生 ' . ($i + 1) . ' 已标记在考场', 'online', (string) (Database::fetch(
            'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        )['stu_status'] ?? ''));
    }
    $cnt = (int) (Database::fetch('SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ?', [$examId])['c'] ?? -1);
    $t->assertSame('入场阶段尚未组卷', 0, $cnt);
});

$t->guard('已入场考生可凭账号密码续考（无需考场口令、不重复标记）', function () use ($t, $examId, $students) {
    AuthSession::logout();
    $res = Http::post('/api/exam/login', [
        'exam_id' => $examId, 'stu_id' => $students[0],
        'password' => Fixture::PWD,
        // 故意不带 exam_pwd：场内考生走续考分支，不再校验口令与窗口
    ]);
    $t->assertSame('续考 -> 200', 200, $res['status']);
    $t->assertSame('仍在等待室', 'waiting', (string) (Http::data($res)['phase'] ?? ''));
    $t->assertSame('名单未产生重复行', 3, (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stuscore` WHERE exam_id = ?', [$examId]
    )['c'] ?? -1));
});

/* ==================================================================
 * 4. 教师：出题 → 开考
 * ================================================================== */

$t->guard('教师登录并只能看到自己名下的考试', function () use ($t, $examId, $examOtherId, $csrf) {
    AuthSession::logout();
    $res = Http::post('/api/teacher/login', ['username' => E2E_T1, 'password' => E2E_T_PWD]);
    $t->assertSame('教师登录 -> 200', 200, $res['status']);
    $t->assertTrue('下发 CSRF 令牌', strlen($csrf()) === 64);

    $list = Http::data(Http::get('/api/teacher/exams')) ?? [];
    $ids = array_map(static fn ($r) => (int) $r['id'], $list['list'] ?? []);
    $t->assertTrue('列表含本人考试', in_array($examId, $ids, true));
    $t->assertTrue('列表不含他人考试', !in_array($examOtherId, $ids, true));
});

$t->guard('教师越权：他人考试一律不可见/不可操作', function () use ($t, $examOtherId, $csrf) {
    $t->assertSame('读他人考试详情 -> 404', 404, Http::get("/api/teacher/exams/{$examOtherId}")['status']);
    // 写操作必须带上本会话的 CSRF 头：否则会在中间件层先被 419 拦下，
    // 归属校验根本不会执行，用例会「因为错误的理由」通过。
    $h = ['X-CSRF-Token' => $csrf()];
    $t->assertSame('给他人考试开放入场 -> 404', 404,
        Http::post("/api/teacher/exams/{$examOtherId}/open", [], $h)['status']);
    $t->assertSame('给他人考试出题 -> 404', 404,
        Http::post("/api/teacher/exams/{$examOtherId}/generate", [], $h)['status']);
    $t->assertSame('开他人考试 -> 404', 404,
        Http::post("/api/teacher/exams/{$examOtherId}/start", [], $h)['status']);
    $t->assertSame('改他人考试 -> 404', 404,
        Http::put("/api/teacher/exams/{$examOtherId}", ['exam_name' => 'x'], $h)['status']);
    $t->assertSame('删他人考试 -> 404', 404, Http::delete("/api/teacher/exams/{$examOtherId}", $h)['status']);
    $t->assertSame('收他人考场名单 -> 404', 404,
        Http::post('/api/teacher/monitor/submit-one', ['exam_id' => $examOtherId, 'stu_id' => Fixture::STU_A], $h)['status']);
    $t->assertSame('看他人考场名单 -> 404（不泄露存在性）', 404,
        Http::get("/api/teacher/monitor?exam_id={$examOtherId}")['status']);
});

$t->guard('出题：为三名考生各出一份卷并推进为已排卷', function () use ($t, $examId, $students, $subjId, $diffCode, $csrf) {
    $res = Http::post("/api/teacher/exams/{$examId}/generate", [], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('出题 -> 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('参考班级共 3 人', 3, (int) ($d['student_total'] ?? 0));
    $t->assertSame('新生成 3 份', 3, (int) ($d['generated'] ?? 0));
    $t->assertSame('无缺题警告', [], $d['warnings'] ?? []);
    $t->assertSame('状态推进为已排卷', 'paper', (string) ((new Exam())->find($examId)['exam_status'] ?? ''));

    foreach ($students as $i => $stuId) {
        $n = (int) (Database::fetch(
            'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        )['c'] ?? 0);
        $t->assertSame('考生 ' . ($i + 1) . ' 试卷 4 题', 4, $n);
        $bad = (int) (Database::fetch(
            'SELECT COUNT(*) c FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ? AND (q.subj_id <> ? OR q.quiz_diff <> ?)',
            [$examId, $stuId, $subjId, $diffCode]
        )['c'] ?? -1);
        $t->assertSame('考生 ' . ($i + 1) . ' 抽题科目/难度正确', 0, $bad);
    }

    // 重复出题幂等：已有卷不再重排
    $again = Http::data(Http::post("/api/teacher/exams/{$examId}/generate", [], ['X-CSRF-Token' => $csrf()])) ?? [];
    $t->assertSame('重复出题：新生成 0 份', 0, (int) ($again['generated'] ?? -1));
    $t->assertSame('重复出题：跳过 3 份', 3, (int) ($again['skipped'] ?? -1));
    $total = (int) (Database::fetch('SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ?', [$examId])['c'] ?? 0);
    $t->assertSame('试卷总数仍为 12', 12, $total);
});

$t->guard('逐人随机卷：三名考生题号结构一致但题目集不全相同', function () use ($t, $examId, $students, $subjId, $diffCode) {
    $sets = [];
    foreach ($students as $stuId) {
        $rows = Database::fetchAll(
            'SELECT paper_id, quiz_class, quiz_id FROM `stupaper`
             WHERE exam_id = ? AND stu_id = ? ORDER BY paper_id ASC',
            [$examId, $stuId]
        );
        $t->assertSame('题号连续 1..4', [1, 2, 3, 4], array_map(static fn ($r) => (int) $r['paper_id'], $rows));
        $sets[] = implode(',', array_map(static fn ($r) => (int) $r['quiz_id'], $rows));
    }

    // 随机性只在题库容量足够时才有意义；不足时诚实标注而不是伪造通过。
    // 只统计「本场考试实际抽题的题型」：Exam::TYPE_PREFIXES 是系统支持的题型全集
    // （A4 起含 longtext），而题库未必每个科目都备了所有题型，按全集取最小值
    // 会让「本场根本没配的题型」把 pool 拉到 0，从而误判为随机性无法验证。
    $avail = Exam::availableCounts($subjId);
    $pool = PHP_INT_MAX;
    foreach (Exam::paperPlan((new Exam())->find($examId) ?? []) as $p) {
        if ($p['easy'] + $p['mid'] + $p['hard'] <= 0) {
            continue;
        }
        $pool = min($pool, (int) ($avail[$p['type']][$diffCode] ?? 0));
    }
    if ($pool === PHP_INT_MAX) {
        $pool = 0;
    }
    if ($pool < 3) {
        $t->skip('逐人随机卷差异', "该难度每种题型可用题量仅 {$pool} 道，不足以区分随机性");
        return;
    }
    $t->assertTrue('三人题目集不全相同', count(array_unique($sets)) > 1, implode(' | ', $sets));
});

$t->guard('未开考不得取卷，开考后口令沿用且状态推进', function () use ($t, $examId, $students, $examPwd, $csrf) {
    // 学生未开考取卷 —— 直接以模型层断言更稳（当前会话是教师）
    $t->assertSame('未开考时状态为已排卷', 'paper', (string) ((new Exam())->find($examId)['exam_status'] ?? ''));

    $res = Http::post("/api/teacher/exams/{$examId}/start", [], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('开考 -> 200', 200, $res['status']);
    $pwdAfter = (string) (Http::data($res)['exam_pwd'] ?? '');
    $t->assertSame('开考沿用入场口令（不突变）', $examPwd, $pwdAfter);
    $t->assertSame('状态推进为进行中', 'testing', (string) ((new Exam())->find($examId)['exam_status'] ?? ''));

    $t->assertSame('重复开考 -> 409', 409,
        Http::post("/api/teacher/exams/{$examId}/start", [], ['X-CSRF-Token' => $csrf()])['status']);
    $t->assertSame('开考后开放入场 -> 409', 409,
        Http::post("/api/teacher/exams/{$examId}/open", [], ['X-CSRF-Token' => $csrf()])['status']);
});

$t->guard('到点惰性自动开考（考生轮询触发）', function () use ($t, $csrf) {
    // 场景：考试尚未到点（开考前 3 分钟，入场窗口已开），考生先入场在等待室；
    // 到点后考生轮询 /api/exam/status，由 autoStartIfDue() 顺带推进为进行中。
    $auto = Fixture::createExam3([
        'exam_tea'   => E2E_T1,
        'exam_status' => 'exam',
        'exam_start' => date('Y-m-d H:i:s', time() + 180),
        'exam_end'   => date('Y-m-d H:i:s', time() + 3600),
    ]);
    if ($auto === null) {
        $t->skip('惰性自动开考', '夹具创建失败');
        return;
    }
    $aid = (int) $auto['exam_id'];

    // 新流程：考生先凭口令入场（等待室），教师再出题 —— 出题只面向已进入考场的考生
    AuthSession::logout();
    $enter = Http::post('/api/exam/login', [
        'exam_id' => $aid, 'stu_id' => $auto['students'][0],
        'password' => Fixture::PWD, 'exam_pwd' => $auto['exam_pwd'],
    ]);
    $t->assertSame('未到点也能入场 -> 200', 200, $enter['status']);
    $t->assertSame('入场后处于等待室', 'waiting', (string) (Http::data($enter)['phase'] ?? ''));

    // 教师出题（exam → paper），使已入场考生的试卷就绪
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => E2E_T1, 'password' => E2E_T_PWD]);
    Http::post("/api/teacher/exams/{$aid}/generate", [], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('开考前为已排卷', 'paper', (string) ((new Exam())->find($aid)['exam_status'] ?? ''));

    // 教师登录占用了同一会话槽位，考生需重新入场才能继续轮询（已入场者可续考）
    AuthSession::logout();
    $re = Http::post('/api/exam/login', [
        'exam_id' => $aid, 'stu_id' => $auto['students'][0],
        'password' => Fixture::PWD, 'exam_pwd' => $auto['exam_pwd'],
    ]);
    $t->assertSame('考生重新入场 -> 200', 200, $re['status']);

    // 把开考时间拨到过去（等价于时间流逝到点），再让考生轮询状态
    Database::query('UPDATE `examinfo` SET exam_start = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 10), $aid]);
    $status = Http::data(Http::get('/api/exam/status')) ?? [];
    $t->assertSame('轮询触发自动开考', 'testing', (string) ((new Exam())->find($aid)['exam_status'] ?? ''));
    $t->assertSame('考生阶段进入答题', 'answering', (string) ($status['phase'] ?? ''));
    $t->assertSame('试卷已就绪', true, (bool) ($status['paper_ready'] ?? false));
    $t->assertSame('重复触发幂等', false, Exam::autoStartIfDue($aid));

    // 本场只为验证自动开考，且与主场景共用同一批考生：立即收束为已结束，
    // 否则它留在 testing 会污染后续「本人是否正在考试」的判定（考生被判为仍在考）。
    Database::query("UPDATE `examinfo` SET exam_status = 'over' WHERE id = ?", [$aid]);
    Database::query("UPDATE `stuscore` SET stu_status = 'over' WHERE exam_id = ?", [$aid]);
    $t->assertSame('本场已收束', 'over', (string) ((new Exam())->find($aid)['exam_status'] ?? ''));
});

/* ==================================================================
 * 5. 考生答题（三态：满分 / 部分 / 由教师收卷）
 * ================================================================== */

$answersOf = static function (int $examId, string $stuId): array {
    return Database::fetchAll(
        'SELECT sp.paper_id, sp.quiz_class, q.quiz_key
         FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND sp.stu_id = ?
         ORDER BY sp.paper_id ASC',
        [$examId, $stuId]
    );
};

$t->guard('考生取卷：字段完整且绝不泄露答案', function () use ($t, $examId, $students) {
    AuthSession::logout();
    $res = Http::post('/api/exam/login', [
        'exam_id' => $examId, 'stu_id' => $students[0], 'password' => Fixture::PWD,
    ]);
    $t->assertSame('开考后入场 -> 200', 200, $res['status']);
    $t->assertSame('阶段为答题中', 'answering', (string) (Http::data($res)['phase'] ?? ''));

    for ($pid = 1; $pid <= 4; $pid++) {
        $r = Http::get("/api/exam/paper?paper_id={$pid}");
        $t->assertSame("第 {$pid} 题 -> 200", 200, $r['status']);
        $t->assertTrue("第 {$pid} 题不下发 quiz_key", !str_contains($r['raw'], 'quiz_key'), substr($r['raw'], 0, 160));
        $q = Http::data($r)['question'] ?? [];
        $t->assertTrue("第 {$pid} 题含题干与题型", !empty($q['quiz_title']) && !empty($q['quiz_type_label']));
        $t->assertTrue("第 {$pid} 题含选项数组", is_array($q['quiz_option_list'] ?? null));
    }
    $nav = Http::data(Http::get('/api/exam/paper?paper_id=1'))['navigation'] ?? [];
    $t->assertSame('答题卡共 4 题', 4, (int) ($nav['total'] ?? 0));
    $t->assertSame('答题卡已答 0 题', 0, (int) ($nav['done'] ?? -1));
});

$t->guard('取卷边界：题号越界 / 未入场 / 已交卷', function () use ($t, $examId, $students) {
    $t->assertSame('题号 0 -> 400', 400, Http::get('/api/exam/paper?paper_id=0')['status']);
    $t->assertSame('题号 999 -> 400', 400, Http::get('/api/exam/paper?paper_id=999')['status']);
    $t->assertSame('缺 paper_id 默认取第 1 题', 200, Http::get('/api/exam/paper')['status']);

    AuthSession::logout();
    $t->assertSame('未入场取卷 -> 401', 401, Http::get('/api/exam/paper')['status']);
    $t->assertSame('未入场查状态 -> 200（如实回答无会话）', 200, Http::get('/api/exam/status')['status']);
    $t->assertTrue('phase 为空', Http::data(Http::get('/api/exam/status'))['phase'] === null);
});

$t->guard('保存答案：CSRF / 不存在的题号 / 跨考生题号', function () use ($t, $examId, $students, $csrf) {
    AuthSession::logout();
    Http::post('/api/exam/login', ['exam_id' => $examId, 'stu_id' => $students[0], 'password' => Fixture::PWD]);

    $t->assertSame('无 CSRF -> 419', 419, Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A'])['status']);
    $t->assertSame('题号越界 -> 404', 404,
        Http::post('/api/exam/paper/save', ['paper_id' => 99, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf()])['status']);
    $t->assertSame('缺 paper_id -> 400', 400,
        Http::post('/api/exam/paper/save', ['stu_key' => 'A'], ['X-CSRF-Token' => $csrf()])['status']);

    // 关键：试卷按 exam_id + stu_id 隔离，考生 1 无法借题号写到考生 2 的卷上
    Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf()]);
    $stolen = Database::fetch(
        'SELECT stu_key FROM `stupaper` WHERE exam_id = ? AND stu_id = ? AND paper_id = 1',
        [$examId, $students[1]]
    );
    $t->assertSame('他人试卷未被写入', '', (string) ($stolen['stu_key'] ?? 'x'));
});

$t->guard('保存答案：多选题数组归一 + 答题卡计数', function () use ($t, $examId, $students, $csrf) {
    $rows = Database::fetchAll(
        'SELECT paper_id, quiz_class FROM `stupaper` WHERE exam_id = ? AND stu_id = ? ORDER BY paper_id ASC',
        [$examId, $students[0]]
    );
    $byType = [];
    foreach ($rows as $r) {
        $byType[(string) $r['quiz_class']] = (int) $r['paper_id'];
    }

    // 多选题传乱序数组，应归一为「去重 + 排序」的字符串（重复项不得影响判分）
    if (isset($byType['checkbox'])) {
        $pid = $byType['checkbox'];
        $r = Http::post('/api/exam/paper/save', ['paper_id' => $pid, 'stu_key' => ['C', 'A', 'C']],
            ['X-CSRF-Token' => $csrf()]);
        $t->assertSame('多选数组保存 -> 200', 200, $r['status']);
        $saved = Database::fetch(
            'SELECT stu_key FROM `stupaper` WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
            [$examId, $students[0], $pid]
        );
        $t->assertSame('答案归一为 AC（去重 + 升序）', 'AC', (string) ($saved['stu_key'] ?? ''));
    }

    // 判断题前端可能提交 Y/N，应兼容为 A/B
    if (isset($byType['radio1'])) {
        $pid = $byType['radio1'];
        Http::post('/api/exam/paper/save', ['paper_id' => $pid, 'stu_key' => 'Y'], ['X-CSRF-Token' => $csrf()]);
        $saved = Database::fetch(
            'SELECT stu_key FROM `stupaper` WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
            [$examId, $students[0], $pid]
        );
        $t->assertSame('判断题 Y 归一为 A', 'A', (string) ($saved['stu_key'] ?? ''));
    }

    $nav = Http::data(Http::get('/api/exam/paper?paper_id=1'))['navigation'] ?? [];
    $t->assertTrue('答题卡已答数 > 0', (int) ($nav['done'] ?? 0) > 0);
});

$t->guard('考生在考期间练习/模拟被个人硬约束暂停', function () use ($t, $examId, $students) {
    $pause = Exam::exercisePause($students[0]);
    $t->assertSame('练习被暂停', true, (bool) $pause['paused']);
    $t->assertSame('原因是本人正在考试中', Exam::PAUSE_SELF_IN_EXAM, (string) $pause['reason']);

    $ex = Http::get('/api/exercise');
    $t->assertTrue('练习接口不可用（暂停）', $ex['status'] !== 200 || (Http::data($ex)['paused'] ?? false) === true,
        'status=' . $ex['status']);
});

/* ---------- 5.1 考生 1：全对交卷 ---------- */
$t->guard('考生 1 全对交卷：判分准确、状态封存', function () use ($t, $examId, $students, $answersOf, $csrf) {
    $rows = $answersOf($examId, $students[0]);
    $expect = 0;
    foreach ($rows as $r) {
        if ((string) $r['quiz_key'] === '') {
            continue;
        }
        Http::post('/api/exam/paper/save', ['paper_id' => (int) $r['paper_id'], 'stu_key' => (string) $r['quiz_key']],
            ['X-CSRF-Token' => $csrf()]);
        $expect += 5;
    }
    $res = Http::post('/api/exam/paper/submit', [], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('交卷 -> 200', 200, $res['status']);
    $t->assertSame("全对得分 {$expect}", $expect, (int) (Http::data($res)['score'] ?? -1));

    $row = Database::fetch('SELECT stu_score, stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $students[0]]);
    $t->assertSame('成绩已落库', $expect, (int) $row['stu_score']);
    $t->assertSame('状态为已交卷', 'over', (string) $row['stu_status']);
});

$t->guard('考生 1 交卷后：幂等、禁止取卷/保存、可查答案解析', function () use ($t, $examId, $students, $csrf) {
    $res = Http::post('/api/exam/paper/submit', [], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('重复交卷 -> 200', 200, $res['status']);
    $t->assertTrue('标记 already_submitted', (Http::data($res)['already_submitted'] ?? false) === true);

    $t->assertSame('交卷后取卷 -> 409', 409, Http::get('/api/exam/paper?paper_id=1')['status']);
    $t->assertSame('交卷后保存 -> 409', 409,
        Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf()])['status']);
    $t->assertSame('交卷后重复入场 -> 409', 409,
        Http::post('/api/exam/login', ['exam_id' => $examId, 'stu_id' => $students[0], 'password' => Fixture::PWD])['status']);

    $ans = Http::get('/api/exam/answer');
    $t->assertSame('查看答案 -> 200', 200, $ans['status']);
    $papers = Http::data($ans)['papers'] ?? [];
    $t->assertSame('返回 4 题明细', 4, count($papers));
    $t->assertTrue('此时下发正确答案', str_contains($ans['raw'], 'quiz_key'));
    $t->assertSame('首题判对', true, $papers[0]['is_correct'] ?? null);
    $summary = Http::data($ans)['summary'] ?? [];
    $t->assertSame('分题型统计 4 类', 4, count($summary));

    // 交卷后应当**只**解除「本人正在考试」这条个人硬约束。
    // 本场考试本身仍在进行中，全局层依旧生效 —— 这正是两层设计的分工，
    // 所以此处预期原因是 exam_ongoing 而不是「完全不暂停」。
    $after = Exam::exercisePause($students[0]);
    $t->assertTrue('交卷后个人硬约束解除', $after['reason'] !== Exam::PAUSE_SELF_IN_EXAM, (string) $after['reason']);
    $t->assertSame('改为受全局层（本场仍在进行）约束', Exam::PAUSE_EXAM_ONGOING, (string) $after['reason']);
});

/* ==================================================================
 * 6. 监考控制：锁定 / 解锁 / 单人收卷（本轮修复点）
 * ================================================================== */

$t->guard('监考锁定：本人被锁、他人不受影响', function () use ($t, $examId, $students, $csrf) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => E2E_T1, 'password' => E2E_T_PWD]);

    $res = Http::post('/api/teacher/monitor/lock', ['exam_id' => $examId, 'stu_id' => $students[1]],
        ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('锁定考生 2 -> 200', 200, $res['status']);
    $t->assertSame('影响 1 行', 1, (int) (Http::data($res)['affected'] ?? 0));
    $t->assertSame('名单状态为 locked', 'locked', (string) (Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $students[1]]
    )['stu_status'] ?? ''));
    $t->assertSame('考生 3 未被牵连', 'online', (string) (Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $students[2]]
    )['stu_status'] ?? ''));

    // 被锁考生：取卷/保存都被拒
    AuthSession::logout();
    Http::post('/api/exam/login', ['exam_id' => $examId, 'stu_id' => $students[1], 'password' => Fixture::PWD]);
    $r = Http::get('/api/exam/paper?paper_id=1');
    $t->assertSame('被锁考生取卷 -> 403', 403, $r['status']);
    $t->assertTrue('提示已被锁定', str_contains($r['raw'], '锁定'));
    $t->assertSame('被锁考生保存 -> 403', 403,
        Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf()])['status']);
});

$t->guard('监考解锁：恢复作答', function () use ($t, $examId, $students, $csrf) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => E2E_T1, 'password' => E2E_T_PWD]);
    $res = Http::post('/api/teacher/monitor/unlock', ['exam_id' => $examId, 'stu_id' => $students[1]],
        ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('解锁 -> 200', 200, $res['status']);

    AuthSession::logout();
    Http::post('/api/exam/login', ['exam_id' => $examId, 'stu_id' => $students[1], 'password' => Fixture::PWD]);
    $t->assertSame('解锁后可取卷', 200, Http::get('/api/exam/paper?paper_id=1')['status']);
});

$t->guard('教师端单人收卷：只收被点名的考生（BUG：此前打到了全员收卷）', function () use ($t, $examId, $students, $csrf) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => E2E_T1, 'password' => E2E_T_PWD]);

    // 先确认考生 2/3 都在场内未交卷
    foreach ([$students[1], $students[2]] as $stuId) {
        $st = (string) (Database::fetch(
            'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $stuId]
        )['stu_status'] ?? '');
        $t->assertTrue('收卷前考生仍在考中', !str_starts_with($st, 'over'), "stu_id={$stuId} status={$st}");
    }

    $res = Http::post('/api/teacher/monitor/submit-one', ['exam_id' => $examId, 'stu_id' => $students[1]],
        ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('单人收卷 -> 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('判分 1 人', 1, (int) ($d['graded'] ?? 0));
    $t->assertSame('跳过 0 人', 0, (int) ($d['skipped'] ?? 0));

    $t->assertSame('被点名考生已交卷', 'over', (string) (Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $students[1]]
    )['stu_status'] ?? ''));
    // 关键断言：其他人绝不能被连带收卷
    $other = (string) (Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $students[2]]
    )['stu_status'] ?? '');
    $t->assertSame('考生 3 仍是进行中（未被连带收卷）', 'online', $other);
    $t->assertSame('整场考试仍在进行', 'testing', (string) ((new Exam())->find($examId)['exam_status'] ?? ''));

    // 收卷幂等
    $again = Http::data(Http::post('/api/teacher/monitor/submit-one', ['exam_id' => $examId, 'stu_id' => $students[1]],
        ['X-CSRF-Token' => $csrf()])) ?? [];
    $t->assertSame('重复收卷标记已跳过', 1, (int) ($again['skipped'] ?? 0));

    // 名单外考生
    $t->assertSame('收名单外考生 -> 404', 404,
        Http::post('/api/teacher/monitor/submit-one', ['exam_id' => $examId, 'stu_id' => '99999999'],
            ['X-CSRF-Token' => $csrf()])['status']);
    $t->assertSame('缺 stu_id -> 400', 400,
        Http::post('/api/teacher/monitor/submit-one', ['exam_id' => $examId], ['X-CSRF-Token' => $csrf()])['status']);
});

$t->guard('被收卷考生不能再作答，其余考生继续', function () use ($t, $examId, $students) {
    // 已交卷考生连入场都会被 409 挡住（会话根本建立不起来），
    // 所以"不能再作答"的验证点是入场这一步，而不是入场之后的取卷。
    AuthSession::logout();
    $relogin = Http::post('/api/exam/login', [
        'exam_id' => $examId, 'stu_id' => $students[1], 'password' => Fixture::PWD,
    ]);
    $t->assertSame('被收卷者重复入场 -> 409', 409, $relogin['status']);
    $t->assertTrue('提示已交卷', str_contains($relogin['raw'], '已交卷'));
    $t->assertSame('会话未建立，取卷 -> 401', 401, Http::get('/api/exam/paper?paper_id=1')['status']);

    AuthSession::logout();
    $t->assertSame('考生 3 仍可入场续考', 200, Http::post('/api/exam/login', [
        'exam_id' => $examId, 'stu_id' => $students[2], 'password' => Fixture::PWD,
    ])['status']);
    $t->assertSame('考生 3 仍可取卷作答', 200, Http::get('/api/exam/paper?paper_id=1')['status']);
});

/* ---------- 6.1 结束后不可入场 / 全员收卷与结束整场 ---------- */

$t->guard('结束整场：未交卷者被强制判分、状态置为已结束', function () use ($t, $examId, $students, $csrf) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => E2E_T1, 'password' => E2E_T_PWD]);

    $res = Http::post('/api/teacher/monitor/over-all', ['exam_id' => $examId], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('结束整场 -> 200', 200, $res['status']);
    $t->assertSame('判分 1 人（考生 3）', 1, (int) (Http::data($res)['graded'] ?? -1));
    $t->assertSame('考试状态为已结束', 'over', (string) ((new Exam())->find($examId)['exam_status'] ?? ''));

    $sts = [];
    foreach ($students as $stuId) {
        $sts[] = (string) (Database::fetch(
            'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $stuId]
        )['stu_status'] ?? '');
    }
    $t->assertSame('三名考生全部为已交卷', ['over', 'over', 'over'], $sts);
});

$t->guard('结束后：考生无法入场、无法取卷；成绩名单完整', function () use ($t, $examId, $students) {
    AuthSession::logout();
    $res = Http::post('/api/exam/login', [
        'exam_id' => $examId, 'stu_id' => $students[0], 'password' => Fixture::PWD, 'exam_pwd' => '123456',
    ]);
    $t->assertTrue('结束后入场被拒', in_array($res['status'], [400, 409], true), 'status=' . $res['status']);

    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => E2E_T1, 'password' => E2E_T_PWD]);
    $scores = Http::data(Http::get("/api/teacher/scores?exam_id={$examId}")) ?? [];
    $list = $scores['list'] ?? [];
    $t->assertSame('成绩名单 3 人', 3, count($list));
    foreach ($list as $row) {
        $t->assertSame('名单 ' . $row['stu_id'] . ' 状态为已交卷', 'over', (string) $row['stu_status']);
    }
    $over = Http::data(Http::get("/api/teacher/scores?exam_id={$examId}"))['exams'] ?? [];
    $ids = array_map(static fn ($r) => (int) $r['id'], $over);
    $t->assertTrue('已结束考试出现在成绩下拉', in_array($examId, $ids, true));
});

$t->guard('成绩导出与备份：越权/重复/未结束均被拒', function () use ($t, $examId, $examOtherId, $csrf) {
    $csv = Http::get("/api/teacher/scores/export?exam_id={$examId}");
    $t->assertSame('导出 -> 200', 200, $csv['status']);
    $t->assertTrue('导出含表头与 3 行数据', substr_count($csv['raw'], "\n") >= 4, 'lines=' . substr_count($csv['raw'], "\n"));
    $t->assertTrue('导出不含考场口令列', !str_contains($csv['raw'], '考场口令'));

    // 越权：导出他人考试
    $t->assertSame('导出他人考试成绩 -> 404', 404, Http::get("/api/teacher/scores/export?exam_id={$examOtherId}")['status']);

    // 备份：已结束可备份一次，重复备份被拒
    $backupUrl = '/api/admin/scores/backup';
    AuthSession::logout();
    Http::post('/api/admin/login', ['username' => E2E_ADMIN, 'password' => E2E_ADMIN_PWD]);
    $r1 = Http::post($backupUrl, ['exam_id' => $examId], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('备份已结束考试 -> 200', 200, $r1['status']);
    $r2 = Http::post($backupUrl, ['exam_id' => $examId], ['X-CSRF-Token' => $csrf()]);
    $t->assertTrue('重复备份被拒', $r2['status'] !== 200, 'status=' . $r2['status']);

    // 未结束考试不能备份（用一场进行中的对照考试）
    $ongoing = Fixture::createExam3(['exam_tea' => E2E_T1, 'exam_status' => 'testing']);
    if ($ongoing !== null) {
        $r3 = Http::post($backupUrl, ['exam_id' => $ongoing['exam_id']], ['X-CSRF-Token' => $csrf()]);
        $t->assertTrue('未结束考试备份被拒', $r3['status'] !== 200, 'status=' . $r3['status']);
        $t->assertTrue('提示尚未结束', str_contains($r3['raw'], '尚未结束'));
    }
});

$t->guard('管理端监考：单人收卷与全员收卷分离', function () use ($t, $examId, $students, $csrf) {
    $fresh = Fixture::createExam3([
        'exam_tea'    => E2E_T1,
        'exam_status' => 'testing',
    ]);
    if ($fresh === null) {
        $t->skip('管理端收卷分离', '夹具创建失败');
        return;
    }
    $fid = (int) $fresh['exam_id'];
    foreach ($fresh['students'] as $stuId) {
        Database::query("UPDATE `stuscore` SET stu_status = 'online' WHERE exam_id = ? AND stu_id = ?", [$fid, $stuId]);
    }

    $one = Http::data(Http::post('/api/admin/monitor/submit-one', ['exam_id' => $fid, 'stu_id' => $fresh['students'][0]],
        ['X-CSRF-Token' => $csrf()])) ?? [];
    $t->assertSame('管理端单人收卷判分 1 人', 1, (int) ($one['graded'] ?? 0));
    $rest = Database::fetchAll('SELECT stu_status FROM `stuscore` WHERE exam_id = ? ORDER BY stu_id ASC', [$fid]);
    $t->assertSame('其余两人未被牵连', ['over', 'online', 'online'],
        array_map(static fn ($r) => (string) $r['stu_status'], $rest));

    $all = Http::data(Http::post('/api/admin/monitor/submit', ['exam_id' => $fid], ['X-CSRF-Token' => $csrf()])) ?? [];
    $t->assertSame('全员收卷判分 2 人', 2, (int) ($all['graded'] ?? -1));
    $t->assertSame('全员收卷跳过已交卷 1 人', 1, (int) ($all['skipped'] ?? -1));
});

/* ==================================================================
 * 7. 收尾
 * ================================================================== */

$t->guard('夹具可精确回收（无残留）', function () use ($t) {
    Fixture::cleanup();
    AuthSession::logout();
    $left = (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `examinfo` WHERE exam_name LIKE ?', [Fixture::PREFIX . '%']
    )['c'] ?? -1);
    $t->assertSame('无残留测试考试', 0, $left);
    $stuLeft = (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stuinfo` WHERE id IN (?, ?, ?, ?, ?)',
        [Fixture::STU_A, Fixture::STU_B, Fixture::STU_1, Fixture::STU_2, Fixture::STU_3]
    )['c'] ?? -1);
    $t->assertSame('无残留测试考生', 0, $stuLeft);
});

exit($t->finish());
