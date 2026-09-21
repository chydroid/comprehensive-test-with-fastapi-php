<?php
declare(strict_types=1);

/**
 * 组卷多样化测试（A3）
 *
 * 覆盖三种组卷模式的全链路（创建 → 落库 → 出题 → 卷面校正）：
 *  - random ：沿用题型×难度计数列随机抽题（回归，确保未被新分支破坏）
 *  - manual ：手动选题（exam_manual_quiz 落库、出题按序取题、满分按所选题型分值合计）
 *  - by_kp  ：按知识点比例（exam_kp_plan 落库、按知识点随机抽题、满分出题时回填）
 *
 * 同时校验：
 *  - 手动选题的守卫（空选择 400、跨科目题目 400）
 *  - 题库检索 / 知识点清单接口（教师端 + 管理端）
 *  - 管理端保存手动选题考试
 *  - 全流程结束后精确回收（含题目知识点字段的还原）
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;
use Test\Fixture;
use App\Models\Exam;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

use Core\Database;

$t = new Harness();
echo "== 组卷多样化测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('组卷多样化', '数据库不可用');
    exit($t->finish());
}

const KP_A = '__TEST__KP_A';
const KP_B = '__TEST__KP_B';

/** 本测试创建的考试 ID 与临时改过知识点的题目 ID */
$createdExams = [];
$touchedQuizIds = [];

function pmCleanup(array &$examIds, array &$touched): void
{
    foreach ($examIds as $id) {
        Database::query('DELETE FROM `exam_manual_quiz` WHERE exam_id = ?', [$id]);
        Database::query('DELETE FROM `exam_kp_plan` WHERE exam_id = ?', [$id]);
        Database::query('DELETE FROM `stupaper` WHERE exam_id = ?', [$id]);
        Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$id]);
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$id]);
    }
    $examIds = [];
    if ($touched !== []) {
        $ph = implode(',', array_fill(0, count($touched), '?'));
        Database::query("UPDATE `quizlib` SET quiz_kp = '' WHERE id IN ({$ph})", $touched);
        $touched = [];
    }
}

pmCleanup($createdExams, $touchedQuizIds);
Fixture::cleanup();

/* ---------- 准备：夹具考试用于取科目与班级；教师登录 ---------- */
$fx = Fixture::createExam();
$createdExams[] = (int) $fx['exam_id'];
$subjId = (int) (Database::fetch('SELECT subj_id FROM `examinfo` WHERE id = ?', [$fx['exam_id']])['subj_id'] ?? 0);
$t->assertTrue('夹具科目有效', $subjId > 0, "subj_id={$subjId}");
$classId = Fixture::CLASS_ID;

$csrf = '';
$t->guard('教师登录成功', function () use ($t, &$csrf) {
    $res = Http::post('/api/teacher/login', ['username' => 'teacher1', 'password' => 'teacher@2026']);
    $t->assertSame('登录 200', 200, $res['status']);
    $csrf = (string) (Http::data($res)['csrf_token'] ?? '');
    $t->assertTrue('取到 CSRF', $csrf !== '');
});
$H = ['X-CSRF-Token' => $csrf];

/** 今日日期 + 相对当前时间偏移的 HH:MM */
function hhmm(int $offsetSeconds): string
{
    return date('H:i', time() + $offsetSeconds);
}

function createExamViaApi(array $extra, array $headers): array
{
    return Http::post('/api/teacher/exams', array_merge([
        'exam_name'        => Fixture::PREFIX . '组卷模式考试',
        'exam_class'       => Fixture::PREFIX,
        'exam_category_id' => 0,
        'subj_id'          => $GLOBALS['subjId'],
        'exam_date'        => date('Y-m-d'),
        'exam_start_time'  => hhmm(3600),
        'exam_end_time'    => hhmm(7200),
        'exam_tea'         => '',
        'stu_class'        => $GLOBALS['classId'],
    ], $extra), $headers);
}

/**
 * 模拟考生「已进入考场」（stuscore.stu_status = online）。
 * 出题只面向已进入考场的考生，未入场者不会被排卷，故断言卷面前必须先入场。
 */
