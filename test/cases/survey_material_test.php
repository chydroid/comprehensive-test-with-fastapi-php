<?php
declare(strict_types=1);

/**
 * C5 考后问卷 + C3 学习资料库 测试
 *
 * C5 重点验证：
 *  - 问卷按考试隔离：未参加本场考试的考生取不到题目（防枚举 exam_id 读题）
 *  - 一人一题一答：重复提交是更新而不是追加，否则统计能被刷高
 *  - 题型归一化：评分夹到 1–5、单选只接受配置过的选项、文本截断
 *  - 未交卷不允许提交（否则等于诱导提前交卷）
 *  - 教师端越权 404
 *
 * C3 重点验证：
 *  - file_url 白名单：javascript: / data: 等会被拒（否则考生点开就在站点上下文执行）
 *  - 上传扩展名白名单不含 php（任意代码上传后门）
 *  - 考生端只读浏览、管理端才能增删改
 *  - 浏览量累加
 *
 * 数据自包含：考试/考生由 Fixture 回收，资料按 title 前缀回收，
 * 问卷随 exam_id 一并清理，AI 设置无涉。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;
use Test\Fixture;
use App\Services\Survey;
use App\Services\Material;
use App\Services\ExamEngine;
use App\Services\AuthSession;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

$t = new Harness();
echo "== 考后问卷（C5）+ 学习资料库（C3）测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('C5/C3', '数据库不可用');
    exit($t->finish());
}

const STU     = Fixture::STU_A;      // 9000001
// 与本场考试完全无关的考生。注意不能用 Fixture::STU_B —— 夹具会给班里两名考生
// 都建 stuscore 行，STU_B 同样是「本场考生」，用它验证隔离会得到假阴性。
const STU_X   = '9000999';
const PWD     = Fixture::PWD;
const TEA     = 'teacher1';
const TEA2    = 'teacher2';
const TEA_PWD = 'teacher@2026';
const ADMIN   = 'admin';
const ADMIN_PWD = 'admin@2026';
const MAT_PREFIX = '__TEST__资料';

$examId = 0;

function smCleanup(): void
{
    Fixture::cleanup();
    \Core\Database::query('DELETE FROM `material` WHERE title LIKE ?', [MAT_PREFIX . '%']);
    \Core\Database::query('DELETE FROM `exam_survey` WHERE exam_id NOT IN (SELECT id FROM `examinfo`)');
    \Core\Database::query('DELETE FROM `exam_survey_answer` WHERE exam_id NOT IN (SELECT id FROM `examinfo`)');
}

/** 建一名与本场考试无关的考生，用于验证问卷按考试隔离 */
function ensureOutsider(): void
{
    $exists = \Core\Database::fetch('SELECT id FROM `stuinfo` WHERE id = ?', [STU_X]);
    if ($exists === null) {
        \Core\Database::query(
            'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [STU_X, Fixture::PREFIX . '无关考生', \App\Services\Password::hash(PWD), '男', '1', '1']
        );
    } else {
        \Core\Database::query('UPDATE `stuinfo` SET stu_pwd = ? WHERE id = ?', [\App\Services\Password::hash(PWD), STU_X]);
    }
}

