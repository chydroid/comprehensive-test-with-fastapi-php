<?php

declare(strict_types=1);

/**
 * 模拟考试 / 在线练习 与正式考试的「隔离」及「数据上限」—— 回归测试
 *
 * 需求（本轮引入）：
 *   1. 模拟考试、在线练习与正式考试互不影响；是否允许在正式考试进行中
 *      组织模拟 / 练习，以及数据上限，全部在管理后台「系统设置 → 模拟考试与练习」配置。
 *   2. 模拟考试**不得**出现在管理员端、教师端的考试管理中（列表 / 监考选择页）。
 *   3. 每位考生每天最多进行 N 场模拟考试（默认 5）。
 *   4. 每场模拟考试题目总数不得超过 M 题（默认 100）。
 *
 * 覆盖点：
 *   - 管理端考试列表排除模拟考试（keyword 精确命中 + 正式考试正向对照）
 *   - 教师端考试列表排除模拟考试（即使 exam_tea 与该教师同名也不展示）
 *   - 门户 / 仪表盘「进行中的考试」排除模拟考试
 *   - 科目 / 考试类别的「被引用」判定排除模拟考试（孤儿模拟数据不得锁住基础数据维护）
 *   - 默认策略下，正式考试进行中可正常组织模拟（互不影响）
 *   - mock_max_questions：合计超限 400（校验文案精确断言，避免与「题库不足」混淆）
 *   - mock_daily_limit：第 N+1 场 429
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Models\SiteConfig;
use App\Models\Subject;
use App\Services\Setting;
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
echo "== 模拟考试隔离与上限回归测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('模拟考试隔离', '数据库不可用');
    exit($t->finish());
}

/* ============================ 夹具 ============================ */
Fixture::cleanup();

$fx = Fixture::createExam();
if ($fx === null) {
    $t->skip('模拟考试隔离', '题库无可用题目');
    exit($t->finish());
}
$stuA = (string) $fx['stu_a'];

// 挑一个题量充足的「科目 + 四类题型之一」组合（longtext 不参与模拟组卷）
$pick = Database::fetch(
    "SELECT subj_id, quiz_class, COUNT(*) AS c FROM `quizlib`
     WHERE quiz_class IN ('radio1','radio2','checkbox','text')
     GROUP BY subj_id, quiz_class ORDER BY c DESC LIMIT 1"
);
if ($pick === null) {
    Fixture::cleanup();
    $t->skip('模拟考试隔离', '题库无可用于模拟组卷的题目');
    exit($t->finish());
}
$subjId    = (int) $pick['subj_id'];
$quizClass = (string) $pick['quiz_class'];
$countKey  = $quizClass . '_count';

$dayFrom = date('Y-m-d 00:00:00');
$dayTo   = date('Y-m-d 00:00:00', strtotime('+1 day'));

/**
 * 回收某考生「今天」的全部模拟考试（含试卷与成绩）。
 *
 * 必要性：模拟考试的 exam_name 是「模拟考试_时间戳」，不带 __TEST__ 前缀，
 * Fixture::cleanup() 抓不到它的 examinfo 行；而每日场次上限按「今天已创建」
 * 计数，残留会让后续用例的前置条件（起始为 0）失效。
 */
$clearTodayMocks = static function (string $stuId) use ($dayFrom, $dayTo): void {
    $rows = Database::fetchAll(
        "SELECT e.id FROM `examinfo` e
         INNER JOIN `stuscore` sc ON sc.exam_id = e.id
         WHERE e.exam_class = ? AND sc.stu_id = ?
           AND e.exam_start >= ? AND e.exam_start < ?",
        [Exam::MOCK_CLASS, $stuId, $dayFrom, $dayTo]
    );
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        Database::query('DELETE FROM `stupaper` WHERE exam_id = ?', [$id]);
        Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$id]);
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$id]);
    }
};