function markEntered(int $examId, string $stuId): void
{
    Database::query(
        "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
         VALUES (?, ?, 0, 'online', '') ON DUPLICATE KEY UPDATE stu_status = 'online'",
        [$examId, $stuId]
    );
}

/** 取若干道指定科目的题目 */
function quizIdsOf(int $subjId, string $class, int $limit): array
{
    $rows = Database::fetchAll(
        "SELECT id FROM `quizlib` WHERE subj_id = ? AND quiz_class = ? AND quiz_key <> '' LIMIT {$limit}",
        [$subjId, $class]
    );
    return array_map(static fn (array $r): int => (int) $r['id'], $rows);
}

/* ==================== 1. random 模式（回归） ==================== */
$randomExamId = 0;
$t->guard('random 模式：创建考试', function () use ($t, &$randomExamId, $subjId) {
    // 按题库实际可用量挑选难度，避免因某「题型×难度」无题导致卷面残缺
    $avail = Exam::availableCounts($subjId);
    $fieldOf = ['Y' => 'easy', 'Z' => 'mid', 'N' => 'hard'];

    $pick1 = null;   // radio1：需 ≥1 道
    $pick2 = null;   // radio2：需 ≥2 道
    foreach (['Y', 'Z', 'N'] as $d) {
        if ($pick1 === null && ($avail['radio1'][$d] ?? 0) >= 1) $pick1 = $d;
        if ($pick2 === null && ($avail['radio2'][$d] ?? 0) >= 2) $pick2 = $d;
    }
    if ($pick1 === null || $pick2 === null) {
        $t->assertTrue('跳过：题库不足以构造随机卷', true);
        return;
    }

    $payload = [
        'radio1_easy_sum' => 0, 'radio1_mid_sum' => 0, 'radio1_hard_sum' => 0, 'radio1_val' => 2,
        'radio2_easy_sum' => 0, 'radio2_mid_sum' => 0, 'radio2_hard_sum' => 0, 'radio2_val' => 3,
        'checkbox_easy_sum' => 0, 'checkbox_mid_sum' => 0, 'checkbox_hard_sum' => 0, 'checkbox_val' => 4,
        'text_easy_sum' => 0, 'text_mid_sum' => 0, 'text_hard_sum' => 0, 'text_val' => 4,
        'paper_mode' => 'random',
    ];
    $payload["radio1_{$fieldOf[$pick1]}_sum"] = 1;
    $payload["radio2_{$fieldOf[$pick2]}_sum"] = 2;

    $res = createExamViaApi($payload, $GLOBALS['H']);
    $t->assertSame('创建 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $randomExamId = (int) ($data['id'] ?? 0);
    $GLOBALS['createdExams'][] = $randomExamId;
    $t->assertTrue('返回考试 ID', $randomExamId > 0);
    $t->assertSame('满分按矩阵推算', 8, (int) ($data['exam_score'] ?? 0));   // 1×2 + 2×3
    $t->assertSame('模式为 random', 'random', (string) ($data['paper_mode'] ?? ''));
});

$t->guard('random 模式：出题按矩阵抽题', function () use ($t, &$randomExamId) {
    if ($randomExamId <= 0) { $t->assertTrue('跳过', true); return; }
    markEntered($randomExamId, Fixture::STU_A);
    $res = Http::post("/api/teacher/exams/{$randomExamId}/generate", [], $GLOBALS['H']);
    $t->assertSame('出题 200', 200, $res['status']);
    $r = Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?',
        [$randomExamId, Fixture::STU_A]
    );
    $t->assertSame('每人 3 题', 3, (int) ($r['c'] ?? 0));
});

/* ==================== 2. manual 手动选题 ==================== */
$manualExamId = 0;
$manualIds = quizIdsOf($subjId, 'radio2', 3);
$t->assertTrue('取到 3 道单选题', count($manualIds) === 3, 'count=' . count($manualIds));

