<?php
declare(strict_types=1);

/**
 * AI 阅卷辅助测试（C4）
 *
 * 验证三件事，按重要性排序：
 *
 *  1. **建议分绝不能被当成成绩写入**（最重要）。本功能的价值在于「给教师一个起点」，
 *     一旦它能直接改分，教师复核就形同虚设。因此对每个接口都断言：调用前后
 *     stupaper.quiz_score 与 stuscore.stu_score 完全不变。
 *  2. **本地启发式可用且方向正确**。不要求它判得多准（那是模型的事），但要求
 *     「答得越全分越高」「抄题干得 0」「没参考答案不瞎给分」这些单调性成立。
 *  3. **外部依赖必须可降级**。模型服务没配 / 配了但连不上，都要静默落回启发式，
 *     而不是让批阅页报错；API Key 绝不能出现在公开设置里。
 *
 * 数据自包含：题库题以 __TEST__ 前缀插入并按 id 回收，考试/考生/答卷由 Fixture 回收。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;
use Test\Fixture;
use App\Models\Exam;
use App\Services\ExamEngine;
use App\Services\Setting;
use App\Services\Grading\GraderFactory;
use App\Services\Grading\HeuristicGrader;
use App\Services\Grading\RemoteGrader;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

$t = new Harness();
echo "== AI 阅卷辅助测试（C4） ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('AI 阅卷辅助', '数据库不可用');
    exit($t->finish());
}

const STU     = Fixture::STU_A;      // 9000001
const PWD     = Fixture::PWD;
const TEA     = 'teacher1';
const TEA2    = 'teacher2';
const TEA_PWD = 'teacher@2026';
const OBJ_VAL  = 5;
const SUBJ_VAL = 10;

/** AI 设置键：测试结束必须还原，否则会污染后续套件的降级路径 */
const AI_KEYS = ['ai_grading_enabled', 'ai_grading_endpoint', 'ai_grading_model', 'ai_grading_api_key'];

$createdQuizIds = [];
$expectedObj = 0;
$examId = 0;
$paperId = 0;

function aiCleanup(array &$quizIds): void
{
    Fixture::cleanup();
    foreach ($quizIds as $qid) {
        \Core\Database::query('DELETE FROM `quizlib` WHERE id = ?', [(int) $qid]);
    }
    $quizIds = [];
    // 还原 AI 设置：本套件会把开关打开，若不还原，其它套件里「未配置」的
    // 前提就不成立了（它们依赖降级到启发式）。
    $revert = [];
    foreach (AI_KEYS as $k) { $revert[$k] = null; }
    try { Setting::putMany($revert); } catch (\Throwable $e) { /* 忽略 */ }
    Setting::flush();
}