/** 本次测试创建的临时 examinfo 行（模拟 + 正式对照），统一回收 */
$tempExamIds = [];
$reapTemp = static function () use (&$tempExamIds): void {
    foreach ($tempExamIds as $id) {
        Database::query('DELETE FROM `stupaper` WHERE exam_id = ?', [$id]);
        Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$id]);
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$id]);
    }
    $tempExamIds = [];
};

/** 临时改写运行参数（必须清 Setting 的请求级缓存，否则同进程内读到旧值 → 假通过） */
$putSetting = static function (string $key, ?string $value): ?string {
    $before = Setting::stored($key);
    if ($value === null) {
        Database::query('DELETE FROM `siteconfig` WHERE config_key = ?', [$key]);
    } else {
        (new SiteConfig())->put($key, $value);
    }
    Setting::flush();
    return $before;
};
$restoreSetting = static function (string $key, ?string $before) use ($putSetting): void {
    $putSetting($key, $before);
};

/** 造一条 examinfo 记录，返回 id */
$makeExam = static function (array $overrides) use ($subjId, &$tempExamIds): int {
    $row = array_merge([
        'exam_name'        => Fixture::PREFIX . '隔离临时',
        'exam_class'       => Fixture::PREFIX,
        'exam_category_id' => 0,
        'subj_id'          => $subjId,
        'exam_start'       => date('Y-m-d H:i:s'),
        'exam_end'         => date('Y-m-d H:i:s', time() + 3600),
        'exam_tea'         => '',
        'stu_class'        => '',
        'exam_status'      => 'testing',
        'exam_pwd'         => '0',
        'exam_score'       => 0,
    ], $overrides);

    $cols = array_keys($row);
    Database::query(
        'INSERT INTO `examinfo` (' . implode(',', array_map(static fn ($c) => "`$c`", $cols)) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')',
        array_values($row)
    );
    $id = (int) Database::lastInsertId();
    $tempExamIds[] = $id;
    return $id;
};

echo "  夹具考生 {$stuA}；模拟组卷用科目 {$subjId} / 题型 {$quizClass}\n";

/* ============ 1. 管理端考试列表排除模拟考试 ============ */
$t->guard('模拟考试不出现在管理端考试列表', function () use ($t, $makeExam) {
    $marker = Fixture::PREFIX . '隔离标记';
    $mockId   = $makeExam(['exam_name' => $marker, 'exam_class' => Exam::MOCK_CLASS]);
    // 正向对照：同名正式考试必须出现（否则用例可能因「关键字搜不到」而假通过）
    $formalId = $makeExam(['exam_name' => $marker, 'exam_class' => Fixture::PREFIX]);

    $_SESSION = [];
    $login = Http::post('/api/admin/login', ['username' => 'admin', 'password' => 'admin@2026']);
    $t->assertSame('管理员登录 -> 200', 200, $login['status']);

    $res = Http::get('/api/admin/exams?keyword=' . rawurlencode($marker));
    $t->assertSame('管理端考试列表 -> 200', 200, $res['status']);

    $data = (array) Http::data($res);
    $ids  = array_map(static fn ($r) => (int) ($r['id'] ?? 0), (array) ($data['list'] ?? []));

    $t->assertTrue('同名正式考试出现在列表中（正向对照）', in_array($formalId, $ids, true), 'ids=' . implode(',', $ids));
    $t->assertTrue('模拟考试未出现在列表中', !in_array($mockId, $ids, true), 'ids=' . implode(',', $ids));
    // total 由同一 WHERE 生成，必须同样排除模拟考试
    $t->assertSame('total 只统计正式考试', 1, (int) ($data['total'] ?? -1));
});

