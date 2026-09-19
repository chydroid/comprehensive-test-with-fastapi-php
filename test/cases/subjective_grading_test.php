<?php
declare(strict_types=1);

/**
 * 主观题批改测试（A4）
 *
 * 验证：
 * - 组卷矩阵纳入问答题：examinfo.longtext_* 生效，题量/满分/库存校验都算上
 * - 交卷判分**不含**主观题分（autoGrade 只算客观题）
 * - scoreBreakdown 只读口径：客观题分 + 已批主观题分 + 待批题数
 * - 教师端批阅闭环：待批总览 → 答题卡（含参考答案）→ 提交批阅 → 总分重算落库
 * - 单题得分封顶（不超过 examinfo.longtext_val）
 * - 撤销批阅：得分清空、总分回落为客观题得分
 * - 越权一律 404（他人考试、模拟考试）
 * - 非法 paper_id 被忽略（skipped），空 items / 非法 stuId 被 400 拒绝
 *
 * 注意：题库中有一部分历史填空题的 quiz_key 为空，组卷又是 ORDER BY RAND()
 * 随机抽题，因此「客观题得分」不能硬编码为满分。测试先按 autoGrade 的同一口径
 * （同一个 Quiz::isCorrect）算出期望值，再断言相等 —— 仍能验证「主观题分不进
 * 交卷分」「批阅后总分 = 客观 + 主观」这些核心不变量，且不受题库数据波动影响。
 *
 * 全部数据自包含：题库题以 __TEST__ 前缀插入并按 id 回收，
 * 考试/考生/答卷由 Fixture 回收。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;
use Test\Fixture;
use App\Models\Exam;
use App\Models\Quiz;
use App\Services\ExamEngine;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

$t = new Harness();
echo "== 主观题批改测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('主观题批改', '数据库不可用');
    exit($t->finish());
}

const STU      = Fixture::STU_A;      // 9000001
const STU_B    = Fixture::STU_B;      // 9000002（本场不作答，用于验证未交卷不计入）
const PWD      = Fixture::PWD;
const TEA      = 'teacher1';
const TEA2     = 'teacher2';
const TEA_PWD  = 'teacher@2026';
const OBJ_VAL  = 5;                   // Fixture 客观题每题分值
const SUBJ_VAL = 10;                  // 本测试给问答题设的分值

/** 本测试插入的题库题 id，精确回收，避免污染共享题库 */
$createdQuizIds = [];
/** 客观题实际判对的得分（由 guard 2 计算，后续 guard 复用） */
$expectedObj = 0;

/** 确保测试考生存在（必须在 Fixture::cleanup() 之后执行） */
function ensureStudents(): void
{
    foreach ([STU, STU_B] as $i => $id) {
        $exists = \Core\Database::fetch('SELECT id FROM `stuinfo` WHERE id = ?', [$id]);
        if ($exists === null) {
            \Core\Database::query(
                'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$id, Fixture::PREFIX . '考生' . ($i + 1), \App\Services\Password::hash(PWD), '男', '1', '1']
            );
        } else {
            \Core\Database::query('UPDATE `stuinfo` SET stu_pwd = ? WHERE id = ?', [\App\Services\Password::hash(PWD), $id]);
        }
    }
}

/** 回收本测试产生的全部数据 */
function sgCleanup(array &$quizIds): void
{
    Fixture::cleanup();
    foreach ($quizIds as $qid) {
        \Core\Database::query('DELETE FROM `quizlib` WHERE id = ?', [(int) $qid]);
    }
    $quizIds = [];
}

sgCleanup($createdQuizIds);
ensureStudents();

