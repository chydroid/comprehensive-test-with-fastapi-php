<?php
declare(strict_types=1);

/**
 * 成绩与学情分析测试（A2）
 *
 * 用完全确定性的数据（直接写入 stupaper/stuscore）验证 ScoreAnalysis 的统计口径：
 *  - 均分 / 中位数 / 最高 / 最低 / 及格率 / 优秀率 / 标准差
 *  - 分数段分布
 *  - 按题型 / 难度 / 科目 / 知识点的正确率
 *  - 薄弱题 TOP（正确率升序）
 *  - 班级横向对比
 *  - 只统计「已交卷」考生（未交卷不进分母）
 *  - 及格线/优秀线可通过 query 调整
 *  - 教师端越权（他人考试）返回 404；不存在的考试返回 404
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;
use Test\Fixture;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

use Core\Database;

$t = new Harness();
echo "== 成绩分析测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('成绩分析', '数据库不可用');
    exit($t->finish());
}

/** 4 名受测考生（A/B 一班；1/2 三人班，用于班级横向对比） */
const S1 = Fixture::STU_A;      // 9000001
const S2 = Fixture::STU_B;      // 9000002
const S3 = Fixture::STU_1;      // 9000003
const S4 = Fixture::STU_2;      // 9000004
const S5 = '9000006';           // 未交卷（应被排除）

const QUESTIONS = 4;
const PER_Q = 10;               // 每题 10 分 → 满分 40

$examId = 0;

function saCleanup(int &$examId): void
{
    if ($examId > 0) {
        Database::query('DELETE FROM `stupaper` WHERE exam_id = ?', [$examId]);
        Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$examId]);
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$examId]);
        $examId = 0;
    }
    Database::query('DELETE FROM `stuinfo` WHERE id = ?', [S5]);
}

saCleanup($examId);
Fixture::cleanup();

/* ---------- 1. 构造考试与受测考生 ---------- */
// 取题量充足的科目
$subjId = (int) (Database::fetch('SELECT subj_id FROM `quizlib` GROUP BY subj_id ORDER BY COUNT(*) DESC LIMIT 1')['subj_id'] ?? 0);
$t->assertTrue('取到科目', $subjId > 0, "subj_id={$subjId}");

$qRows = Database::fetchAll(
    'SELECT id, quiz_key FROM `quizlib`
     WHERE subj_id = ? AND quiz_class = \'radio2\' AND quiz_key <> \'\' AND CHAR_LENGTH(quiz_key) = 1
     LIMIT ' . QUESTIONS,
    [$subjId]
);
$t->assertTrue('取到 ' . QUESTIONS . ' 道单选题', count($qRows) === QUESTIONS, 'count=' . count($qRows));

$t->guard('构造确定性考试数据', function () use ($t, &$examId, $subjId, $qRows) {
    if (count($qRows) !== QUESTIONS) { $t->assertTrue('跳过：题目不足', true); return; }

    Database::query(
        'INSERT INTO `examinfo`
            (exam_name, exam_class, subj_id, exam_start, exam_end, exam_tea, stu_class,
             exam_status, exam_pwd, exam_score, paper_mode, radio2_mid_sum, radio2_val)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            Fixture::PREFIX . '分析用考试', Fixture::PREFIX, $subjId,
            date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600),
            'teacher1', Fixture::CLASS_ID,
            'over', '246810', QUESTIONS * PER_Q, 'random', QUESTIONS, PER_Q,
        ]
    );
    $examId = Database::lastInsertId();
    $t->assertTrue('考试已创建', $examId > 0);

    // 考生（A/B 一班；1/2 三人班）
    $students = [[S1, Fixture::CLASS_ID], [S2, Fixture::CLASS_ID], [S3, Fixture::CLASS_ID3], [S4, Fixture::CLASS_ID3]];
    foreach ($students as $i => [$id, $classId]) {
        Database::query('DELETE FROM `stuinfo` WHERE id = ?', [$id]);
        Database::query(
            'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, Fixture::PREFIX . '分析考生' . ($i + 1), \App\Services\Password::hash(Fixture::PWD), '男', '1', $classId]
        );
    }
    // 未交卷考生（用于验证排除逻辑）
    Database::query(
        'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        [S5, Fixture::PREFIX . '未交卷考生', \App\Services\Password::hash(Fixture::PWD), '男', '1', Fixture::CLASS_ID]
    );

    // 作答矩阵：q1 全对 / q2 三人对 / q3 二人对 / q4 一人对
    //  → S1=4 题=40 分；S2=3 题=30 分；S3=2 题=20 分；S4=1 题=10 分
    $correctBy = [
        0 => [S1, S2, S3, S4],
        1 => [S1, S2, S3],
        2 => [S1, S2],
        3 => [S1],
    ];

    $scores = [S1 => 0, S2 => 0, S3 => 0, S4 => 0];
    foreach ($qRows as $qi => $q) {
        $correctKey = (string) $q['quiz_key'];
        $wrongKey = $correctKey === 'A' ? 'B' : 'A';
        foreach ([S1, S2, S3, S4] as $stuId) {
            $isRight = in_array($stuId, $correctBy[$qi], true);
            Database::query(
                'INSERT INTO `stupaper` (exam_id, stu_id, paper_id, quiz_id, quiz_class, stu_key, quiz_status)
                 VALUES (?, ?, ?, ?, ?, ?, 1)',
                [$examId, $stuId, $qi + 1, (int) $q['id'], 'radio2', $isRight ? $correctKey : $wrongKey]
            );
            if ($isRight) {
                $scores[$stuId] += PER_Q;
            }
        }
        // 未交卷考生也答了题，但不应计入统计
        Database::query(
            'INSERT INTO `stupaper` (exam_id, stu_id, paper_id, quiz_id, quiz_class, stu_key, quiz_status)
             VALUES (?, ?, ?, ?, ?, ?, 0)',
            [$examId, S5, $qi + 1, (int) $q['id'], 'radio2', $correctKey]
        );
    }

    foreach ($scores as $stuId => $score) {
        Database::query(
            'INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
             VALUES (?, ?, ?, \'over\', ?)',
            [$examId, $stuId, $score, '246810']
        );
    }
    // 未交卷：状态 online
    Database::query(
        'INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
         VALUES (?, ?, 0, \'online\', ?)',
        [$examId, S5, '246810']
    );

    $t->assertSame('S1 满分', 40, $scores[S1]);
    $t->assertSame('S4 十分', 10, $scores[S4]);
});