$t->guard('manual 模式：创建并落库', function () use ($t, &$manualExamId, $manualIds) {
    $res = createExamViaApi([
        'radio2_val' => 3,          // 手动选题同样需要每题分值
        'paper_mode' => 'manual',
        'manual_ids' => $manualIds,
    ], $GLOBALS['H']);
    $t->assertSame('创建 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $manualExamId = (int) ($data['id'] ?? 0);
    $GLOBALS['createdExams'][] = $manualExamId;

    $t->assertSame('模式为 manual', 'manual', (string) ($data['paper_mode'] ?? ''));
    $t->assertSame('满分 = 3 题 × 3 分', 9, (int) ($data['exam_score'] ?? 0));

    $rows = Database::fetchAll('SELECT quiz_id FROM `exam_manual_quiz` WHERE exam_id = ? ORDER BY sort', [$manualExamId]);
    $stored = array_map(static fn (array $r): int => (int) $r['quiz_id'], $rows);
    $t->assertSame('明细落库数量一致', $manualIds, $stored);

    // 详情回读走 show()：save() 只返回 examinfo 主行，组卷明细由 show() 附加
    $show = Http::get("/api/teacher/exams/{$manualExamId}", $GLOBALS['H']);
    $t->assertSame('详情 200', 200, $show['status']);
    $info = (Http::data($show)['paper_mode_info'] ?? []);
    $t->assertSame('详情回读模式', 'manual', (string) ($info['mode'] ?? ''));
    $t->assertSame('详情回读题目', $manualIds, array_map('intval', $info['manual_ids'] ?? []));
});

$t->guard('manual 模式：出题取所选题目', function () use ($t, &$manualExamId, $manualIds) {
    markEntered($manualExamId, Fixture::STU_A);
    markEntered($manualExamId, Fixture::STU_B);
    $res = Http::post("/api/teacher/exams/{$manualExamId}/generate", [], $GLOBALS['H']);
    $t->assertSame('出题 200', 200, $res['status']);
    $rows = Database::fetchAll(
        'SELECT quiz_id FROM `stupaper` WHERE exam_id = ? AND stu_id = ? ORDER BY paper_id',
        [$manualExamId, Fixture::STU_A]
    );
    $got = array_map(static fn (array $r): int => (int) $r['quiz_id'], $rows);
    $t->assertSame('卷面题目与所选一致', $manualIds, $got);

    // 另一名考生应为同一份卷（手动选题所有考生同卷）
    $rows2 = Database::fetchAll(
        'SELECT quiz_id FROM `stupaper` WHERE exam_id = ? AND stu_id = ? ORDER BY paper_id',
        [$manualExamId, Fixture::STU_B]
    );
    $t->assertSame('同卷：第二名考生题目一致', $manualIds, array_map(static fn (array $r): int => (int) $r['quiz_id'], $rows2));
});

$t->guard('manual 模式：空选择被拒', function () use ($t) {
    $res = createExamViaApi(['paper_mode' => 'manual', 'manual_ids' => [], 'radio2_val' => 3], $GLOBALS['H']);
    $t->assertSame('空选择 400', 400, $res['status']);
});

$t->guard('manual 模式：跨科目题目被拒', function () use ($t, $subjId) {
    // 另找一个与当前考试科目不同的科目题目
    $row = Database::fetch('SELECT id FROM `quizlib` WHERE subj_id <> ? LIMIT 1', [$subjId]);
    if ($row === null) {
        $t->assertTrue('跳过：无其他科目题目', true);
        return;
    }
    $res = createExamViaApi([
        'paper_mode' => 'manual',
        'manual_ids' => [(int) $row['id']],
        'radio2_val' => 3,
    ], $GLOBALS['H']);
    $t->assertSame('跨科目 400', 400, $res['status']);
});

/* ==================== 3. by_kp 按知识点 ==================== */
$kpExamId = 0;
$t->guard('by_kp 模式：准备知识点数据', function () use ($t, $subjId, &$touchedQuizIds) {
    $idsA = array_slice(quizIdsOf($subjId, 'radio2', 6), 0, 3);
    $idsB = array_slice(quizIdsOf($subjId, 'radio1', 6), 0, 2);
    if (count($idsA) < 2 || count($idsB) < 2) {
        $t->assertTrue('跳过：题库不足', true);
        return;
    }
    $phA = implode(',', $idsA);
    $phB = implode(',', $idsB);
    Database::query("UPDATE `quizlib` SET quiz_kp = '" . KP_A . "' WHERE id IN ({$phA})");
    Database::query("UPDATE `quizlib` SET quiz_kp = '" . KP_B . "' WHERE id IN ({$phB})");
    $touchedQuizIds = array_merge($idsA, $idsB);
    $t->assertTrue('知识点已写入', true);
});

$t->guard('by_kp 模式：创建并落库', function () use ($t, &$kpExamId) {
    $res = createExamViaApi([
        'radio1_val' => 2,
        'radio2_val' => 3,
        'paper_mode' => 'by_kp',
        'kp_plan'    => [
            ['kp' => KP_A, 'diff' => '',  'cnt' => 2],
            ['kp' => KP_B, 'diff' => '',  'cnt' => 1],
        ],
    ], $GLOBALS['H']);
    $t->assertSame('创建 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $kpExamId = (int) ($data['id'] ?? 0);
    $GLOBALS['createdExams'][] = $kpExamId;

    $t->assertSame('模式为 by_kp', 'by_kp', (string) ($data['paper_mode'] ?? ''));
    $rows = Database::fetchAll('SELECT kp, diff, cnt FROM `exam_kp_plan` WHERE exam_id = ? ORDER BY kp', [$kpExamId]);
    $t->assertSame('知识点计划落库 2 条', 2, count($rows));

    $show = Http::get("/api/teacher/exams/{$kpExamId}", $GLOBALS['H']);
    $t->assertSame('详情 200', 200, $show['status']);
    $info = (Http::data($show)['paper_mode_info'] ?? []);
    $t->assertSame('详情回读模式', 'by_kp', (string) ($info['mode'] ?? ''));
    $t->assertSame('详情回读计划条数', 2, count($info['kp_plan'] ?? []));
});

$t->guard('by_kp 模式：出题按知识点抽题 + 满分回填', function () use ($t, &$kpExamId) {
    markEntered($kpExamId, Fixture::STU_A);   // 出题只面向已进入考场的考生
    $res = Http::post("/api/teacher/exams/{$kpExamId}/generate", [], $GLOBALS['H']);
    $t->assertSame('出题 200', 200, $res['status']);

    $rows = Database::fetchAll(
        'SELECT q.quiz_kp FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND sp.stu_id = ?',
        [$kpExamId, Fixture::STU_A]
    );
    $kps = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['quiz_kp'], $rows)));
    sort($kps);
    $expect = [KP_A, KP_B];
    sort($expect);
    $t->assertSame('卷面题目均来自计划知识点', $expect, $kps);
    $t->assertSame('题量 = 计划总量', 3, count($rows));

    $score = (int) (Database::fetch('SELECT exam_score FROM `examinfo` WHERE id = ?', [$kpExamId])['exam_score'] ?? 0);
    $t->assertTrue('满分在出题后被回填', $score > 0, "exam_score={$score}");
});