/** 以教师身份登录，返回 CSRF */
function asTeacher(string $name = TEA): string
{
    $res = Http::post('/api/teacher/login', ['username' => $name, 'password' => TEA_PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

/** 以考生身份登录，返回 CSRF */
function asStudent(): string
{
    $res = Http::post('/api/student/login', ['username' => STU, 'password' => PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

/* ---------- 0. 建场：Fixture 考试 + 插入问答题 + 补齐组卷维度 ---------- */
$examId = 0;
$subjId = 0;
$diffField = 'mid';
$diffCode = 'Z';

$t->guard('建场：考试排入问答题维度', function () use ($t, &$examId, &$subjId, &$diffField, &$diffCode, &$createdQuizIds) {
    $exam = Fixture::createExam(['exam_tea' => TEA]);
    $t->assertTrue('夹具考试创建成功', $exam !== null);
    if ($exam === null) return;
    $examId = (int) $exam['exam_id'];

    $row = \Core\Database::fetch('SELECT * FROM `examinfo` WHERE id = ?', [$examId]);
    $subjId = (int) $row['subj_id'];

    // 夹具把四种客观题各配 1 题在同一难度，问答题沿用该难度码
    foreach (['easy' => 'Y', 'mid' => 'Z', 'hard' => 'N'] as $f => $c) {
        if ((int) ($row["radio1_{$f}_sum"] ?? 0) > 0) { $diffField = $f; $diffCode = $c; break; }
    }

    \Core\Database::query(
        'INSERT INTO `quizlib` (subj_id, quiz_kp, quiz_title, quiz_class, quiz_option, quiz_key, quiz_diff)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $subjId, '', Fixture::PREFIX . '问答题：请简述本系统的判分流程。',
            'longtext', '', '参考答案要点：交卷后先自动判分客观题，问答题由教师人工批阅后重算总分。', $diffCode,
        ]
    );
    $longtextQuizId = (int) \Core\Database::lastInsertId();
    $createdQuizIds[] = $longtextQuizId;
    $t->assertTrue('问答题已入库', $longtextQuizId > 0);

    // 组卷维度：同难度 1 题、每题 10 分；满分同步为 4×5 + 1×10 = 30
    \Core\Database::query(
        "UPDATE `examinfo` SET `longtext_{$diffField}_sum` = 1, `longtext_val` = ?, `exam_score` = ?
         WHERE id = ?",
        [SUBJ_VAL, 4 * OBJ_VAL + SUBJ_VAL, $examId]
    );

    $fresh = \Core\Database::fetch('SELECT * FROM `examinfo` WHERE id = ?', [$examId]);
    $t->assertSame('应出题总数含问答题', 5, Exam::totalQuestions($fresh));
    $t->assertSame('满分含问答题', 4 * OBJ_VAL + SUBJ_VAL, Exam::computedTotalScore($fresh));
    $t->assertSame('库存校验通过', true, Exam::checkStock($fresh)['ok']);

    $lt = null;
    foreach (Exam::paperPlan($fresh) as $p) {
        if ($p['type'] === 'longtext') { $lt = $p; break; }
    }
    $t->assertTrue('paperPlan 含 longtext', $lt !== null);
    $t->assertSame('longtext 抽题数为 1', 1, (int) ($lt[$diffField] ?? 0));
    $t->assertSame('longtext 每题分值 10', SUBJ_VAL, (int) ($lt['val'] ?? 0));
});

/* ---------- 1. 组卷：问答题进入试卷 ---------- */
$t->guard('组卷：问答题被排进试卷', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $res = ExamEngine::generatePaper($examId, STU);
    $t->assertSame('组卷成功', true, (bool) ($res['generated'] ?? false));
    $t->assertSame('无缺题警告', [], $res['warnings'] ?? ['unexpected']);

    $c = \Core\Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ? AND quiz_class = ?',
        [$examId, STU, 'longtext']
    );
    $t->assertSame('试卷含 1 道问答题', 1, (int) ($c['c'] ?? 0));

    $total = \Core\Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?', [$examId, STU]
    );
    $t->assertSame('试卷共 5 题', 5, (int) ($total['c'] ?? 0));
});

/* ---------- 2. 交卷判分：主观题不计分 ---------- */
$t->guard('交卷判分只算客观题', function () use ($t, &$examId, &$expectedObj) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }

    $papers = \Core\Database::fetchAll(
        'SELECT sp.paper_id, sp.quiz_class, q.quiz_key
         FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND sp.stu_id = ?',
        [$examId, STU]
    );
    // 客观题填正确答案（争取满分），问答题填一段真实作答文本
    foreach ($papers as $p) {
        $ans = (string) $p['quiz_class'] === 'longtext'
            ? '考生作答：交卷后先自动判分客观题…'
            : (string) $p['quiz_key'];
        ExamEngine::saveAnswer($examId, STU, (int) $p['paper_id'], $ans);
    }

    // 期望的客观题得分：只统计「考生作答非空 且 标准答案非空」的题
    $rows = \Core\Database::fetchAll(
        'SELECT sp.quiz_class, sp.stu_key, q.quiz_key
         FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND sp.stu_id = ?',
        [$examId, STU]
    );
    foreach ($rows as $r) {
        $type = (string) $r['quiz_class'];
        if ($type === 'longtext') continue;
        $ans = (string) $r['stu_key'];
        $key = (string) $r['quiz_key'];
        if ($ans === '' || $key === '') continue;
        if (Quiz::isCorrect($type, $key, $ans)) $expectedObj += OBJ_VAL;
    }
    $t->assertTrue('至少一题判对（测试有效）', $expectedObj > 0, "expectedObj={$expectedObj}");

    $score = ExamEngine::autoGrade($examId, STU);
    $t->assertSame('交卷分数=客观题判对分（不含主观题）', $expectedObj, $score);

    $row = \Core\Database::fetch(
        'SELECT stu_score, stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, STU]
    );
    $t->assertSame('成绩落库与判分一致', $expectedObj, (int) ($row['stu_score'] ?? -1));
    $t->assertSame('状态已交卷', true, str_starts_with((string) ($row['stu_status'] ?? ''), 'over'));
});