/* ---------- 2. 教师登录 ---------- */
$csrf = '';
$t->guard('教师登录成功', function () use ($t, &$csrf) {
    $res = Http::post('/api/teacher/login', ['username' => 'teacher1', 'password' => 'teacher@2026']);
    $t->assertSame('登录 200', 200, $res['status']);
    $csrf = (string) (Http::data($res)['csrf_token'] ?? '');
});
$H = ['X-CSRF-Token' => $csrf];

/* ---------- 3. 分析接口断言 ---------- */
$got = null;
$t->guard('教师端：拉取分析结果', function () use ($t, &$examId, &$got) {
    if ($examId <= 0) { $t->assertTrue('跳过', true); return; }
    $res = Http::get("/api/teacher/exams/{$examId}/analysis", $GLOBALS['H']);
    $t->assertSame('分析 200', 200, $res['status']);
    $got = Http::data($res);
    $t->assertTrue('返回结构完整', isset($got['summary'], $got['distribution'], $got['by_type'], $got['weak_items']));
});

function checkSummary(Harness $t, ?array $d): void
{
    if ($d === null) { $t->assertTrue('跳过 summary（无数据）', true); return; }
    $s = $d['summary'] ?? [];
    $t->assertSame('统计人数 = 4（排除未交卷）', 4, (int) ($s['count'] ?? 0));
    $t->assertSame('平均分 = 25', 25.0, (float) ($s['avg'] ?? 0));
    $t->assertSame('中位数 = 25', 25.0, (float) ($s['median'] ?? 0));
    $t->assertSame('最高分 = 40', 40, (int) ($s['max'] ?? 0));
    $t->assertSame('最低分 = 10', 10, (int) ($s['min'] ?? 0));
    $t->assertSame('满分 = 40', 40, (int) ($d['exam']['full_score'] ?? 0));
    // 及格线 60% → 24 分 → S1/S2 及格
    $t->assertSame('及格线分数 = 24', 24, (int) ($s['pass_score'] ?? 0));
    $t->assertSame('及格人数 = 2', 2, (int) ($s['pass_count'] ?? 0));
    $t->assertSame('及格率 = 50%', 50, (int) ($s['pass_rate'] ?? 0));
    // 优秀线 85% → 34 分 → 仅 S1
    $t->assertSame('优秀率 = 25%', 25, (int) ($s['excellent_rate'] ?? 0));
}
$t->guard('summary 口径正确', function () use ($t, &$got) { checkSummary($t, $got); });

$t->guard('分数段分布正确', function () use ($t, &$got) {
    if ($got === null) { $t->assertTrue('跳过', true); return; }
    $map = [];
    foreach ($got['distribution'] as $b) $map[$b['key']] = (int) $b['count'];
    $t->assertSame('不及格段 2 人（50%、25%）', 2, $map['0-59'] ?? 0);
    $t->assertSame('70-79 段 1 人（75%）', 1, $map['70-79'] ?? 0);
    $t->assertSame('90-100 段 1 人（100%）', 1, $map['90-100'] ?? 0);
    $t->assertSame('60-69 段 0 人', 0, $map['60-69'] ?? 0);
});

