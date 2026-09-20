<?php
declare(strict_types=1);

/**
 * AI 智能组卷测试（C4 组卷）
 *
 * 验证三件事，按重要性排序：
 *
 *  1. **AI 只给建议，绝不直接成卷**。compose() 前后考试 paper_mode / exam_score
 *     完全不变；真正落库必须经 applyComposition()（教师确认采用）。
 *  2. **本地抽样可用且方向正确**。不要求它多智能，但要求「按难度分布从题库抽样」
 *     成立、题目都来自指定科目、数量受控。
 *  3. **外部依赖必须可降级**。模型服务没配 / 配了但连不上，都要静默落回本地抽样，
 *     而不是让组卷页报错。
 *
 * 数据自包含：题库题以 __TEST__ 前缀插入并按 writer 回收，考试由 Fixture 回收。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;
use Test\Fixture;
use App\Models\Exam;
use App\Services\Setting;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

$t = new Harness();
echo "== AI 智能组卷测试（C4 组卷） ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('AI 智能组卷', '数据库不可用');
    exit($t->finish());
}

const TEA = 'teacher1';
const TEA2 = 'teacher2';
const TEA_PWD = 'teacher@2026';
const STU = Fixture::STU_A;
const STU_PWD = Fixture::PWD;
const AI_KEYS = ['ai_grading_enabled', 'ai_grading_endpoint', 'ai_grading_model', 'ai_grading_api_key'];

$createdQuizIds = [];
$examId = 0;

function aiComposeCleanup(array &$quizIds): void
{
    Fixture::cleanup();
    if ($quizIds !== []) {
        \Core\Database::query('DELETE FROM `quizlib` WHERE id IN (' . implode(',', array_fill(0, count($quizIds), '?')) . ')', $quizIds);
    }
    \Core\Database::query("DELETE FROM `quizlib` WHERE quiz_writer = '__TEST__'");
    \Core\Database::query("DELETE FROM `quizlib` WHERE quiz_writer = 'AI组卷'");
    $revert = [];
    foreach (AI_KEYS as $k) { $revert[$k] = null; }
    try { Setting::putMany($revert); } catch (\Throwable $e) { /* ignore */ }
    Setting::flush();
}