/* ==================== 4. 题库检索 / 知识点清单 ==================== */
$t->guard('题库检索：按题型过滤', function () use ($t, $subjId) {
    $res = Http::get("/api/teacher/quiz-search?subj_id={$subjId}&quiz_class=radio2&per_page=5", $GLOBALS['H']);
    $t->assertSame('检索 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $list = $data['list'] ?? [];
    $t->assertTrue('返回题目', count($list) > 0, 'count=' . count($list));
    $t->assertSame('题型过滤生效', 'radio2', (string) ($list[0]['quiz_class'] ?? ''));
    $t->assertTrue('不含正确答案', !array_key_exists('quiz_key', $list[0] ?? []), 'quiz_key 不应下发');
    $t->assertTrue('带题型标签', (string) ($list[0]['quiz_type_label'] ?? '') !== '');
});

$t->guard('题库检索：关键字命中', function () use ($t, $subjId) {
    $one = Database::fetch('SELECT quiz_title FROM `quizlib` WHERE subj_id = ? AND CHAR_LENGTH(quiz_title) > 4 LIMIT 1', [$subjId]);
    if ($one === null) { $t->assertTrue('跳过：无题干', true); return; }
    $kw = mb_substr((string) $one['quiz_title'], 0, 3);
    $res = Http::get('/api/teacher/quiz-search?subj_id=' . $subjId . '&per_page=5&keyword=' . rawurlencode($kw), $GLOBALS['H']);
    $t->assertSame('检索 200', 200, $res['status']);
    $list = Http::data($res)['list'] ?? [];
    $t->assertTrue('命中至少一条', count($list) > 0, "kw={$kw}");
});

$t->guard('知识点清单：返回临时写入的知识点', function () use ($t, $subjId) {
    $res = Http::get("/api/teacher/quiz-kps?subj_id={$subjId}", $GLOBALS['H']);
    $t->assertSame('清单 200', 200, $res['status']);
    $list = Http::data($res)['list'] ?? [];
    $names = array_map(static fn (array $r): string => (string) $r['kp'], $list);
    $t->assertTrue('含 KP_A', in_array(KP_A, $names, true), implode(',', $names));
    $t->assertTrue('含 KP_B', in_array(KP_B, $names, true));
});

/* ==================== 5. 管理端 ==================== */
$adminCsrf = '';
$t->guard('管理员登录', function () use ($t, &$adminCsrf) {
    $res = Http::post('/api/admin/login', ['username' => 'admin', 'password' => 'admin@2026']);
    $t->assertSame('登录 200', 200, $res['status']);
    $adminCsrf = (string) (Http::data($res)['csrf_token'] ?? '');
    $t->assertTrue('取到 CSRF', $adminCsrf !== '');
});
$AH = ['X-CSRF-Token' => $adminCsrf];

$t->guard('管理端：题库检索可用', function () use ($t, $subjId) {
    $res = Http::get("/api/admin/quiz-search?subj_id={$subjId}&per_page=5", $GLOBALS['AH']);
    $t->assertSame('检索 200', 200, $res['status']);
    $t->assertTrue('返回题目', count(Http::data($res)['list'] ?? []) > 0);
});

$t->guard('管理端：知识点清单可用', function () use ($t, $subjId) {
    $res = Http::get("/api/admin/quiz-kps?subj_id={$subjId}", $GLOBALS['AH']);
    $t->assertSame('清单 200', 200, $res['status']);
    $t->assertTrue('含 KP_A', in_array(KP_A, array_map(static fn (array $r): string => (string) $r['kp'], Http::data($res)['list'] ?? []), true));
});

$t->guard('管理端：保存手动选题考试并落库', function () use ($t, &$manualIds, $subjId, $classId) {
    $res = Http::post('/api/admin/exams', [
        'exam_name'        => Fixture::PREFIX . '管理端手动选卷',
        'exam_class'       => Fixture::PREFIX,
        'exam_category_id' => 0,
        'subj_id'          => $subjId,
        'exam_date'        => date('Y-m-d'),
        'exam_start_time'  => hhmm(3600),
        'exam_end_time'    => hhmm(7200),
        'exam_tea'         => '',
        'stu_class'        => $classId,
        'radio2_val'       => 4,
        'paper_mode'       => 'manual',
        'manual_ids'       => $manualIds,
    ], $GLOBALS['AH']);
    $t->assertSame('创建 200', 200, $res['status']);
    $id = (int) (Http::data($res)['id'] ?? 0);
    $GLOBALS['createdExams'][] = $id;
    $t->assertTrue('考试 ID 有效', $id > 0);

    $cnt = (int) (Database::fetch('SELECT COUNT(*) AS c FROM `exam_manual_quiz` WHERE exam_id = ?', [$id])['c'] ?? 0);
    $t->assertSame('管理端明细落库', 3, $cnt);
    $score = (int) (Database::fetch('SELECT exam_score FROM `examinfo` WHERE id = ?', [$id])['exam_score'] ?? 0);
    $t->assertSame('管理端满分 = 3 × 4', 12, $score);
});

/* ==================== 6. 清理 ==================== */
$t->guard('清理测试数据', function () use ($t, &$createdExams, &$touchedQuizIds) {
    pmCleanup($createdExams, $touchedQuizIds);
    Fixture::cleanup();
    $t->assertTrue('回收完成', true);
});

exit($t->finish());
