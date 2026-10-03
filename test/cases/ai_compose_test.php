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
    // 回收「难度兜底」用例建的临时科目（题已随上面的 quiz_writer 一并删除）
    \Core\Database::query("DELETE FROM `subject` WHERE subj_name LIKE '__TEST__难度%'");
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

function insertQuiz(int $subjId, string $type, string $diff, string $title, string $opt, string $key, string $kp = ''): int
{
    \Core\Database::query(
        'INSERT INTO `quizlib` (subj_id, quiz_title, quiz_class, quiz_option, quiz_key, quiz_diff, quiz_writer, quiz_time, quiz_kp, quiz_key_ok)
         VALUES (?, ?, ?, ?, ?, ?, \'__TEST__\', NOW(), ?, 1)',
        [$subjId, $title, $type, $opt, $key, $diff, $kp]
    );
    return \Core\Database::lastInsertId();
}

/**
 * 为「难度兜底」用例建一个独立科目 + 指定难度的题，返回科目 id。
 * 独立科目是关键：直接在业务科目上验证会受真实题库分布干扰（真实题难度多为单一）。
 *
 * @param array<int,array{0:string,1:string,2?:string}> $spec [题型, 难度, 知识点]
 */
function testSubject(string $name, array $spec): int
{
    $existing = \Core\Database::fetch('SELECT id FROM `subject` WHERE subj_name = ?', [$name]);
    if (is_array($existing)) {
        (new \App\Models\Subject())->delete((int) $existing['id']);
    }
    $subjId = (new \App\Models\Subject())->create([
        'subj_name' => $name,
        'subj_info' => '__TEST__ 临时科目',
    ]);
    foreach ($spec as $i => $one) {
        insertQuiz(
            (int) $subjId,
            (string) $one[0],
            (string) $one[1],
            '__TEST__' . $name . '_' . $i,
            'A.一|B.二',
            'A',
            (string) ($one[2] ?? '')
        );
    }
    return (int) $subjId;
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

/* ==================================================================
 * F. 难度缺档兜底（2026-10-03 修 BUG-265）
 *
 * 背景：draw() 此前严格按 quiz_diff 精确过滤，某档没题就返回 0 且**不借用其他难度**。
 * 而真实题库里绝大多数是「难度单一」的（本项目 994 道旧题全为 Z），于是教师要 10 题
 * 只拿到 3-4 题，前端还标「题库题量不足，已截断」——实际不是题量不足而是难度只有一档。
 * 现在该档抽不够时按就近难度逐级放宽补足，并如实上报借用题数。
 * ================================================================== */
$composer = new \App\Services\Composition\LocalComposer();

$t->guard('F-1 难度齐全时精确匹配，不借用', function () use ($t, $composer) {
    // 用 __TEST__ 题（Y/Z/N 三档齐全）建一个专用科目
    $sid = testSubject('__TEST__难度三档', [
        ['radio2', 'Y'], ['radio2', 'Z'], ['radio2', 'N'],
    ]);
    $r = $composer->compose(['subj_id' => $sid, 'easy' => 1, 'mid' => 1, 'hard' => 1]);
    $t->assertSame('三档各 1 题', 3, count($r['questions']));
    $t->assertSame('不应借用', 0, (int) ($r['diff_borrowed'] ?? -1));
    $t->assertSame('不应截断', false, (bool) $r['truncated']);
});

$t->guard('F-2 难度缺档时借用其他难度补足题量', function () use ($t, $composer) {
    // 只有 Z 的科目：题量必须够（6 道题要 6 道），只是难度只有一档。
    // 关键区分：**题量够但难度缺** 不该报截断；**题量不够** 才报（见 F-5）。
    $sid = testSubject('__TEST__难度单一', [
        ['radio2', 'Z'], ['radio2', 'Z'], ['radio2', 'Z'],
        ['radio2', 'Z'], ['radio2', 'Z'], ['radio2', 'Z'],
    ]);
    $r = $composer->compose(['subj_id' => $sid, 'easy' => 2, 'mid' => 2, 'hard' => 2]);
    $t->assertSame('题量够时应补满 6 题', 6, count($r['questions']));
    $t->assertSame('题量够则不应报截断', false, (bool) $r['truncated']);
    $t->assertTrue('借用数 > 0', (int) ($r['diff_borrowed'] ?? 0) > 0);
    $ids = array_map('intval', array_column($r['questions'], 'id'));
    $t->assertSame('借用不得产生重复', count($ids), count(array_unique($ids)));
});

$t->guard('F-2b 难度缺档 + 题量充足时，跨档补齐不空转', function () use ($t, $composer) {
    // 回归防线：draw() 早期版本不把已抽 id 传进 SQL，Y 档借来的 2 道会被
    // Z 档同一条 LIMIT 查询重复取回并撞 $seen 跳过，表现为「明明够题却少给」。
    // 题量=9（Y/Z/N 各 3），要 9 道题，断言必须给满 9 道。
    $sid = testSubject('__TEST__难度补齐', [
        ['radio2', 'Y'], ['radio2', 'Y'], ['radio2', 'Y'],
        ['radio2', 'Z'], ['radio2', 'Z'], ['radio2', 'Z'],
        ['radio2', 'N'], ['radio2', 'N'], ['radio2', 'N'],
    ]);
    $r = $composer->compose(['subj_id' => $sid, 'easy' => 3, 'mid' => 3, 'hard' => 3]);
    $t->assertSame('应给满 9 题', 9, count($r['questions']));
    $t->assertSame('不应截断', false, (bool) $r['truncated']);
    $t->assertSame('难度齐全不应借用', 0, (int) ($r['diff_borrowed'] ?? -1));
    $ds = [];
    foreach ($r['questions'] as $q) { $ds[(string) $q['difficulty']] = ($ds[(string) $q['difficulty']] ?? 0) + 1; }
    ksort($ds);
    $t->assertSame('难度分布 Y3/Z3/N3', ['N' => 3, 'Y' => 3, 'Z' => 3], $ds);
});

$t->guard('F-2c 只有单一难度且题量恰好=需求时补齐', function () use ($t, $composer) {
    // 回归防线（同上）：全 Z 库 3 道，Y/Z/N 各要 1 道，共 3 道。
    // 若不传 exclude，第 2、3 档会重复取回同一批题被 $seen 跳过，最终只得 1 道。
    $sid = testSubject('__TEST__难度单档补齐', [['radio2', 'Z'], ['radio2', 'Z'], ['radio2', 'Z']]);
    $r = $composer->compose(['subj_id' => $sid, 'easy' => 1, 'mid' => 1, 'hard' => 1]);
    $t->assertSame('应给满 3 题', 3, count($r['questions']));
    $t->assertSame('不应截断', false, (bool) $r['truncated']);
    $ids = array_map('intval', array_column($r['questions'], 'id'));
    $t->assertSame('无重复', count($ids), count(array_unique($ids)));
});

$t->guard('F-3 借用不能产生重复题', function () use ($t, $composer) {
    $sid = testSubject('__TEST__难度去重', [['radio2', 'Z'], ['radio2', 'Z'], ['radio2', 'Z']]);
    $r = $composer->compose(['subj_id' => $sid, 'easy' => 3, 'mid' => 3, 'hard' => 3]);
    $ids = array_map('intval', array_column($r['questions'], 'id'));
    $t->assertSame('无重复', count($ids), count(array_unique($ids)));
    $t->assertTrue('不超过库中题量', count($ids) <= 3);
});

$t->guard('F-4 借用题的 difficulty 必须是真实值（不谎报）', function () use ($t, $composer) {
    // 库中只有 Z，借来的题也必须显示 Z，不能谎报成 Y/N
    $sid = testSubject('__TEST__难度真实', [['radio2', 'Z'], ['radio2', 'Z']]);
    $r = $composer->compose(['subj_id' => $sid, 'easy' => 2, 'mid' => 0, 'hard' => 0]);
    foreach ($r['questions'] as $q) {
        $t->assertSame('difficulty 应为真实值 Z', 'Z', (string) $q['difficulty']);
    }
});

$t->guard('F-5 题量真不足时仍须标 truncated（不能假装够了）', function () use ($t, $composer) {
    $sid = testSubject('__TEST__难度不足', [['radio2', 'Z'], ['radio2', 'Z']]);
    $r = $composer->compose(['subj_id' => $sid, 'easy' => 10, 'mid' => 0, 'hard' => 0]);
    $t->assertTrue('题量不足应截断', (bool) $r['truncated']);
    $t->assertSame('有多少给多少', 2, count($r['questions']));
});

$t->guard('F-6 知识点/题型过滤在兜底后仍生效', function () use ($t, $composer) {
    $sid = testSubject('__TEST__难度过滤', [
        ['radio2', 'Z', 'KP甲'], ['radio2', 'Z', 'KP甲'], ['checkbox', 'Z', 'KP乙'],
    ]);
    $r = $composer->compose(['subj_id' => $sid, 'easy' => 5, 'mid' => 0, 'hard' => 0, 'kps' => ['KP甲'], 'types' => ['radio2']]);
    foreach ($r['questions'] as $q) {
        $t->assertSame('题型应为 radio2', 'radio2', (string) $q['type']);
        $t->assertSame('知识点应为 KP甲', 'KP甲', (string) $q['kp']);
    }
});

$t->guard('F-7 diff_borrowed 字段必须存在（前端依赖）', function () use ($t, $composer) {
    $sid = testSubject('__TEST__难度字段', [['radio2', 'Z']]);
    $r = $composer->compose(['subj_id' => $sid, 'easy' => 1]);
    $t->assertTrue('compose 返回 diff_borrowed', array_key_exists('diff_borrowed', $r));
});

/* ==================================================================
 * G. 远程模型返回的脏数据必须归一（LlmComposer）
 *
 * 背景：模型爱把选项写成 ['A. 匀速礼让行人', ...]、把答案写成「答案是A」、
 * 把判断题选项写成 ['错误','正确']。此前 normOptions 只按序重新分配 key 而不剥
 * 自带前缀 → 前端渲染成「A.A. 匀速礼让行人」；answer 原样入库 → 该题永远判错。
 * 这些脏数据会**被教师勾选后落库**，属真实数据污染，必须在解析层拦住。
 * ================================================================== */
$llm = new ReflectionClass(\App\Services\Composition\LlmComposer::class);
$mNormOptions  = $llm->getMethod('normOptions');       $mNormOptions->setAccessible(true);
$mNormJudge    = $llm->getMethod('normJudgeOptions');  $mNormJudge->setAccessible(true);
$mNormAnswer   = $llm->getMethod('normAnswer');        $mNormAnswer->setAccessible(true);

$t->guard('G-1 选项自带前缀必须剥离（否则显示 A.A. xxx）', function () use ($t, $mNormOptions) {
    $cases = [
        [['A. 匀速礼让行人', 'B. 强行超车'],      ['匀速礼让行人', '强行超车']],
        [['A、匀速礼让行人', 'B、强行超车'],      ['匀速礼让行人', '强行超车']],
        [['A: 匀速礼让行人', 'B: 强行超车'],      ['匀速礼让行人', '强行超车']],
        [['(A) 匀速礼让行人', '(B) 强行超车'],    ['匀速礼让行人', '强行超车']],
        [['（A）匀速礼让行人', '（B）强行超车'],  ['匀速礼让行人', '强行超车']],
        [['【A】匀速礼让行人', '【B】强行超车'],  ['匀速礼让行人', '强行超车']],
        [['1. 匀速礼让行人', '2. 强行超车'],      ['匀速礼让行人', '强行超车']],
        [['1、匀速礼让行人', '2、强行超车'],      ['匀速礼让行人', '强行超车']],
        // 模型也可能返回已带 key 的结构
        [[['key' => 'A', 'text' => 'A. 匀速礼让行人'], ['key' => 'B', 'text' => 'B. 强行超车']],
         ['匀速礼让行人', '强行超车']],
    ];
    foreach ($cases as $i => [$in, $want]) {
        $out = $mNormOptions->invoke(null, $in);
        $got = array_map(static fn ($o): string => (string) $o['text'], $out);
        $t->assertSame("第 " . ($i + 1) . " 组前缀已剥离", $want, $got);
        // key 必须按序重新分配
        $keys = array_map(static fn ($o): string => (string) $o['key'], $out);
        $t->assertSame("第 " . ($i + 1) . " 组 key 按序分配", ['A', 'B'], $keys);
    }
});

$t->guard('G-2 数字开头的正文不得被误当成序号剥掉', function () use ($t, $mNormOptions) {
    // 「3.5 千瓦时」的小数点不是序号；「2024 年的新规」也不是。
    $out = $mNormOptions->invoke(null, ['3.5 千瓦时的负载', '4.2 米']);
    $t->assertSame('小数不得被剥', '3.5 千瓦时的负载', $out[0]['text']);
    $out2 = $mNormOptions->invoke(null, ['2024 年的新规', '15 元']);
    $t->assertSame('年份不得被剥', '2024 年的新规', $out2[0]['text']);
});

$t->guard('G-3 答案口语化必须归一（否则该题永远判错）', function () use ($t, $mNormAnswer) {
    $cases = [
        ['A', 'A'], ['答案是A', 'A'], ['A. 匀速礼让行人', 'A'], ['（A）', 'A'],
        ['a', 'A'], ['  B  ', 'B'], ['答案：AC', 'AC'],
    ];
    foreach ($cases as [$in, $want]) {
        $t->assertSame("「{$in}」-> {$want}", $want, $mNormAnswer->invoke(null, 'radio2', $in, 4));
    }
});

$t->guard('G-4 答案字母越界必须拒收（防止脏数据落库）', function () use ($t, $mNormAnswer) {
    // 4 个选项却答 E → 整题不可用，必须返回 '' 让调用方丢弃
    $t->assertSame('越界字母 E 拒收', '', $mNormAnswer->invoke(null, 'radio2', 'E', 4));
    $t->assertSame('越界字母 F 拒收', '', $mNormAnswer->invoke(null, 'radio2', 'AF', 4));
    $t->assertSame('无字母拒收', '', $mNormAnswer->invoke(null, 'radio2', '不确定', 4));
});

$t->guard('G-5 多选答案去重升序（对齐判分语义）', function () use ($t, $mNormAnswer) {
    // Quiz::normalizeAnswer 认为 ABC≡CBA≡ACC，解析层就要先归一好
    $t->assertSame('ca -> AC', 'AC', $mNormAnswer->invoke(null, 'checkbox', 'ca', 4));
    $t->assertSame('ACC -> AC', 'AC', $mNormAnswer->invoke(null, 'checkbox', 'ACC', 4));
    $t->assertSame('CBA -> ABC', 'ABC', $mNormAnswer->invoke(null, 'checkbox', 'CBA', 4));
    $t->assertSame('答案是ABD -> ABD', 'ABD', $mNormAnswer->invoke(null, 'checkbox', '答案是ABD', 4));
});

$t->guard('G-6 主观题答案保留原文（不按字母映射）', function () use ($t, $mNormAnswer) {
    $t->assertSame('要点原文保留', '网络诈骗的防范措施', $mNormAnswer->invoke(null, 'longtext', '网络诈骗的防范措施', 0));
    $t->assertSame('多要点保留', '要点一；要点二', $mNormAnswer->invoke(null, 'longtext', '要点一；要点二', 0));
});

$t->guard('G-7 判断题选项必须归一为「正确|错误」', function () use ($t, $mNormJudge) {
    $mk = static fn (string $x, string $y): array => [['key' => 'A', 'text' => $x], ['key' => 'B', 'text' => $y]];
    foreach ([$mk('正确', '错误'), $mk('对', '错'), $mk('错误', '正确')] as $in) {
        [$opts] = $mNormJudge->invoke(null, $in, 'A');
        $texts = array_map(static fn ($o): string => (string) $o['text'], $opts);
        $t->assertSame('归一为标准两档', ['正确', '错误'], $texts);
    }
    // 只给一项 / 完全没给，都必须补齐两档
    [$o1] = $mNormJudge->invoke(null, [['key' => 'A', 'text' => '正确']], 'A');
    $t->assertSame('只给一项也补齐', ['正确', '错误'], array_map(static fn ($o): string => (string) $o['text'], $o1));
    [$o2] = $mNormJudge->invoke(null, [], 'A');
    $t->assertSame('没给选项也补齐', ['正确', '错误'], array_map(static fn ($o): string => (string) $o['text'], $o2));
});

$t->guard('G-8 判断题摆正选项时必须同步摆正答案（否则答案被改）', function () use ($t, $mNormJudge, $mNormAnswer) {
    $mk = static fn (string $x, string $y): array => [['key' => 'A', 'text' => $x], ['key' => 'B', 'text' => $y]];
    // 模型给了「错误|正确」且答案 A（指第一项=错误）→ 摆正后答案必须变 B
    [$opts, $ans] = $mNormJudge->invoke(null, $mk('错误', '正确'), 'A');
    $final = $mNormAnswer->invoke(null, 'radio1', $ans, count($opts));
    $t->assertSame('顺序反转后答案应为 B', 'B', $final);
    // 顺序本来就对时答案不变
    [$opts2, $ans2] = $mNormJudge->invoke(null, $mk('正确', '错误'), 'A');
    $t->assertSame('顺序正确时答案保持 A', 'A', $mNormAnswer->invoke(null, 'radio1', $ans2, count($opts2)));
    // 答案用中文写也要翻成字母
    [, $ans3] = $mNormJudge->invoke(null, [], '错误');
    $t->assertSame('中文答案翻成 B', 'B', $mNormAnswer->invoke(null, 'radio1', $ans3, 2));
});

/**
 * G-1~G-8 直接调私有方法，能覆盖边界但不覆盖**调用链**。
 * 此前「把 normAnswer 换成 trim()」这类注入后 97 条仍全绿 —— 因为没有一条测试
 * 走真实的 parse() 入口。故补本组：从「模型 HTTP 响应」一路到「可用题目对象」。
 */
$mParse = $llm->getMethod('parse');
$mParse->setAccessible(true);

/** 构造一条 OpenAI 兼容响应，内部 content 为给定 JSON 字符串 */
$llmResp = static function (string $content): array {
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
};

$t->guard('G-9 真实解析链路：脏选项/口语答案必须被归一', function () use ($t, $mParse, $llmResp) {
    // 模拟模型真实会返回的样子：选项带 A. 前缀、答案写成「答案是A」
    $raw = json_encode([
        ['type' => '单选题', 'stem' => '下列哪项正确？',
         'options' => ['A. 甲', 'B. 乙', 'C. 丙', 'D. 丁'],
         'answer' => '答案是A', 'difficulty' => '易'],
    ], JSON_UNESCAPED_UNICODE);
    $out = $mParse->invoke(null, $llmResp($raw), ['subj_id' => 2, 'subject_name' => '测试']);
    $t->assertSame('应解析出 1 题', 1, count($out));
    $q = $out[0];
    $t->assertSame('选项前缀已剥离', '甲', $q['options'][0]['text']);
    $t->assertSame('答案已归一', 'A', $q['answer']);
    $t->assertSame('key 仍按序分配', 'A', $q['options'][0]['key']);
    $t->assertSame('难度已归一', 'Y', $q['difficulty']);
    $t->assertTrue('标记为新题待审核', (bool) $q['new']);
});

$t->guard('G-10 真实解析链路：答案越界的题必须被丢弃而非落库', function () use ($t, $mParse, $llmResp) {
    // 4 个选项却答 E。若不做钳制，这题会以「quiz_key=E」落库 → 永远判错。
    $raw = json_encode([
        ['type' => '单选题', 'stem' => '越界答案题',
         'options' => ['A. 甲', 'B. 乙', 'C. 丙', 'D. 丁'],
         'answer' => 'E', 'difficulty' => '中'],
        // 这题合法，用于确认不是把整批都丢了
        ['type' => '单选题', 'stem' => '正常题',
         'options' => ['A. 甲', 'B. 乙'], 'answer' => 'B', 'difficulty' => '中'],
    ], JSON_UNESCAPED_UNICODE);
    $out = $mParse->invoke(null, $llmResp($raw), ['subj_id' => 2, 'subject_name' => '测试']);
    $t->assertSame('越界题应被丢弃，只剩 1 题', 1, count($out));
    $t->assertSame('留下的是合法那题', '正常题', $out[0]['stem']);
    $t->assertSame('合法题答案正确', 'B', $out[0]['answer']);
});

$t->guard('G-11 真实解析链路：判断题选项顺序反转须同步修正答案', function () use ($t, $mParse, $llmResp) {
    // 模型给了「错误|正确」但答案 A（指第一项）→ 摆正后答案必须变 B
    $raw = json_encode([
        ['type' => '判断题', 'stem' => '该项说法正确。',
         'options' => ['错误', '正确'], 'answer' => 'A', 'difficulty' => '易'],
    ], JSON_UNESCAPED_UNICODE);
    $out = $mParse->invoke(null, $llmResp($raw), ['subj_id' => 2, 'subject_name' => '测试']);
    $t->assertSame('应解析出 1 题', 1, count($out));
    $t->assertSame('选项已摆正为 正确|错误', ['正确', '错误'],
        array_map(static fn ($o): string => (string) $o['text'], $out[0]['options']));
    $t->assertSame('答案随选项同步修正为 B', 'B', $out[0]['answer']);
    // 判分必须自洽：选「错误」(=B) 应判对
    $t->assertTrue('按修正后的答案判分正确',
        \App\Models\Quiz::isCorrect('radio1', $out[0]['answer'], 'B'));
});

$t->guard('G-12 真实解析链路：全批不可用必须抛异常（不得静默返回空）', function () use ($t, $mParse, $llmResp) {
    // 若这里返回空数组而不抛，ComposerFactory 不会降级，教师只会看到「没有可用的建议题目」
    $raw = json_encode([
        ['type' => '单选题', 'stem' => '无选项', 'options' => [], 'answer' => 'A'],
    ], JSON_UNESCAPED_UNICODE);
    $threw = false;
    try {
        $mParse->invoke(null, $llmResp($raw), ['subj_id' => 2, 'subject_name' => '测试']);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $t->assertTrue('全批不可用时应抛 ComposerFailureException 以触发降级', $threw);
});

aiComposeCleanup($createdQuizIds);

exit($t->finish());