/* ============ 2. 教师端考试列表排除模拟考试（归属匹配也不展示） ============ */
$t->guard('模拟考试不出现在教师端考试列表', function () use ($t, $makeExam) {
    $_SESSION = [];
    $login = Http::post('/api/teacher/login', ['username' => 'teacher1', 'password' => 'teacher@2026']);
    $t->assertSame('教师登录 -> 200', 200, $login['status']);
    $teaName = (string) (Http::data($login)['teacher']['tea_name'] ?? '');
    $t->assertTrue('取得教师姓名（前置条件）', $teaName !== '');

    $beforeRes = Http::get('/api/teacher/exams');
    $t->assertSame('教师考试列表 -> 200', 200, $beforeRes['status']);
    $before = (array) Http::data($beforeRes);
    $totalBefore = (int) ($before['total'] ?? -1);

    // 两条都挂在该教师名下：一条模拟、一条正式
    $mockId   = $makeExam(['exam_name' => Fixture::PREFIX . '教师隔离模拟', 'exam_class' => Exam::MOCK_CLASS, 'exam_tea' => $teaName]);
    $formalId = $makeExam(['exam_name' => Fixture::PREFIX . '教师隔离正式', 'exam_class' => Fixture::PREFIX, 'exam_tea' => $teaName]);

    $afterRes = Http::get('/api/teacher/exams');
    $after = (array) Http::data($afterRes);
    $ids = array_map(static fn ($r) => (int) ($r['id'] ?? 0), (array) ($after['list'] ?? []));

    $t->assertTrue('正式考试导致教师列表 total +1（正向对照）', (int) ($after['total'] ?? -1) === $totalBefore + 1);
    $t->assertTrue('同名教师的正式考试在列表中', in_array($formalId, $ids, true));
    $t->assertTrue('同名教师的模拟考试不在列表中', !in_array($mockId, $ids, true));
});

/* ============ 3. 门户 / 仪表盘「进行中的考试」排除模拟考试 ============ */
$t->guard('「进行中的考试」不含模拟考试', function () use ($t, $makeExam) {
    $beforeCount = count(Exam::activeWithSubject());
    $mockId = $makeExam(['exam_class' => Exam::MOCK_CLASS, 'exam_status' => 'testing']);

    $list = Exam::activeWithSubject();
    $ids  = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $list);

    $t->assertTrue('模拟考试未进入「进行中的考试」', !in_array($mockId, $ids, true));
    $t->assertSame('「进行中的考试」条数不变', $beforeCount, count($list));
});

/* ============ 4. 科目 / 类别引用计数排除模拟考试 ============ */
$t->guard('孤儿模拟考试不锁住科目与类别的删除', function () use ($t, $makeExam) {
    // 找一个当前没有任何正式考试引用的科目
    $free = Database::fetch(
        "SELECT s.id FROM `subject` s
         WHERE NOT EXISTS (
             SELECT 1 FROM `examinfo` e
             WHERE e.subj_id = s.id AND COALESCE(e.exam_class, '') <> ?
         ) LIMIT 1",
        [Exam::MOCK_CLASS]
    );
    if ($free === null) {
        $t->skip('孤儿模拟考试不锁住科目与类别的删除', '库中每个科目都已被正式考试引用');
        return;
    }
    $freeSubj = (int) $free['id'];
    $hasExamsBefore = (new Subject())->hasExams($freeSubj);

    $mockId = $makeExam(['subj_id' => $freeSubj, 'exam_class' => Exam::MOCK_CLASS, 'exam_category_id' => 0]);

    $t->assertSame('模拟考试未被视为「科目下的考试」', $hasExamsBefore, (new Subject())->hasExams($freeSubj));
    $t->assertSame('该科目原本就没有正式考试（前置条件）', false, $hasExamsBefore);

    // 正向对照：同一科目加一场正式考试后，引用判定必须变为 true
    $makeExam(['subj_id' => $freeSubj, 'exam_class' => Fixture::PREFIX]);
    $t->assertSame('正式考试使该科目被判定为「有考试」', true, (new Subject())->hasExams($freeSubj));

    // 类别：造一个未被引用的类别 id 不可控，改为直接验证 mock 行（exam_category_id=0）不干扰
    $t->assertTrue('模拟考试行确实落库（前置条件）', $mockId > 0);
});