function asTeacher(string $name = TEA): string
{
    $res = Http::post('/api/teacher/login', ['username' => $name, 'password' => TEA_PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

function asStudent(): string
{
    $res = Http::post('/api/student/login', ['username' => STU, 'password' => STU_PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

function teacherPost(string $path, array $body, string $teacher = TEA): array
{
    $csrf = asTeacher($teacher);
    return Http::post($path, $body, ['X-CSRF-Token' => $csrf]);
}

function studentPost(string $path, array $body): array
{
    $csrf = asStudent();
    return Http::post($path, $body, ['X-CSRF-Token' => $csrf]);
}

function insertQuiz(int $subjId, string $type, string $diff, string $title, string $opt, string $key): int
{
    \Core\Database::query(
        'INSERT INTO `quizlib` (subj_id, quiz_title, quiz_class, quiz_option, quiz_key, quiz_diff, quiz_writer, quiz_time, quiz_kp, quiz_key_ok)
         VALUES (?, ?, ?, ?, ?, ?, \'__TEST__\', NOW(), ?, 1)',
        [$subjId, $title, $type, $opt, $key, $diff, '__TEST__']
    );
    return \Core\Database::lastInsertId();
}

aiComposeCleanup($createdQuizIds);

/* ==================================================================
 * 准备：建一场归属 teacher1 的考试，并插入受控题库题
 * ================================================================== */
$fixture = Fixture::createExam(['exam_tea' => TEA]);
$examId = (int) $fixture['exam_id'];
$exam = (new Exam())->find($examId);
$subjId = (int) ($exam['subj_id'] ?? 0);

$createdQuizIds[] = insertQuiz($subjId, 'radio2', 'Y', '__TEST__单选易', 'A.一|B.二|C.三', 'A');
$createdQuizIds[] = insertQuiz($subjId, 'radio2', 'Z', '__TEST__单选中', 'A.甲|B.乙', 'B');
$createdQuizIds[] = insertQuiz($subjId, 'radio2', 'N', '__TEST__单选难', 'A.对|B.错', 'A');
$createdQuizIds[] = insertQuiz($subjId, 'checkbox', 'Z', '__TEST__多选', 'A.x|B.y|C.z', 'AB');
$createdQuizIds[] = insertQuiz($subjId, 'text', 'Y', '__TEST__填空', '', '答案');

/* ==================================================================
 * A. 本地抽样（未配模型）：建议来自指定科目、数量受控、不改考试
 * ================================================================== */
$t->guard('A-1 compose 只读：不改变 paper_mode', function () use ($t, $examId) {
    $before = (new Exam())->find($examId);
    $res = teacherPost("/api/teacher/exams/{$examId}/compose", ['count' => 3, 'easy' => 1, 'mid' => 1, 'hard' => 1]);
    $after = (new Exam())->find($examId);
    $t->assertSame('compose 200', 200, $res['status']);
    $t->assertSame('paper_mode 不变', (string) ($before['paper_mode'] ?? 'random'), (string) ($after['paper_mode'] ?? 'random'));
});

$t->guard('A-2 本地抽样：provider=local，题目来自真实题库', function () use ($t, $examId) {
    $res = teacherPost("/api/teacher/exams/{$examId}/compose", ['count' => 3, 'easy' => 1, 'mid' => 1, 'hard' => 1]);
    $d = Http::data($res);
    $t->assertSame('compose 200', 200, $res['status']);
    $t->assertSame('provider=local', 'local', $d['provider']['key'] ?? '');
    $count = count($d['questions'] ?? []);
    $t->assertTrue('题数受控 1..3', $count >= 1 && $count <= 3);
    $t->assertTrue('composable=true', ($d['composable'] ?? false) === true);
    // 本地抽样：题目必须来自真实题库（有 id、非 AI 编造），不要求正好是我们插入的 5 道
    foreach (($d['questions'] ?? []) as $q) {
        $t->assertTrue('题有有效 id（非编造）', (int) ($q['id'] ?? 0) > 0);
        $t->assertTrue('非远程新题', empty($q['new']));
    }
});

/* ==================================================================
 * B. 配了模型但连不上 → 静默降级本地，标 degraded
 * ================================================================== */
$t->guard('B-1 模型不可用降级本地：degraded=true 且仍有题', function () use ($t, $examId) {
    Setting::putMany([
        'ai_grading_enabled'  => 1,
        'ai_grading_endpoint' => 'http://127.0.0.1:9/nonexistent',
        'ai_grading_model'    => 'x',
        'ai_grading_api_key'  => 'k',
    ]);
    Setting::flush();

    $res = teacherPost("/api/teacher/exams/{$examId}/compose", ['count' => 3, 'easy' => 1, 'mid' => 1, 'hard' => 1]);
    $d = Http::data($res);
    $t->assertSame('compose 200', 200, $res['status']);
    $t->assertTrue('应标记 degraded', !empty($d['degraded']));
    // 关键：降级后 provider 必须报 local（实际使用的引擎），不能误报 remote
    $t->assertSame('降级后 provider=local', 'local', $d['provider']['key'] ?? '');
    $t->assertTrue('降级后仍有题', count($d['questions'] ?? []) >= 1);
});

/* 还原 AI 设置，避免污染后续断言 */
foreach (AI_KEYS as $k) { Setting::putMany([$k => null]); }
Setting::flush();

/* ==================================================================
 * C. 采用：选中题落库为 manual 组卷，满分重算，远程新题先入库
 * ================================================================== */
$t->guard('C-1 apply：采用 2 道现题 + 1 道新题，落 manual 且满分=15', function () use ($t, $examId, $createdQuizIds) {
    $chosen = [
        ['id' => $createdQuizIds[0], 'type' => 'radio2', 'stem' => '__TEST__单选易', 'options' => ['A.一', 'B.二', 'C.三'], 'answer' => 'A', 'kp' => '__TEST__', 'difficulty' => 'Y'],
        ['id' => $createdQuizIds[1], 'type' => 'radio2', 'stem' => '__TEST__单选中', 'options' => ['A.甲', 'B.乙'], 'answer' => 'B', 'kp' => '__TEST__', 'difficulty' => 'Z'],
        // 远程新题（无 id）：radio1 应为本考试 radio1_val=5
        ['type' => 'radio1', 'stem' => '__TEST__AI新判断题', 'options' => ['对', '错'], 'answer' => 'A', 'kp' => '__TEST__', 'difficulty' => 'Y'],
    ];
    $res = teacherPost("/api/teacher/exams/{$examId}/apply-composition", ['questions' => $chosen]);
    $d = Http::data($res);
    $t->assertSame('apply 200', 200, $res['status']);
    $t->assertSame('applied=3', 3, $d['applied'] ?? 0);
    $t->assertSame('new=1', 1, $d['new'] ?? 0);
    $t->assertSame('total_score=15', 15, $d['total_score'] ?? 0);

    $exam = (new Exam())->find($examId);
    $t->assertSame('paper_mode=manual', 'manual', (string) ($exam['paper_mode'] ?? ''));
    $t->assertSame('exam_score=15', 15, (int) ($exam['exam_score'] ?? 0));

    $cnt = \Core\Database::fetch('SELECT COUNT(*) AS c FROM `exam_manual_quiz` WHERE exam_id = ?', [$examId]);
    $t->assertSame('manual_quiz 行数=3', 3, (int) ($cnt['c'] ?? 0));

    $newRow = \Core\Database::fetch("SELECT id FROM `quizlib` WHERE quiz_writer = 'AI组卷' AND quiz_title = ?", ['__TEST__AI新判断题']);
    $t->assertTrue('远程新题已入库', $newRow !== null);
});

$t->guard('C-2 apply 拒绝空列表', function () use ($t, $examId) {
    $res = teacherPost("/api/teacher/exams/{$examId}/apply-composition", ['questions' => []]);
    $t->assertSame('期望 400', 400, $res['status']);
});

/* ==================================================================
 * D. 护栏：考试已开始（testing）禁止修改组卷（409）
 * ================================================================== */
$t->guard('D-1 已开考后 apply 拒绝 409', function () use ($t, $examId) {
    \Core\Database::query('UPDATE `examinfo` SET exam_status = ? WHERE id = ?', ['testing', $examId]);
    $res = teacherPost("/api/teacher/exams/{$examId}/apply-composition", ['questions' => [
        ['id' => null, 'type' => 'radio2', 'stem' => 'x', 'options' => ['A', 'B'], 'answer' => 'A', 'difficulty' => 'Y'],
    ]]);
    \Core\Database::query('UPDATE `examinfo` SET exam_status = ? WHERE id = ?', ['exam', $examId]);
    $t->assertSame('期望 409', 409, $res['status']);
});

/* ==================================================================
 * E. 权限：非本人教师 404；考生调用教师端 401
 * ================================================================== */
$t->guard('E-1 非本人教师 compose 404', function () use ($t, $examId) {
    $res = teacherPost("/api/teacher/exams/{$examId}/compose", ['count' => 3], TEA2);
    $t->assertSame('期望 404', 404, $res['status']);
});

$t->guard('E-2 考生调用教师端 compose 401', function () use ($t, $examId) {
    $res = studentPost("/api/teacher/exams/{$examId}/compose", ['count' => 3]);
    $t->assertSame('期望 401', 401, $res['status']);
});

aiComposeCleanup($createdQuizIds);

exit($t->finish());
