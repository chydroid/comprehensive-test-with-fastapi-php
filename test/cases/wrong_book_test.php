<?php
declare(strict_types=1);

/**
 * 错题本测试（A1）
 *
 * 验证：
 * - 在线练习答错 → 沉淀错题本（exam_type=exercise）；重复答错仅累加 wrong_count 不重复建行
 * - 答对 → 标记 mastered=1
 * - 正式考试判分（ExamEngine::autoGrade）自动沉淀 formal 错题，且幂等不重复计数
 * - 模拟考试交卷（gradeMock）自动沉淀 mock 错题，且重复交卷不重复计数
 * - GET /api/student/wrong-book 列表 + 统计正确
 * - GET /api/student/wrong-book/practice 抽题（不下发答案）
 * - POST /api/student/wrong-book/check 校验并标记掌握
 * - 错题本故障（RENAME 表）时，练习校验仍返回 200，业务不被打断
 * - 清理：测试数据按 stu_id 回收，模拟考试按 exam_id 回收
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;
use Test\Fixture;
use App\Models\Exam;
use App\Services\ExamEngine;
use App\Services\WrongBook;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

$t = new Harness();
echo "== 错题本测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('错题本', '数据库不可用');
    exit($t->finish());
}

const STU  = Fixture::STU_A;   // 9000001
const STU2 = Fixture::STU_B;   // 9000002
const PWD  = Fixture::PWD;     // testPwd123

$mockExamId = 0;

/** 确保测试考生存在（登录前必须存在） */
function ensureStudents(): void {
    foreach ([STU, STU2] as $i => $id) {
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
/** 回收本测试产生的错题本与模拟考试行 */
function wbCleanup(int &$mockExamId): void {
    \Core\Database::query('DELETE FROM `wrong_book` WHERE stu_id IN (?, ?)', [STU, STU2]);
    if ($mockExamId > 0) {
        \Core\Database::query('DELETE FROM `stupaper` WHERE exam_id = ?', [$mockExamId]);
        \Core\Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$mockExamId]);
        \Core\Database::query('DELETE FROM `examinfo` WHERE id = ?', [$mockExamId]);
        $mockExamId = 0;
    }
}
wbCleanup($mockExamId);
Fixture::cleanup();
ensureStudents();   // 必须在 Fixture::cleanup() 之后重建，否则 line 78 已把考生删掉