$t->guard('题型正确率正确', function () use ($t, &$got) {
    if ($got === null) { $t->assertTrue('跳过', true); return; }
    $row = null;
    foreach ($got['by_type'] as $r) { if ($r['type'] === 'radio2') $row = $r; }
    $t->assertTrue('含单选题型', $row !== null);
    $t->assertSame('作答数 16（4 人 × 4 题）', 16, (int) ($row['total'] ?? 0));
    $t->assertSame('答对 10', 10, (int) ($row['correct'] ?? 0));
    $t->assertSame('正确率 63%', 63, (int) ($row['correct_rate'] ?? 0));
});

$t->guard('薄弱题按正确率升序', function () use ($t, &$got, $qRows) {
    if ($got === null || count($qRows) !== QUESTIONS) { $t->assertTrue('跳过', true); return; }
    $weak = $got['weak_items'] ?? [];
    $t->assertSame('返回 4 道薄弱题', 4, count($weak));
    // q4（1/4）应排最前，q1（4/4）应排最后
    $t->assertSame('最薄弱题正确率 25%', 25, (int) ($weak[0]['correct_rate'] ?? -1));
    $t->assertSame('最薄弱题为 q4', (int) $qRows[3]['id'], (int) ($weak[0]['quiz_id'] ?? 0));
    $t->assertSame('正确率最高题 100%', 100, (int) ($weak[3]['correct_rate'] ?? -1));
});

$t->guard('班级横向对比正确', function () use ($t, &$got) {
    if ($got === null) { $t->assertTrue('跳过', true); return; }
    $classes = $got['classes'] ?? [];
    $t->assertSame('两个班级', 2, count($classes));
    // 按均分降序：一班（40/30 → 35）在前
    $t->assertSame('一班 2 人', 2, (int) ($classes[0]['count'] ?? 0));
    $t->assertSame('一班均分 35', 35.0, (float) ($classes[0]['avg'] ?? 0));
    $t->assertSame('二班均分 15', 15.0, (float) ($classes[1]['avg'] ?? 0));
});

$t->guard('及格线可调（pass_line=80）', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('跳过', true); return; }
    $res = Http::get("/api/teacher/exams/{$examId}/analysis?pass_line=80", $GLOBALS['H']);
    $t->assertSame('分析 200', 200, $res['status']);
    $s = Http::data($res)['summary'] ?? [];
    $t->assertSame('及格线 80% → 32 分', 32, (int) ($s['pass_score'] ?? 0));
    $t->assertSame('仅 1 人及格（40 分）', 1, (int) ($s['pass_count'] ?? 0));
    $t->assertSame('及格率 25%', 25, (int) ($s['pass_rate'] ?? 0));
});

/* ---------- 4. 守卫 ---------- */
$t->guard('不存在的考试 → 404', function () use ($t) {
    $res = Http::get('/api/teacher/exams/999999999/analysis', $GLOBALS['H']);
    $t->assertSame('404', 404, $res['status']);
});

$t->guard('他人考试 → 404（不可枚举）', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('跳过', true); return; }
    // 把考试改挂到 teacher2 名下，teacher1 应看不到
    Database::query('UPDATE `examinfo` SET exam_tea = ? WHERE id = ?', ['teacher2', $examId]);
    $res = Http::get("/api/teacher/exams/{$examId}/analysis", $GLOBALS['H']);
    $t->assertSame('404', 404, $res['status']);
    Database::query('UPDATE `examinfo` SET exam_tea = ? WHERE id = ?', ['teacher1', $examId]);
});

/* ---------- 5. 管理端 ---------- */
$adminCsrf = '';
$t->guard('管理员登录', function () use ($t, &$adminCsrf) {
    $res = Http::post('/api/admin/login', ['username' => 'admin', 'password' => 'admin@2026']);
    $t->assertSame('登录 200', 200, $res['status']);
    $adminCsrf = (string) (Http::data($res)['csrf_token'] ?? '');
});
$AH = ['X-CSRF-Token' => $adminCsrf];

$t->guard('管理端：分析结果与教师端一致', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('跳过', true); return; }
    $res = Http::get("/api/admin/exams/{$examId}/analysis", $GLOBALS['AH']);
    $t->assertSame('分析 200', 200, $res['status']);
    checkSummary($t, Http::data($res));
});

$t->guard('管理端：不存在的考试 → 404', function () use ($t) {
    $res = Http::get('/api/admin/exams/999999999/analysis', $GLOBALS['AH']);
    $t->assertSame('404', 404, $res['status']);
});

/* ---------- 6. 清理 ---------- */
$t->guard('清理测试数据', function () use ($t, &$examId) {
    saCleanup($examId);
    Fixture::cleanup();
    $t->assertTrue('回收完成', true);
});

exit($t->finish());