/* ---------- 3. 只读分值明细 ---------- */
$t->guard('scoreBreakdown 口径正确', function () use ($t, &$examId, &$expectedObj) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $b = ExamEngine::scoreBreakdown($examId, STU);
    $t->assertSame('客观题分与判分一致', $expectedObj, $b['objective']);
    $t->assertSame('主观题 0（未批）', 0, $b['subjective']);
    $t->assertSame('待批 1 题', 1, $b['pending']);
    $t->assertSame('总分=客观题分', $expectedObj, $b['score']);
    $t->assertSame('卷面满分 30', 4 * OBJ_VAL + SUBJ_VAL, $b['full']);
});

/* ---------- 4. 教师端批阅闭环 ---------- */
$paperId = 0;

$t->guard('待批总览：仅统计已交卷考生', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $t->assertTrue('教师登录取到 CSRF', $csrf !== '');

    $res = Http::get("/api/teacher/exams/{$examId}/subjective", ['X-CSRF-Token' => $csrf]);
    $t->assertSame('总览 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $ov = $d['overview'] ?? [];
    $t->assertSame('已交卷考生 1 人', 1, (int) ($ov['students'] ?? -1));
    $t->assertSame('待批题数 1', 1, (int) ($ov['pending_items'] ?? -1));
    $t->assertSame('待批考生 1 人', 1, (int) ($ov['pending_students'] ?? -1));
    $list = $ov['list'] ?? [];
    $t->assertSame('列表 1 行', 1, count($list));
    $t->assertSame('列表考生正确', STU, (string) ($list[0]['stu_id'] ?? ''));
});

$t->guard('答题卡：含参考答案与考生作答', function () use ($t, &$examId, &$paperId, &$expectedObj) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $res = Http::get("/api/teacher/exams/{$examId}/subjective/" . STU, ['X-CSRF-Token' => $csrf]);
    $t->assertSame('答题卡 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $items = $d['items'] ?? [];
    $t->assertSame('1 道主观题', 1, count($items));
    $it = $items[0] ?? [];
    $paperId = (int) ($it['paper_id'] ?? 0);
    $t->assertTrue('返回 paper_id', $paperId > 0);
    $t->assertTrue('含参考答案', (string) ($it['quiz_key'] ?? '') !== '');
    $t->assertTrue('含考生作答', (string) ($it['stu_key'] ?? '') !== '');
    $t->assertSame('满分 10', SUBJ_VAL, (int) ($d['full_score'] ?? 0));
    $t->assertSame('尚未批阅', false, (bool) ($it['graded'] ?? true));
    $t->assertSame('待批 1', 1, (int) ($d['pending'] ?? -1));
    $t->assertSame('明细客观分与判分一致', $expectedObj, (int) ($d['breakdown']['objective'] ?? 0));
});