/* ---------- 学生登录 ---------- */
$csrf = '';
$t->guard('考生登录成功', function () use ($t, &$csrf) {
    $res = Http::post('/api/student/login', ['username' => STU, 'password' => PWD]);
    $t->assertSame('登录 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $csrf = (string) ($data['csrf_token'] ?? '');
    $t->assertTrue('取到 CSRF', $csrf !== '');
});

/** 取一道单选题（客观题，答案非空） */
function pickQuiz(): ?array {
    return \Core\Database::fetch(
        "SELECT id, quiz_class, quiz_key FROM `quizlib`
         WHERE quiz_class = 'radio2' AND quiz_key <> '' AND CHAR_LENGTH(quiz_key) = 1
         LIMIT 1"
    );
}

/* ---------- 1. 在线练习：答错沉淀、重复累加、答对标记掌握 ---------- */
$t->guard('练习答错沉淀错题本（exercise）', function () use ($t, $csrf) {
    $q = pickQuiz();
    $t->assertTrue('存在可练单选题', $q !== null);
    if ($q === null) return;
    $quizId = (int) $q['id'];
    $correct = (string) $q['quiz_key'];          // 如 'A'
    $wrong = $correct === 'A' ? 'B' : 'A';

    $r1 = Http::post('/api/exercise/answer', ['quiz_id' => $quizId, 'stu_key' => $wrong], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('练习校验 200', 200, $r1['status']);
    $t->assertSame('判定为答错', false, (bool) (Http::data($r1)['correct'] ?? true));

    $row = \Core\Database::fetch(
        'SELECT * FROM `wrong_book` WHERE stu_id = ? AND quiz_id = ?', [STU, $quizId]
    );
    $t->assertTrue('错题本已建行', $row !== null);
    $t->assertSame('来源为 exercise', 'exercise', $row['exam_type'] ?? '');
    $t->assertSame('首错 wrong_count=1', 1, (int) ($row['wrong_count'] ?? 0));

    // 重复答错：仅累加，不新建行
    Http::post('/api/exercise/answer', ['quiz_id' => $quizId, 'stu_key' => $wrong], ['X-CSRF-Token' => $csrf]);
    $row2 = \Core\Database::fetch(
        'SELECT wrong_count, COUNT(*) AS c FROM `wrong_book` WHERE stu_id = ? AND quiz_id = ?', [STU, $quizId]
    );
    $t->assertSame('重复答错仍只有一行', 1, (int) ($row2['c'] ?? 0));
    $t->assertSame('wrong_count 累加到 2', 2, (int) ($row2['wrong_count'] ?? 0));

    // 答对 → 标记掌握
    $r2 = Http::post('/api/exercise/answer', ['quiz_id' => $quizId, 'stu_key' => $correct], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('答对校验 200', 200, $r2['status']);
    $t->assertSame('判定为答对', true, (bool) (Http::data($r2)['correct'] ?? false));
    $row3 = \Core\Database::fetch(
        'SELECT mastered FROM `wrong_book` WHERE stu_id = ? AND quiz_id = ?', [STU, $quizId]
    );
    $t->assertSame('已标记为掌握', 1, (int) ($row3['mastered'] ?? 0));
});

/* ---------- 2. 正式考试判分自动沉淀（formal），幂等不重复计数 ---------- */
$t->guard('正式考试判分沉淀 formal 错题', function () use ($t) {
    $exam = Fixture::createExam();
    $t->assertTrue('考试创建成功', $exam !== null);
    if ($exam === null) return;
    $examId = (int) $exam['exam_id'];

    // 为该考生生成试卷
    ExamEngine::generatePaper($examId, STU);
    $papers = \Core\Database::fetchAll(
        'SELECT sp.paper_id, sp.quiz_id, q.quiz_class, q.quiz_key
         FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND sp.stu_id = ?',
        [$examId, STU]
    );
    $t->assertTrue('试卷非空', count($papers) > 0);

    // 全部填错答案（'ZZ'，明显非任何单字母正确答案），制造「全错」
    foreach ($papers as $p) {
        ExamEngine::saveAnswer($examId, STU, (int) $p['paper_id'], 'ZZ');
    }

    // 应沉淀的错题数 = 客观题且答案非空的数量（'ZZ' 不可能等于正确答案）
    $expected = 0;
    foreach ($papers as $p) {
        if ((string) $p['quiz_class'] !== 'longtext' && (string) ($p['quiz_key'] ?? '') !== '' && (string) ($p['quiz_key'] ?? '') !== 'ZZ') {
            $expected++;
        }
    }

    ExamEngine::autoGrade($examId, STU);
    $formalRows = \Core\Database::fetchAll(
        'SELECT * FROM `wrong_book` WHERE stu_id = ? AND exam_type = ?', [STU, 'formal']
    );
    $t->assertSame('formal 错题数=客观题数', $expected, count($formalRows));
    // 全错 → 成绩为 0
    $score = \Core\Database::fetch(
        'SELECT stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, STU]
    );
    $t->assertSame('全错成绩为 0', 0, (int) ($score['stu_score'] ?? -1));

    // 幂等：重复判分不应重复计数
    ExamEngine::autoGrade($examId, STU);
    $formalRows2 = \Core\Database::fetch(
        'SELECT COUNT(*) AS c FROM `wrong_book` WHERE stu_id = ? AND exam_type = ?', [STU, 'formal']
    );
    $t->assertSame('重复判分不新增错题行', $expected, (int) ($formalRows2['c'] ?? 0));
});

/* ---------- 3. 模拟考试交卷自动沉淀（mock），重复交卷不重复计数 ---------- */
$t->guard('模拟考试交卷沉淀 mock 错题', function () use ($t, $csrf, &$mockExamId) {
    // 选一个至少有 2 道单选题的科目
    $subj = \Core\Database::fetch(
        "SELECT subj_id, COUNT(*) AS c FROM `quizlib`
         WHERE quiz_class = 'radio2' AND quiz_key <> '' GROUP BY subj_id HAVING c >= 2 LIMIT 1"
    );
    $t->assertTrue('存在可用于模拟的科目', $subj !== null);
    if ($subj === null) return;
    $subjId = (int) $subj['subj_id'];

    $start = Http::post('/api/exercise/mock/start', [
        'subj_id' => $subjId, 'radio2_count' => 2,
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('模拟开始 200', 200, $start['status']);
    $mockExamId = (int) ((Http::data($start) ?? [])['exam_id'] ?? 0);
    $t->assertTrue('拿到模拟考试 id', $mockExamId > 0);
    if ($mockExamId <= 0) return;

    // 不作答直接交卷（答案默认空 → 全错）
    $submit = Http::post('/api/exercise/mock/submit', ['exam_id' => $mockExamId], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('模拟交卷 200', 200, $submit['status']);

    $mockRows = \Core\Database::fetchAll(
        'SELECT * FROM `wrong_book` WHERE stu_id = ? AND exam_type = ?', [STU, 'mock']
    );
    $t->assertSame('mock 错题数=2', 2, count($mockRows));
    $t->assertTrue('mock 错题关联 exam_id', count(array_filter($mockRows, static fn ($r) => (int) $r['exam_id'] === $mockExamId)) === 2);

    // 重复交卷：成绩不覆盖，错题不重复计数
    Http::post('/api/exercise/mock/submit', ['exam_id' => $mockExamId], ['X-CSRF-Token' => $csrf]);
    $mockRows2 = \Core\Database::fetch(
        'SELECT COUNT(*) AS c FROM `wrong_book` WHERE stu_id = ? AND exam_type = ?', [STU, 'mock']
    );
    $t->assertSame('重复交卷不新增错题行', 2, (int) ($mockRows2['c'] ?? 0));
});

/* ---------- 4. 列表 + 统计 + 抽题 + 重练校验 ---------- */
$t->guard('错题本列表 / 统计 / 抽题 / 重练', function () use ($t, $csrf) {
    $list = Http::get('/api/student/wrong-book', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('列表 200', 200, $list['status']);
    $d = Http::data($list) ?? [];
    $t->assertTrue('列表 total > 0', ($d['total'] ?? 0) > 0, 'total=' . ($d['total'] ?? 0));
    $stats = $d['stats'] ?? [];
    $t->assertTrue('统计 total 与列表一致', ($stats['total'] ?? -1) === ($d['total'] ?? 0));
    $t->assertTrue('统计含按科目分布', isset($stats['by_subject']) && is_array($stats['by_subject']));

    // 过滤：仅未掌握
    $un = Http::get('/api/student/wrong-book?mastered=0', ['X-CSRF-Token' => $csrf]);
    $unD = Http::data($un) ?? [];
    $allUnmastered = true;
    foreach ($unD['list'] ?? [] as $it) {
        if ((int) $it['mastered'] !== 0) { $allUnmastered = false; break; }
    }
    $t->assertTrue('mastered=0 过滤生效', $allUnmastered);

    // 抽题（不下发答案）
    $prac = Http::get('/api/student/wrong-book/practice?limit=10', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('抽题 200', 200, $prac['status']);
    $pD = Http::data($prac) ?? [];
    $t->assertTrue('抽到题目', ($pD['total'] ?? 0) > 0);
    $q0 = ($pD['questions'] ?? [])[0] ?? null;
    $t->assertTrue('抽题不含答案', $q0 === null || !isset($q0['quiz_key']));

    // 重练校验：用一道未掌握题答对 → 标记掌握
    $target = null;
    foreach ($unD['list'] ?? [] as $it) {
        if ((int) $it['mastered'] === 0 && !empty($it['quiz_id'])) { $target = $it; break; }
    }
    if ($target !== null) {
        $ans = \Core\Database::fetch('SELECT quiz_class, quiz_key FROM `quizlib` WHERE id = ?', [(int) $target['quiz_id']]);
        $chk = Http::post('/api/student/wrong-book/check',
            ['quiz_id' => (int) $target['quiz_id'], 'stu_key' => $ans['quiz_key']],
            ['X-CSRF-Token' => $csrf]);
        $t->assertSame('重练校验 200', 200, $chk['status']);
        $t->assertSame('重练答对', true, (bool) (Http::data($chk)['correct'] ?? false));
        $m = \Core\Database::fetch('SELECT mastered FROM `wrong_book` WHERE stu_id = ? AND quiz_id = ?', [STU, (int) $target['quiz_id']]);
        $t->assertSame('重练后标记掌握', 1, (int) ($m['mastered'] ?? 0));
    }
});

/* ---------- 5. 熔断：错题本故障不打断练习业务 ---------- */
$t->guard('错题本故障不影响练习校验', function () use ($t, $csrf) {
    $q = pickQuiz();
    if ($q === null) return;
    $quizId = (int) $q['id'];
    $correct = (string) $q['quiz_key'];
    $wrong = $correct === 'A' ? 'B' : 'A';

    // 制造故障：改名表
    \Core\Database::query('RENAME TABLE `wrong_book` TO `wrong_book_bak_test`');
    try {
        $r = Http::post('/api/exercise/answer', ['quiz_id' => $quizId, 'stu_key' => $wrong], ['X-CSRF-Token' => $csrf]);
        $t->assertSame('表故障仍 200', 200, $r['status']);
        $t->assertSame('仍正确判定对错', false, (bool) (Http::data($r)['correct'] ?? true));
    } finally {
        // 还原表（清理本次测试可能误建的孤儿行）
        \Core\Database::query('DROP TABLE IF EXISTS `wrong_book`');
        \Core\Database::query('RENAME TABLE `wrong_book_bak_test` TO `wrong_book`');
    }
    $t->assertTrue('表已还原', \Core\Database::fetch("SHOW TABLES LIKE 'wrong_book'") !== null);
});

/* ---------- 清理 ---------- */
wbCleanup($mockExamId);
Fixture::cleanup();
\Core\Database::query('DELETE FROM `wrong_book` WHERE stu_id IN (?, ?)', [STU, STU2]);

exit($t->finish());