function asTeacher(string $name = TEA): string
{
    $res = Http::post('/api/teacher/login', ['username' => $name, 'password' => TEA_PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

/** 本场问答题的参考答案（同时用于组卷与启发式断言） */
const REF_TEXT = '参考答案要点：交卷后先自动判分客观题，问答题由教师人工批阅后重算总分。';

aiCleanup($createdQuizIds);

/* ==================================================================
 * A. 本地启发式
 * ================================================================== */

$g = new HeuristicGrader();
$full = 10;
$title = '请简述本系统的判分流程。';

$suggest = function (string $ans, string $ref = REF_TEXT, string $ttl = '') use ($g, $full, $title): array {
    return $g->suggest([
        'title'      => $ttl === '' ? $title : $ttl,
        'reference'  => $ref,
        'answer'     => $ans,
        'full_score' => $full,
    ]);
};

$t->guard('A1 与参考答案一致 → 满分', function () use ($t, $suggest, $full) {
    $r = $suggest(REF_TEXT);
    $t->assertSame('满分', $full, $r['score']);
    $t->assertSame('置信度 1', 1.0, $r['confidence']);
});

$t->guard('A2 未作答 → 0 分', function () use ($t, $suggest) {
    $r = $suggest('');
    $t->assertSame('0 分', 0, $r['score']);
    $t->assertSame('置信度 1', 1.0, $r['confidence']);
});

$t->guard('A3 无参考答案 → 不给分（null，而非 0）', function () use ($t, $suggest) {
    $r = $suggest('交卷后自动判分客观题，问答题人工批阅。', '');
    $t->assertSame('score 为 null', null, $r['score']);
    $t->assertTrue('理由说明无法判断', str_contains($r['reason'], '未设置参考答案'));
});

$t->guard('A4 抄题干 → 0 分', function () use ($t, $suggest) {
    $r = $suggest('请简述本系统的判分流程。请简述本系统的判分流程。');
    $t->assertSame('0 分', 0, $r['score']);
    $t->assertTrue('理由点明照抄', str_contains($r['reason'], '照抄题目'));
});

$t->guard('A5 答非所问 → 低分', function () use ($t, $suggest, $full) {
    $r = $suggest('今天天气很好，我中午吃了三碗饭。');
    $t->assertTrue('分数低于 30% 满分', $r['score'] !== null && $r['score'] < $full * 0.3,
        'score=' . var_export($r['score'], true));
});

$t->guard('A6 换措辞但要点齐全 → 中高分（术语按整词匹配）', function () use ($t, $g, $full) {
    // SYN/ACK 这类术语如果被拆成字符，换一种说法就会被判成没答 —— 这是本项目
    // （计算机类理论考核）最典型的误判场景，必须按整词匹配。
    $r = $g->suggest([
        'title'      => '简述 TCP 三次握手的过程。',
        'reference'  => '三次握手：客户端发送 SYN，服务端返回 SYN+ACK，客户端再发送 ACK。',
        'answer'     => '客户机先发出 SYN 报文，服务器回应 SYN 加 ACK 报文，最后客户机再发一次 ACK 确认。',
        'full_score' => 15,
    ]);
    $t->assertTrue('得分不低于 60% 满分', $r['score'] !== null && $r['score'] >= 9,
        'score=' . var_export($r['score'], true));
    $t->assertTrue('命中了术语要点', count($r['hits']) >= 2, 'hits=' . count($r['hits']));
});

$t->guard('A7 分数恒在 [0, 满分] 内', function () use ($t, $g) {
    $bad = [];
    foreach ([
        '短答' => '客观题自动判分。',
        '冗长无关' => str_repeat('这是一段与参考答案毫无关系的废话。', 20),
        '只抄一半' => '交卷后先自动判分客观题。',
        '纯符号' => '！！！？？？###',
    ] as $name => $ans) {
        $r = $g->suggest(['title' => 't', 'reference' => REF_TEXT, 'answer' => $ans, 'full_score' => 10]);
        if ($r['score'] === null) continue;
        if ($r['score'] < 0 || $r['score'] > 10) $bad[] = "{$name}={$r['score']}";
    }
    $t->assertSame('无越界分数', [], $bad);
});

$t->guard('A8 启发式永远可用', function () use ($t, $g) {
    $t->assertSame('isAvailable', true, $g->isAvailable());
    $t->assertSame('provider key', 'heuristic', $g->key());
});

/* ==================================================================
 * B. 工厂 / 设置 / 降级
 * ================================================================== */

$item = [
    'title'      => '简述 TCP 三次握手。',
    'reference'  => '客户端发送 SYN，服务端返回 SYN+ACK。',
    'answer'     => 'SYN，然后 SYN 加 ACK。',
    'full_score' => 10,
];

$t->guard('B1 未配置远程模型 → 使用本地启发式', function () use ($t, $item) {
    $remote = new RemoteGrader();
    $t->assertSame('远程不可用', false, $remote->isAvailable());
    $r = GraderFactory::suggest($item);
    $t->assertSame('provider', 'heuristic', $r['provider']);
    $t->assertSame('未降级', false, $r['degraded']);
});

$t->guard('B2 服务不可达 → 逐题降级且不抛异常', function () use ($t, $item) {
    Setting::putMany([
        'ai_grading_enabled'  => 1,
        // 端口 9（discard）必然连不上，用来模拟「模型服务挂了」
        'ai_grading_endpoint' => 'https://127.0.0.1:9/v1/chat/completions',
        'ai_grading_model'    => 'test-model',
        'ai_grading_api_key'  => 'sk-test-not-real',
    ]);
    Setting::flush();

    $t->assertSame('远程标记为可用', true, (new RemoteGrader())->isAvailable());

    $r = GraderFactory::suggest($item);
    $t->assertSame('已降级到启发式', 'heuristic', $r['provider']);
    $t->assertSame('降级标记', true, $r['degraded']);
    $t->assertTrue('展示名说明已降级', str_contains($r['provider_label'], '降级'));
    $t->assertTrue('仍然给出了分数', $r['score'] !== null);

    // 还原，避免影响后续断言
    $revert = [];
    foreach (AI_KEYS as $k) { $revert[$k] = null; }
    Setting::putMany($revert);
    Setting::flush();
});

$t->guard('B3 API Key 不进公开设置', function () use ($t) {
    Setting::putMany(['ai_grading_api_key' => 'sk-secret-value']);
    Setting::flush();
    $t->assertSame('管理端可读', 'sk-secret-value', Setting::get('ai_grading_api_key'));
    $pub = Setting::publicSubset();
    $t->assertSame('公开子集不含 api_key', false, array_key_exists('ai_grading_api_key', $pub));
    Setting::putMany(['ai_grading_api_key' => null]);
    Setting::flush();
    $t->assertSame('撤销后回落默认', '', Setting::get('ai_grading_api_key'));
});

$t->guard('B4 新增 AI 设置项已纳入 schema', function () use ($t) {
    $schema = Setting::adminSchema();
    $keys = array_column($schema['fields'], 'key');
    foreach (AI_KEYS as $k) {
        $t->assertTrue("{$k} 在 schema 中", in_array($k, $keys, true));
    }
    $t->assertTrue('存在 ai 分组', isset($schema['groups']['ai']));
    // secret 标记必须下发，否则前端会把它渲染成普通文本框
    $found = null;
    foreach ($schema['fields'] as $f) {
        if ($f['key'] === 'ai_grading_api_key') { $found = $f; break; }
    }
    $t->assertSame('api_key 标记为 secret', true, $found['secret'] ?? null);
});

$t->guard('B5 string 设置项超长被拒', function () use ($t) {
    $threw = false;
    try {
        Setting::putMany(['ai_grading_model' => str_repeat('x', 101)]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $t->assertSame('超过 100 字符被拒', true, $threw);
    Setting::putMany(['ai_grading_model' => null]);
    Setting::flush();
});

/* ==================================================================
 * C. 接口层（真实 HTTP 全链路）
 * ================================================================== */

$t->guard('C1 建场：问答题入卷并交卷', function () use ($t, &$examId, &$createdQuizIds, &$expectedObj) {
    $exam = Fixture::createExam(['exam_tea' => TEA]);
    if ($exam === null) { $t->assertTrue('夹具考试创建成功', false); return; }
    $examId = (int) $exam['exam_id'];

    $row = \Core\Database::fetch('SELECT * FROM `examinfo` WHERE id = ?', [$examId]);
    $subjId = (int) $row['subj_id'];
    $diffField = 'mid';
    $diffCode = 'Z';
    foreach (['easy' => 'Y', 'mid' => 'Z', 'hard' => 'N'] as $f => $c) {
        if ((int) ($row["radio1_{$f}_sum"] ?? 0) > 0) { $diffField = $f; $diffCode = $c; break; }
    }

    \Core\Database::query(
        'INSERT INTO `quizlib` (subj_id, quiz_kp, quiz_title, quiz_class, quiz_option, quiz_key, quiz_diff)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$subjId, '', Fixture::PREFIX . '问答题：请简述本系统的判分流程。', 'longtext', '', REF_TEXT, $diffCode]
    );
    $qid = (int) \Core\Database::lastInsertId();
    $createdQuizIds[] = $qid;

    \Core\Database::query(
        "UPDATE `examinfo` SET `longtext_{$diffField}_sum` = 1, `longtext_val` = ?, `exam_score` = ? WHERE id = ?",
        [SUBJ_VAL, 4 * OBJ_VAL + SUBJ_VAL, $examId]
    );

    $res = ExamEngine::generatePaper($examId, STU);
    $t->assertSame('组卷成功', true, (bool) ($res['generated'] ?? false));

    $papers = \Core\Database::fetchAll(
        'SELECT sp.paper_id, sp.quiz_class, q.quiz_key
         FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND sp.stu_id = ?', [$examId, STU]
    );
    foreach ($papers as $p) {
        $ans = (string) $p['quiz_class'] === 'longtext'
            ? '交卷后先自动判分客观题，问答题由教师人工批阅后重算总分。'
            : (string) $p['quiz_key'];
        ExamEngine::saveAnswer($examId, STU, (int) $p['paper_id'], $ans);
    }
    ExamEngine::autoGrade($examId, STU);

    $scoreRow = \Core\Database::fetch(
        'SELECT stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, STU]
    );
    $expectedObj = (int) ($scoreRow['stu_score'] ?? 0);
    $t->assertTrue('已交卷并判分', $expectedObj >= 0);
});

$t->guard('C2 建议接口返回结构与来源标注', function () use ($t, &$examId, &$paperId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $res = Http::post("/api/teacher/exams/{$examId}/subjective/" . STU . "/suggest", [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('适用题目 1 道', 1, (int) ($d['applicable'] ?? -1));
    $t->assertSame('每题满分', SUBJ_VAL, (int) ($d['full_score'] ?? -1));
    $t->assertSame('provider 已标注', true, isset($d['provider']['key']));

    $items = $d['items'] ?? [];
    $t->assertSame('建议 1 条', 1, count($items));
    $it = $items[0] ?? [];
    $paperId = (int) ($it['paper_id'] ?? 0);
    $t->assertTrue('paper_id 有效', $paperId > 0);
    $t->assertTrue('含理由文本', (string) ($it['reason'] ?? '') !== '');
    $t->assertTrue('含置信度', isset($it['confidence']));
    $t->assertTrue('分数不超满分', $it['score'] === null || $it['score'] <= SUBJ_VAL);
});

$t->guard('C3 建议不写库（最重要）', function () use ($t, &$examId, &$paperId, &$expectedObj) {
    if ($examId <= 0 || $paperId <= 0) { $t->assertTrue('前置数据存在', false); return; }
    $row = \Core\Database::fetch(
        'SELECT quiz_score, graded_at FROM `stupaper` WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
        [$examId, STU, $paperId]
    );
    // 注意不能用 `$row['quiz_score'] ?? 'x'` 判空：?? 对值为 NULL 的键同样取默认值，
    // 会把「确实没批阅」误判成「查不到这一列」。必须区分「键不存在」与「值为 NULL」。
    $t->assertTrue(
        'quiz_score 仍为 NULL',
        array_key_exists('quiz_score', $row) && $row['quiz_score'] === null,
        'got=' . var_export($row['quiz_score'] ?? 'COLUMN_MISSING', true)
    );
    $t->assertTrue(
        'graded_at 仍为 NULL',
        array_key_exists('graded_at', $row) && $row['graded_at'] === null
    );

    $score = \Core\Database::fetch(
        'SELECT stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, STU]
    );
    $t->assertSame('总分未变', $expectedObj, (int) ($score['stu_score'] ?? -1));
});

$t->guard('C4 越权与他人考试一律 404', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher(TEA2);   // teacher2 不监考本场
    $res = Http::post("/api/teacher/exams/{$examId}/subjective/" . STU . "/suggest", [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('他人考试 404', 404, $res['status']);

    $res2 = Http::post("/api/teacher/exams/999999/subjective/" . STU . "/suggest", [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('不存在的考试 404', 404, $res2['status']);
});

$t->guard('C5 CSRF 保护', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $t->assertTrue('登录取到 CSRF', $csrf !== '');
    $res = Http::post("/api/teacher/exams/{$examId}/subjective/" . STU . "/suggest", [], ['X-CSRF-Token' => 'bad-token']);
    $t->assertSame('错误令牌 419', 419, $res['status']);
});

$t->guard('C6 已批阅的题目不再给建议', function () use ($t, &$examId, &$paperId) {
    if ($examId <= 0 || $paperId <= 0) { $t->assertTrue('前置数据存在', false); return; }
    $csrf = asTeacher();

    // 先人工批阅（走正常保存通道）
    $gr = Http::post("/api/teacher/exams/{$examId}/subjective/" . STU, [
        'items' => [['paper_id' => $paperId, 'score' => 6, 'comment' => '']],
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('批阅保存 200', 200, $gr['status']);

    $res = Http::post("/api/teacher/exams/{$examId}/subjective/" . STU . "/suggest", [], ['X-CSRF-Token' => $csrf]);
    $d = Http::data($res) ?? [];
    $t->assertSame('适用题目 0', 0, (int) ($d['applicable'] ?? -1));
    $t->assertSame('跳过已批阅 1 题', 1, (int) ($d['skipped_graded'] ?? -1));

    // 撤销，回到未批阅状态
    $rv = Http::post("/api/teacher/exams/{$examId}/subjective/" . STU . "/revoke", [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('撤销 200', 200, $rv['status']);
});

$t->guard('C7 未登录 401', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    \App\Services\AuthSession::logout();
    $res = Http::post("/api/teacher/exams/{$examId}/subjective/" . STU . "/suggest", []);
    $t->assertTrue('未登录被拒（401 或 419）', in_array($res['status'], [401, 419], true),
        'status=' . $res['status']);
});

/* ---------- 收尾 ---------- */
aiCleanup($createdQuizIds);

exit($t->finish());