$t->guard('提交批阅：写分并重算总分', function () use ($t, &$examId, &$paperId, &$expectedObj) {
    if ($examId <= 0 || $paperId <= 0) { $t->assertTrue('前置数据存在', false); return; }
    $csrf = asTeacher();
    $res = Http::post(
        "/api/teacher/exams/{$examId}/subjective/" . STU,
        ['items' => [['paper_id' => $paperId, 'score' => 8, 'comment' => '要点基本齐全']]],
        ['X-CSRF-Token' => $csrf]
    );
    $t->assertSame('批阅 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('保存 1 题', 1, (int) ($d['saved'] ?? -1));
    $b = $d['breakdown'] ?? [];
    $t->assertSame('主观题 8', 8, (int) ($b['subjective'] ?? -1));
    $t->assertSame('总分=客观题分+主观题分', $expectedObj + 8, (int) ($b['score'] ?? -1));
    $t->assertSame('已无待批', 0, (int) ($b['pending'] ?? -1));

    $row = \Core\Database::fetch(
        'SELECT stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, STU]
    );
    $t->assertSame('总分已落库', $expectedObj + 8, (int) ($row['stu_score'] ?? -1));

    $sp = \Core\Database::fetch(
        'SELECT quiz_score, quiz_comment, grader_name, graded_at FROM `stupaper`
         WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
        [$examId, STU, $paperId]
    );
    $t->assertSame('单题得分落库', 8, (int) ($sp['quiz_score'] ?? -1));
    $t->assertSame('评语落库', '要点基本齐全', (string) ($sp['quiz_comment'] ?? ''));
    $t->assertSame('批阅人落库', TEA, (string) ($sp['grader_name'] ?? ''));
    $t->assertTrue('批阅时间已写', !empty($sp['graded_at']));
});

$t->guard('单题得分封顶不超过满分', function () use ($t, &$examId, &$paperId, &$expectedObj) {
    if ($examId <= 0 || $paperId <= 0) { $t->assertTrue('前置数据存在', false); return; }
    $csrf = asTeacher();
    $res = Http::post(
        "/api/teacher/exams/{$examId}/subjective/" . STU,
        ['items' => [['paper_id' => $paperId, 'score' => 999]]],
        ['X-CSRF-Token' => $csrf]
    );
    $t->assertSame('批阅 200', 200, $res['status']);
    $b = Http::data($res)['breakdown'] ?? [];
    $t->assertSame('主观题被封顶到 10', SUBJ_VAL, (int) ($b['subjective'] ?? -1));
    $t->assertSame('总分=客观题分+封顶主观题分', $expectedObj + SUBJ_VAL, (int) ($b['score'] ?? -1));
});

$t->guard('越权与非法入参被拒', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }

    // 非法 paper_id 被忽略
    $csrf = asTeacher();
    $res = Http::post(
        "/api/teacher/exams/{$examId}/subjective/" . STU,
        ['items' => [['paper_id' => 99999999, 'score' => 5]]],
        ['X-CSRF-Token' => $csrf]
    );
    $t->assertSame('越界 paper_id 请求 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('全部被忽略', 0, (int) ($d['saved'] ?? -1));
    $t->assertSame('skipped 计数 1', 1, (int) ($d['skipped'] ?? -1));

    // 空 items 被拒
    $bad = Http::post("/api/teacher/exams/{$examId}/subjective/" . STU, ['items' => []], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('空 items 400', 400, $bad['status']);

    // 非法 stuId 被拒
    $bad2 = Http::get("/api/teacher/exams/{$examId}/subjective/abc", ['X-CSRF-Token' => $csrf]);
    $t->assertSame('非法 stuId 400', 400, $bad2['status']);
});

$t->guard('他人考试一律 404', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf2 = asTeacher(TEA2);
    $t->assertTrue('teacher2 登录成功', $csrf2 !== '');

    $r1 = Http::get("/api/teacher/exams/{$examId}/subjective", ['X-CSRF-Token' => $csrf2]);
    $t->assertSame('总览 404', 404, $r1['status']);
    $r2 = Http::get("/api/teacher/exams/{$examId}/subjective/" . STU, ['X-CSRF-Token' => $csrf2]);
    $t->assertSame('答题卡 404', 404, $r2['status']);
    $r3 = Http::post(
        "/api/teacher/exams/{$examId}/subjective/" . STU,
        ['items' => [['paper_id' => 1, 'score' => 1]]],
        ['X-CSRF-Token' => $csrf2]
    );
    $t->assertSame('批阅 404', 404, $r3['status']);

    // 不存在的考试同样 404（不区分「无权限」与「不存在」）
    $r4 = Http::get('/api/teacher/exams/99999999/subjective', ['X-CSRF-Token' => $csrf2]);
    $t->assertSame('不存在考试 404', 404, $r4['status']);
});