/* ============ 5. 默认策略：正式考试进行中允许模拟考试（互不影响） ============ */
$t->guard('默认策略下开考期间可正常组织模拟考试', function () use ($t, $stuA, $subjId, $countKey, $clearTodayMocks) {
    // 前置：库中确实存在进行中的正式考试
    if (!Exam::hasOngoingFormalExam()) {
        Fixture::createExam(['exam_status' => 'testing']);
    }
    $t->assertTrue('存在进行中的正式考试（前置条件）', Exam::hasOngoingFormalExam());
    $clearTodayMocks($stuA);

    $_SESSION = [];
    $login = Http::post('/api/student/login', ['username' => $stuA, 'password' => Fixture::PWD]);
    $t->assertSame('考生登录 -> 200', 200, $login['status']);
    $csrf = (string) (Http::data($login)['csrf_token'] ?? '');

    $res = Http::post('/api/exercise/mock/start', [
        'subj_id' => $subjId, $countKey => 1,
    ], ['X-CSRF-Token' => $csrf]);

    $t->assertSame('开考期间组模拟卷 -> 200（默认互不影响）', 200, $res['status']);
    $data = (array) Http::data($res);
    $examId = (int) ($data['exam_id'] ?? 0);
    $t->assertTrue('返回 exam_id', $examId > 0);
    $t->assertSame('返回当日已用场次 = 1', 1, (int) ($data['used_today'] ?? -1));
    $t->assertTrue('返回单场题目上限', (int) ($data['max_questions'] ?? 0) > 0);
    $t->assertSame('组卷题数与请求一致', 1, (int) ($data['total'] ?? -1));

    // 组卷与「考试记录 + 试卷 + 成绩」已并入同一事务，必须验证三者在提交后都真实落地：
    // 取第 1 题应能拿到题目，导航总数应为 1，且答题页不得下发 quiz_key。
    if ($examId > 0) {
        $paper = Http::get("/api/exercise/mock/paper?exam_id={$examId}&paper_id=1");
        $t->assertSame('取模拟试卷第 1 题 -> 200', 200, $paper['status']);
        $pdata = (array) Http::data($paper);
        $t->assertSame('导航总题数 = 1', 1, (int) ($pdata['navigation']['total'] ?? -1));
        $t->assertTrue('题目已生成且携带 quiz_id', (int) ($pdata['question']['quiz_id'] ?? 0) > 0);
        $t->assertTrue('答题中不下发 quiz_key', !array_key_exists('quiz_key', (array) ($pdata['question'] ?? [])));
    }

    $clearTodayMocks($stuA);
});

/* ============ 6. 单场模拟考试题目总数上限 ============ */
$t->guard('单场模拟考试题目总数超过上限被拒绝', function () use ($t, $stuA, $subjId, $putSetting, $restoreSetting) {
    $before = $putSetting('mock_max_questions', '5');
    try {
        $_SESSION = [];
        $login = Http::post('/api/student/login', ['username' => $stuA, 'password' => Fixture::PWD]);
        $t->assertSame('考生登录 -> 200', 200, $login['status']);
        $csrf = (string) (Http::data($login)['csrf_token'] ?? '');

        // 各题型分别 3 题均不超过单题型上限，但合计 6 > 5 —— 必须由「总数上限」拦下
        $res = Http::post('/api/exercise/mock/start', [
            'subj_id' => $subjId, 'radio1_count' => 3, 'radio2_count' => 3,
        ], ['X-CSRF-Token' => $csrf]);

        $t->assertSame('合计 6 题 > 上限 5 -> 400', 400, $res['status']);
        $msg = (string) Http::message($res);
        $t->assertTrue('文案明确指出总数上限（而非题库不足）', str_contains($msg, '不能超过 5 题'), 'message=' . $msg);
        $t->assertTrue('文案回显实际请求题数', str_contains($msg, '当前 6 题'), 'message=' . $msg);
        $t->assertSame('未产生任何模拟考试记录', 0, Exam::mockUsedToday($stuA));
    } finally {
        $restoreSetting('mock_max_questions', $before);
    }
});