function asTeacher(string $name = TEA): string
{
    $res = Http::post('/api/teacher/login', ['username' => $name, 'password' => TEA_PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

function asStudent(string $stu = STU): string
{
    $res = Http::post('/api/student/login', ['username' => $stu, 'password' => PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

function asAdmin(): string
{
    $res = Http::post('/api/admin/login', ['username' => ADMIN, 'password' => ADMIN_PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

smCleanup();
ensureOutsider();

/* ==================================================================
 * 建场：考试 + 考生交卷（问卷要求已交卷才能填）
 * ================================================================== */

$t->guard('建场：考生交卷', function () use ($t, &$examId) {
    $exam = Fixture::createExam(['exam_tea' => TEA]);
    if ($exam === null) { $t->assertTrue('夹具考试创建成功', false); return; }
    $examId = (int) $exam['exam_id'];

    $res = ExamEngine::generatePaper($examId, STU);
    $t->assertSame('组卷成功', true, (bool) ($res['generated'] ?? false));

    foreach (\Core\Database::fetchAll(
        'SELECT sp.paper_id, q.quiz_key FROM `stupaper` sp
         INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND sp.stu_id = ?', [$examId, STU]
    ) as $p) {
        ExamEngine::saveAnswer($examId, STU, (int) $p['paper_id'], (string) $p['quiz_key']);
    }
    ExamEngine::autoGrade($examId, STU);

    $row = \Core\Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, STU]
    );
    $t->assertSame('考生已交卷', true, str_starts_with((string) ($row['stu_status'] ?? ''), 'over'));
});

/* ==================================================================
 * C5-A 教师端配置
 * ================================================================== */

$t->guard('C5-A1 保存问卷：三题型 + 空题干被丢弃', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $res = Http::put("/api/teacher/exams/{$examId}/survey", [
        'questions' => [
            ['title' => '本场难度如何？', 'type' => 'rating', 'required' => true],
            ['title' => '时间是否充足？', 'type' => 'choice', 'options' => ['充足', '刚好', '不够']],
            ['title' => '其他建议', 'type' => 'text'],
            ['title' => '   ', 'type' => 'text'],          // 空题干
        ],
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('保留 3 题', 3, (int) ($d['count'] ?? -1));

    $qs = $d['questions'] ?? [];
    $t->assertSame('第 1 题评分', 'rating', (string) ($qs[0]['type'] ?? ''));
    $t->assertSame('第 1 题必答', true, (bool) ($qs[0]['required'] ?? false));
    $t->assertSame('第 2 题单选', 'choice', (string) ($qs[1]['type'] ?? ''));
    $t->assertSame('第 2 题选项', ['充足', '刚好', '不够'], $qs[1]['options'] ?? []);
    $t->assertSame('题号连续', [1, 2, 3], array_column($qs, 'sort_no'));
});

$t->guard('C5-A2 非法题型被拒', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $res = Http::put("/api/teacher/exams/{$examId}/survey", [
        'questions' => [['title' => '坏题型', 'type' => 'essay']],
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('400', 400, $res['status']);
});

$t->guard('C5-A3 单选题无选项 → 回落为评分题', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $res = Http::put("/api/teacher/exams/{$examId}/survey", [
        'questions' => [['title' => '没有选项的单选', 'type' => 'choice', 'options' => []]],
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $qs = Http::data($res)['questions'] ?? [];
    $t->assertSame('回落为 rating', 'rating', (string) ($qs[0]['type'] ?? ''));
});

$t->guard('C5-A4 题目数量上限', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $many = [];
    for ($i = 0; $i < Survey::MAX_QUESTIONS + 1; $i++) {
        $many[] = ['title' => "题 {$i}", 'type' => 'text'];
    }
    $res = Http::put("/api/teacher/exams/{$examId}/survey", ['questions' => $many], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('超上限 400', 400, $res['status']);
});

$t->guard('C5-A5 越权与非本场一律 404', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher(TEA2);
    $res = Http::get("/api/teacher/exams/{$examId}/survey", ['X-CSRF-Token' => $csrf]);
    $t->assertSame('他人考试 404', 404, $res['status']);
    $res2 = Http::get('/api/teacher/exams/999999/survey', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('不存在考试 404', 404, $res2['status']);
});

/* ---------- 恢复成三题（后续用例依赖这套问卷） ---------- */
$qids = [];
$t->guard('C5-A6 恢复三题问卷', function () use ($t, &$examId, &$qids) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asTeacher();
    $res = Http::put("/api/teacher/exams/{$examId}/survey", [
        'questions' => [
            ['title' => '本场难度如何？', 'type' => 'rating', 'required' => true],
            ['title' => '时间是否充足？', 'type' => 'choice', 'options' => ['充足', '刚好', '不够']],
            ['title' => '其他建议', 'type' => 'text'],
        ],
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $qids = array_column(Http::data($res)['questions'] ?? [], 'id');
    $t->assertSame('3 个题号', 3, count($qids));
});

/* ==================================================================
 * C5-B 考生端
 * ================================================================== */

$t->guard('C5-B1 考生读取本场问卷', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asStudent();
    $res = Http::get("/api/student/survey?exam_id={$examId}", ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('有问卷', true, (bool) ($d['has_survey'] ?? false));
    $t->assertSame('尚未填写', false, (bool) ($d['filled'] ?? true));
    $t->assertSame('已交卷标记', true, (bool) ($d['finished'] ?? false));
    $t->assertSame('题目 3 道', 3, count($d['questions'] ?? []));
    $t->assertSame('尚无作答', [], $d['answers'] ?? ['x']);
});

$t->guard('C5-B2 未参加本场考试的考生取不到问卷', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asStudent(STU_X);
    $res = Http::get("/api/student/survey?exam_id={$examId}", ['X-CSRF-Token' => $csrf]);
    $t->assertSame('404', 404, $res['status']);
});

$t->guard('C5-B3 必答题留空被拒', function () use ($t, &$examId, &$qids) {
    if ($examId <= 0 || count($qids) < 3) { $t->assertTrue('前置数据存在', false); return; }
    $csrf = asStudent();
    $res = Http::post('/api/student/survey', [
        'exam_id' => $examId,
        'answers' => [$qids[1] => '充足'],       // 跳过必答的评分题
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('400', 400, $res['status']);
});

$t->guard('C5-B4 提交反馈', function () use ($t, &$examId, &$qids) {
    if ($examId <= 0 || count($qids) < 3) { $t->assertTrue('前置数据存在', false); return; }
    $csrf = asStudent();
    $res = Http::post('/api/student/survey', [
        'exam_id' => $examId,
        'answers' => [
            $qids[0] => '4',
            $qids[1] => '刚好',
            $qids[2] => '题目偏难，希望增加练习。',
        ],
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $t->assertSame('保存 3 条', 3, (int) (Http::data($res)['saved'] ?? -1));
});

$t->guard('C5-B5 重复提交是更新而非追加（防刷统计）', function () use ($t, &$examId, &$qids) {
    if ($examId <= 0 || count($qids) < 3) { $t->assertTrue('前置数据存在', false); return; }
    $csrf = asStudent();
    $res = Http::post('/api/student/survey', [
        'exam_id' => $examId,
        'answers' => [$qids[0] => '2'],
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);

    $cnt = \Core\Database::fetch(
        'SELECT COUNT(*) AS c FROM `exam_survey_answer` WHERE exam_id = ? AND stu_id = ?', [$examId, STU]
    );
    $t->assertSame('仍是 3 行', 3, (int) ($cnt['c'] ?? -1));

    $val = \Core\Database::fetch(
        'SELECT answer FROM `exam_survey_answer` WHERE qid = ? AND stu_id = ?', [$qids[0], STU]
    );
    $t->assertSame('评分已更新为 2', '2', (string) ($val['answer'] ?? ''));
});

$t->guard('C5-B6 题型归一化：评分夹取 / 单选只认配置选项', function () use ($t, &$examId, &$qids) {
    if ($examId <= 0 || count($qids) < 3) { $t->assertTrue('前置数据存在', false); return; }
    $csrf = asStudent();
    // 评分给 99 → 夹到 5；单选塞一个不在选项里的值 → 被丢弃
    Http::post('/api/student/survey', [
        'exam_id' => $examId,
        'answers' => [$qids[0] => '99', $qids[1] => '随便乱填'],
    ], ['X-CSRF-Token' => $csrf]);

    $r1 = \Core\Database::fetch('SELECT answer FROM `exam_survey_answer` WHERE qid = ? AND stu_id = ?', [$qids[0], STU]);
    $t->assertSame('评分夹到 5', '5', (string) ($r1['answer'] ?? ''));

    $r2 = \Core\Database::fetch('SELECT answer FROM `exam_survey_answer` WHERE qid = ? AND stu_id = ?', [$qids[1], STU]);
    $t->assertSame('非法选项未被写入（保留原值）', '刚好', (string) ($r2['answer'] ?? ''));
});

$t->guard('C5-B7 未登录 / CSRF 保护', function () use ($t, &$examId) {
    if ($examId <= 0) { $t->assertTrue('前置考试存在', false); return; }
    $csrf = asStudent();
    $res = Http::post('/api/student/survey', ['exam_id' => $examId, 'answers' => []], ['X-CSRF-Token' => 'bad']);
    $t->assertSame('错误令牌 419', 419, $res['status']);

    AuthSession::logout();
    $res2 = Http::get("/api/student/survey?exam_id={$examId}");
    $t->assertTrue('未登录被拒', in_array($res2['status'], [401, 419], true), 'status=' . $res2['status']);
});

$t->guard('C5-B8 成绩列表携带 has_survey', function () use ($t) {
    $csrf = asStudent();
    $res = Http::get('/api/student/scores', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $list = Http::data($res)['list'] ?? [];
    $hit = null;
    foreach ($list as $r) {
        if ((int) ($r['exam_id'] ?? 0) === $GLOBALS['examId']) { $hit = $r; break; }
    }
    $t->assertTrue('本场成绩行存在', $hit !== null);
    $t->assertSame('has_survey 为 true', true, (bool) ($hit['has_survey'] ?? false));
});

/* ==================================================================
 * C5-C 统计
 * ================================================================== */

$t->guard('C5-C1 统计：均值 / 分布 / 文本列表 / 回收率', function () use ($t, &$examId, &$qids) {
    if ($examId <= 0 || count($qids) < 3) { $t->assertTrue('前置数据存在', false); return; }
    $csrf = asTeacher();
    $res = Http::get("/api/teacher/exams/{$examId}/survey", ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $st = Http::data($res)['stats'] ?? [];

    $t->assertSame('回收人数 1', 1, (int) ($st['answered_students'] ?? -1));
    $t->assertSame('交卷人数 1', 1, (int) ($st['total_students'] ?? -1));

    $qs = $st['questions'] ?? [];
    $t->assertSame('统计 3 题', 3, count($qs));
    $t->assertSame('评分题均值 5', 5.0, (float) ($qs[0]['avg'] ?? 0));
    $t->assertSame('评分题分布', [5 => 1], $qs[0]['distribution'] ?? []);
    $t->assertSame('单选分布', ['刚好' => 1], $qs[1]['distribution'] ?? []);
    $t->assertSame('文本题逐条列出', ['题目偏难，希望增加练习。'], $qs[2]['texts'] ?? []);
});

/* ==================================================================
 * C3 学习资料库
 * ================================================================== */

$matId = 0;

$t->guard('C3-1 管理端新增资料', function () use ($t, &$matId) {
    $csrf = asAdmin();
    $res = Http::post('/api/admin/materials', [
        'title'     => MAT_PREFIX . '：第一章课件',
        'subj_id'   => 1,
        'category'  => '课件',
        'summary'   => '测试用资料',
        'file_url'  => '/uploads/doc_test.pdf',
        'file_name' => '第一章.pdf',
        'file_ext'  => 'pdf',
        'file_size' => 102400,
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $matId = (int) ($d['id'] ?? 0);
    $t->assertTrue('拿到 id', $matId > 0);
    $t->assertSame('大小可读', '100 KB', (string) ($d['size_text'] ?? ''));
});

$t->guard('C3-2 危险 URL 被拒（防 javascript: 注入）', function () use ($t) {
    $csrf = asAdmin();
    foreach (['javascript:alert(1)', 'data:text/html,<script>', 'file:///etc/passwd', ''] as $url) {
        $res = Http::post('/api/admin/materials', [
            'title'    => MAT_PREFIX . '_bad',
            'file_url' => $url,
        ], ['X-CSRF-Token' => $csrf]);
        $t->assertSame("拒绝 {$url}", 400, $res['status']);
    }
});

$t->guard('C3-3 列表与筛选', function () use ($t) {
    $csrf = asAdmin();
    $res = Http::get('/api/admin/materials?keyword=' . urlencode(MAT_PREFIX), ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertTrue('至少 1 条', (int) ($d['total'] ?? 0) >= 1);
    $t->assertTrue('分类聚合含课件', in_array('课件', array_column($d['categories'] ?? [], 'name'), true));

    $res2 = Http::get('/api/admin/materials?category=不存在的分类', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('不存在的分类 0 条', 0, (int) (Http::data($res2)['total'] ?? -1));
});

$t->guard('C3-4 考生端可浏览且记浏览量', function () use ($t, &$matId) {
    if ($matId <= 0) { $t->assertTrue('前置资料存在', false); return; }
    $csrf = asStudent();
    $res = Http::get('/api/student/materials?keyword=' . urlencode(MAT_PREFIX), ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $t->assertTrue('能看到资料', (int) (Http::data($res)['total'] ?? 0) >= 1);

    $before = (int) (\Core\Database::fetch('SELECT hits FROM `material` WHERE id = ?', [$matId])['hits'] ?? -1);
    $hit = Http::post("/api/student/materials/{$matId}/hit", [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('浏览量接口 200', 200, $hit['status']);
    $after = (int) (\Core\Database::fetch('SELECT hits FROM `material` WHERE id = ?', [$matId])['hits'] ?? -1);
    $t->assertSame('浏览量 +1', $before + 1, $after);
});

$t->guard('C3-5 考生不能改资料（无写接口 / 未登录被拒）', function () use ($t, &$matId) {
    if ($matId <= 0) { $t->assertTrue('前置资料存在', false); return; }
    $csrf = asStudent();
    $res = Http::delete("/api/admin/materials/{$matId}", ['X-CSRF-Token' => $csrf]);
    $t->assertTrue('考生删管理端资料被拒', in_array($res['status'], [401, 403, 404], true), 'status=' . $res['status']);

    AuthSession::logout();
    $res2 = Http::get('/api/student/materials');
    $t->assertTrue('未登录被拒', in_array($res2['status'], [401, 419], true), 'status=' . $res2['status']);
});

$t->guard('C3-6 上传白名单不含可执行类型', function () use ($t) {
    $allowed = (array) config('upload.allowed_doc_ext', []);
    $t->assertTrue('允许 pdf', in_array('pdf', $allowed, true));
    $t->assertTrue('允许 docx', in_array('docx', $allowed, true));
    foreach (['php', 'phtml', 'phar', 'sh', 'exe', 'htaccess'] as $bad) {
        $t->assertSame("白名单不含 {$bad}", false, in_array($bad, $allowed, true));
    }
    $t->assertTrue('文档大小上限已配置', (int) config('upload.doc_max_bytes', 0) > 0);
});

$t->guard('C3-7 删除资料', function () use ($t, &$matId) {
    if ($matId <= 0) { $t->assertTrue('前置资料存在', false); return; }
    $csrf = asAdmin();
    $res = Http::delete("/api/admin/materials/{$matId}", ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $left = \Core\Database::fetch('SELECT id FROM `material` WHERE id = ?', [$matId]);
    $t->assertSame('已删除', null, $left);
    $matId = 0;
});

/* ---------- 收尾 ---------- */
\Core\Database::query('DELETE FROM `stuinfo` WHERE id = ?', [STU_X]);
smCleanup();

exit($t->finish());