$t->guard('模拟考试不可批阅（404）', function () use ($t) {
    $csrfS = asStudent();
    $t->assertTrue('考生登录成功', $csrfS !== '');

    $subj = \Core\Database::fetch(
        "SELECT subj_id FROM `quizlib` WHERE quiz_class = 'radio2' AND quiz_key <> '' GROUP BY subj_id LIMIT 1"
    );
    $t->assertTrue('存在可用于模拟的科目', $subj !== null);
    if ($subj === null) return;

    $start = Http::post('/api/exercise/mock/start', [
        'subj_id' => (int) $subj['subj_id'], 'radio2_count' => 1,
    ], ['X-CSRF-Token' => $csrfS]);
    $t->assertSame('模拟开始 200', 200, $start['status']);
    $mockId = (int) ((Http::data($start) ?? [])['exam_id'] ?? 0);
    $t->assertTrue('拿到模拟考试 id', $mockId > 0);
    if ($mockId <= 0) return;

    try {
        $csrf = asTeacher();
        $res = Http::get("/api/teacher/exams/{$mockId}/subjective", ['X-CSRF-Token' => $csrf]);
        $t->assertSame('模拟考试 404', 404, $res['status']);
    } finally {
        // 清理模拟考试（它不以 __TEST__ 命名，Fixture::cleanup 抓不到）
        \Core\Database::query('DELETE FROM `stupaper` WHERE exam_id = ?', [$mockId]);
        \Core\Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$mockId]);
        \Core\Database::query('DELETE FROM `examinfo` WHERE id = ?', [$mockId]);
    }
});

/* ---------- 5. 撤销批阅 ---------- */
$t->guard('撤销批阅：总分回落为客观题得分', function () use ($t, &$examId, &$expectedObj) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $res = Http::post("/api/teacher/exams/{$examId}/subjective/" . STU . '/revoke', [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('撤销 200', 200, $res['status']);
    $b = Http::data($res)['breakdown'] ?? [];
    $t->assertSame('主观题归零', 0, (int) ($b['subjective'] ?? -1));
    $t->assertSame('待批恢复为 1', 1, (int) ($b['pending'] ?? -1));
    $t->assertSame('总分回落为客观题分', $expectedObj, (int) ($b['score'] ?? -1));

    $sp = \Core\Database::fetch(
        'SELECT quiz_score, grader_name FROM `stupaper`
         WHERE exam_id = ? AND stu_id = ? AND quiz_class = ?',
        [$examId, STU, 'longtext']
    );
    $t->assertTrue('得分已清空', $sp['quiz_score'] === null);
    $t->assertSame('批阅人已清空', '', (string) ($sp['grader_name'] ?? ''));

    $row = \Core\Database::fetch('SELECT stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, STU]);
    $t->assertSame('库中总分已回落', $expectedObj, (int) ($row['stu_score'] ?? -1));
});

/* ---------- 清理 ---------- */
sgCleanup($createdQuizIds);
\Core\Database::query('DELETE FROM `stuscorebak` WHERE stu_id IN (?, ?)', [STU, STU_B]);

exit($t->finish());