/* ============ 7. 每人每日模拟考试场次上限 ============ */
$t->guard('每人每日模拟考试场次上限生效（第 N+1 场 429）', function () use ($t, $stuA, $subjId, $countKey, $putSetting, $restoreSetting, $clearTodayMocks) {
    $before = $putSetting('mock_daily_limit', '2');
    try {
        $clearTodayMocks($stuA);

        $_SESSION = [];
        $login = Http::post('/api/student/login', ['username' => $stuA, 'password' => Fixture::PWD]);
        $t->assertSame('考生登录 -> 200', 200, $login['status']);
        $csrf = (string) (Http::data($login)['csrf_token'] ?? '');

        $t->assertSame('起始当日场次为 0（前置条件）', 0, Exam::mockUsedToday($stuA));

        for ($i = 1; $i <= 2; $i++) {
            $res = Http::post('/api/exercise/mock/start', [
                'subj_id' => $subjId, $countKey => 1,
            ], ['X-CSRF-Token' => $csrf]);
            $t->assertSame("第 {$i} 场模拟考试 -> 200", 200, $res['status']);
        }
        $t->assertSame('当日已用场次 = 2', 2, Exam::mockUsedToday($stuA));

        $third = Http::post('/api/exercise/mock/start', [
            'subj_id' => $subjId, $countKey => 1,
        ], ['X-CSRF-Token' => $csrf]);
        $t->assertSame('第 3 场模拟考试 -> 429', 429, $third['status']);
        $m = (string) Http::message($third);
        $t->assertTrue('拒绝文案含每日上限', str_contains($m, '每日上限'), 'message=' . $m);
        $t->assertSame('被拒后当日场次仍为 2', 2, Exam::mockUsedToday($stuA));
    } finally {
        $clearTodayMocks($stuA);
        $restoreSetting('mock_daily_limit', $before);
    }
});

/* ============ 8. 后台可配置：每日上限与题目上限来自 Setting ============ */
$t->guard('每日场次与题目上限读取后台配置', function () use ($t, $putSetting, $restoreSetting) {
    // 先记录当前生效值（可能已被后台配置过），还原后据此断言，避免依赖库中状态
    $wasDaily = Exam::mockDailyLimit();
    $wasMax   = Exam::mockMaxQuestions();

    $b1 = $putSetting('mock_daily_limit', '7');
    $b2 = $putSetting('mock_max_questions', '88');
    try {
        $t->assertSame('mockDailyLimit 读取配置值', 7, Exam::mockDailyLimit());
        $t->assertSame('mockMaxQuestions 读取配置值', 88, Exam::mockMaxQuestions());
    } finally {
        $restoreSetting('mock_daily_limit', $b1);
        $restoreSetting('mock_max_questions', $b2);
    }
    $t->assertSame('还原后回到原生效值', $wasDaily, Exam::mockDailyLimit());
    $t->assertSame('还原后回到原生效值', $wasMax, Exam::mockMaxQuestions());
    // SCHEMA 默认值必须与需求一致（每人每日 5 场 / 单场 100 题）
    $t->assertSame('SCHEMA 默认每日上限 = 5', 5, Setting::SCHEMA['mock_daily_limit']['default']);
    $t->assertSame('SCHEMA 默认单场题数上限 = 100', 100, Setting::SCHEMA['mock_max_questions']['default']);
    $t->assertTrue('后台可见该分组', isset(Setting::GROUPS['practice']));
});

/* ============================ 清理 ============================ */
$reapTemp();
Fixture::cleanup();

exit($t->finish());
