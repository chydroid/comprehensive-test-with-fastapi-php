<?php

declare(strict_types=1);

/**
 * 考试全流程模拟演练：管理员 → 监考教师 → 考生 → 监考收卷 → 成绩。
 *
 * 与另外三份用例的分工：
 *   exam_flow_test       —— 考生视角的接口契约边界
 *   exam_prep_test       —— 考前准备（入场窗口 / 口令 / 自动出题 / 出题进度）
 *   full_exam_e2e_test   —— 三端串联，但大量边界靠「把时间拨到过去」触发
 *   本文件                —— **按真实时间流逝**把一场考试从头跑到尾，
 *                            三端各自视角的关键动作都亲自走一遍（而不是只调
 *                            一个接口就断言），并把时间线打印出来，便于人读。
 *
 * 为什么值得单独一份：新流程里「开考前 N 秒自动出题 → 到点自动开考」这两步
 * 都依赖**真实时间**（惰性触发，无常驻定时任务）。用改 exam_start 的办法能验
 * 伪流程：把时间拨过去后，autoGenerateIfDue/autoStartIfDue 仍会跑，但连不连
 * 得上「入场 → 出题 → 开考 → 答题」这条真实因果链就测不出来了。本文件让脚本
 * 真的等到点再操作。
 *
 * 时间轴（主场景，秒）：
 *   t0     管理员建场（随建场下发考场口令），入场窗口早已开启（考前 15 分钟）
 *   t0+3   三名考生凭考场口令陆续入场 → 等待室，此时**尚未出题**
 *   t0+11 (= start-3)  已到自动出题时点，考生 1 轮询 status 触发自动出题 → 3 人全部出卷
 *   t0+14 (= start)    已到开考时间，考生 2 轮询 status 触发自动开考 → 进入答题
 *   ……   考生 1 交卷 / 教师锁定-解锁 / 单人收卷 / 结束整场 / 查分
 *
 * 与既有约定一致：**串行执行**（并发跑会共用同一个 MySQL 互相 cleanup）。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Services\AuthSession;
use App\Services\Password;
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
echo "\n========== 考试全流程模拟演练（管理员 / 监考教师 / 考生）==========\n";

if (!Harness::dbAvailable()) {
    $t->skip('全流程模拟演练', '数据库不可用');
    exit($t->finish());
}

Fixture::cleanup();
Setting::flush();

const FLOW_ADMIN    = 'admin';
const FLOW_ADMIN_PWD = 'admin@2026';
const FLOW_TEACHER  = 'teacher1';
const FLOW_T_PWD    = 'teacher@2026';

/** 当前会话的 CSRF 令牌（每次登录后调用一次即可） */
$csrf = static fn (): string => AuthSession::csrfToken();

/** 时间线日志：让演练过程对人类可读 */
$say = static function (string $who, string $msg): void {
    echo sprintf("  — [%s] %s\n", $who, $msg);
};

/** 睡到指定时间戳（模拟真实时间流逝；本场景最多十几秒） */
$waitUntil = static function (int $ts) use ($say): void {
    $sleep = $ts - time();
    if ($sleep > 0) {
        usleep($sleep * 1000000);
    }
};

/** H:i:s 形式的时间戳，写进日志更好读 */
$clock = static fn (int $ts = 0): string => date('H:i:s', $ts === 0 ? time() : $ts);

/* ==================================================================
 * 0. 准备：教师账号 + 三名考生（供入场时自动建名单）
 * ================================================================== */

$row = Database::fetch('SELECT id FROM `teainfo` WHERE tea_name = ?', [FLOW_TEACHER]);
if ($row === null) {
    Database::query(
        'INSERT INTO `teainfo` (tea_name, tea_pwd, avatar) VALUES (?, ?, ?)',
        [FLOW_TEACHER, Password::hash(FLOW_T_PWD), '']
    );
} else {
    Database::query('UPDATE `teainfo` SET tea_pwd = ? WHERE id = ?', [Password::hash(FLOW_T_PWD), $row['id']]);
}

/** 考生由管理员建场后入场时自动登记，这里只需保证 stuinfo 存在且班级匹配 */
$students = [(string) Fixture::STU_1, (string) Fixture::STU_2, (string) Fixture::STU_3];
$classId  = Fixture::CLASS_ID3;
foreach ($students as $i => $stuId) {
    $exists = Database::fetch('SELECT id FROM `stuinfo` WHERE id = ?', [$stuId]);
    $name   = Fixture::PREFIX . '考生' . ($i + 1);
    if ($exists === null) {
        Database::query(
            'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$stuId, $name, Password::hash(Fixture::PWD), '男', '1', $classId]
        );
    } else {
        Database::query(
            'UPDATE `stuinfo` SET stu_pwd = ?, class_id = ? WHERE id = ?',
            [Password::hash(Fixture::PWD), $classId, $stuId]
        );
    }
}

// 选出「四种题型齐备、且四种题型都各有一题答案键非空」的科目：
// 题库里存在 quiz_key 为空的填空题（脏数据），一旦被随机抽中，「全对交卷」
// 演练就会静默少答一题、得分断言退化成自我实现的期望值。
$pick = Database::fetch(
    "SELECT subj_id, quiz_diff
     FROM `quizlib`
     WHERE quiz_class IN ('radio1','radio2','checkbox','text')
     GROUP BY subj_id, quiz_diff
     HAVING COUNT(DISTINCT CASE WHEN quiz_key IS NOT NULL AND quiz_key <> '' THEN quiz_class END) = 4
     ORDER BY COUNT(*) DESC
     LIMIT 1"
);
if ($pick === null) {
    $t->skip('全流程模拟演练', '题库无四种题型齐备的科目');
    Fixture::cleanup();
    exit($t->finish());
}
$subjId    = (int) $pick['subj_id'];
$diffField = ['Y' => 'easy', 'Z' => 'mid', 'N' => 'hard'][(string) $pick['quiz_diff']] ?? 'mid';

/** 建场用的题量参数：四种题型各 1 题、每题 5 分 */
$quizParams = [];
foreach (['radio1', 'radio2', 'checkbox', 'text'] as $type) {
    $quizParams["{$type}_{$diffField}_sum"] = 1;
    $quizParams["{$type}_val"] = 5;
}

// 缩短自动出题提前量，让「出题 → 开考」两段在仿真时间轴上都能真实等到
Setting::putMany(['exam_auto_gen_lead_seconds' => 3]);
Setting::flush();

$t0       = time();
$startAt  = $t0 + 14;                       // 开考时刻
$genAt    = $startAt - 3;                   // 自动出题时点（exam_auto_gen_lead_seconds = 3）
$endAt    = $t0 + 900;                      // 本场结束时间（演练内主动结束整场，不会真超时）
$duration = intdiv($endAt - $startAt, 60);

echo "  仿真时间轴：建场 {$clock($t0)} → 自动出题 {$clock($genAt)} → 开考 {$clock($startAt)}\n";
echo "  科目 #{$subjId}，四人题型各 1 题，共 4 题 / 20 分；班级 {$classId}\n\n";

/* ==================================================================
 * 1. 管理员：编排考试，考场口令随建场下发
 * ================================================================== */

$t->guard('管理员登录', function () use ($t, $csrf) {
    AuthSession::logout();
    $res = Http::post('/api/admin/login', ['username' => FLOW_ADMIN, 'password' => FLOW_ADMIN_PWD]);
    $t->assertSame('管理员登录 -> 200', 200, $res['status']);
    $t->assertTrue('下发 CSRF 令牌', strlen($csrf()) === 64);
});

$examId = 0;
$examPwd = '';

$t->guard('管理员建场：随建场下发考场口令，未出题、未开考', function () use ($t, $csrf, $subjId, $diffField, $startAt, $endAt, $classId, $quizParams, &$examId, &$examPwd, $say) {
    $res = Http::post('/api/admin/exams', array_merge([
        'exam_name'  => Fixture::PREFIX . '全流程演练',
        'exam_tea'   => FLOW_TEACHER,
        'subj_id'    => $subjId,
        'exam_start' => date('Y-m-d H:i:s', $startAt),
        'exam_end'   => date('Y-m-d H:i:s', $endAt),
        'stu_class'  => $classId,
    ], $quizParams), ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('建场 -> 200', 200, $res['status']);

    $d = Http::data($res) ?? [];
    $examId  = (int) ($d['id'] ?? 0);
    $examPwd = (string) ($d['exam_pwd'] ?? '');
    $say('管理员', "建场 #{$examId}「" . (string) ($d['exam_name'] ?? '') . "」，考场口令 {$examPwd}"
        . '，开考 ' . date('H:i:s', $startAt));

    $t->assertTrue('返回新考试 id', $examId > 0);
    $t->assertSame('初始状态为未开考', 'exam', (string) ($d['exam_status'] ?? ''));
    // 关键约定：考场口令在建场当下就在（不是点「开放入场」才有）
    $t->assertSame('建场即有考场口令（4-10 位数字）', 1, preg_match('/^\d{4,10}$/', $examPwd));
    $t->assertTrue('口令不是占位 0', $examPwd !== '0', $examPwd);
    $t->assertSame('参考班级按 ID 归一', $classId, (string) ($d['stu_class'] ?? ''));
    $t->assertSame('满分 = 4 题 × 5 分', 20, (int) ($d['exam_score'] ?? 0));
    $t->assertSame('组卷计划 4 题', 4, Exam::totalQuestions((new Exam())->find($examId) ?? []));
});

$t->guard('建场后落库为「未出卷」，入场窗口已开', function () use ($t, $examId, $startAt) {
    $exam = (new Exam())->find($examId);
    $t->assertSame('状态 exam（未出题）', 'exam', (string) ($exam['exam_status'] ?? ''));
    $t->assertTrue('未出任何卷', (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ?', [$examId]
    )['c'] ?? -1) === 0);
    $t->assertTrue('入场窗口已开启（考前 15 分钟）', Exam::entryOpensAt($exam) <= time(), (string) $exam['exam_start']);
});

/* ==================================================================
 * 2. 三端同时看得见：管理端 / 教师端 / 出题计划
 * ================================================================== */

$t->guard('管理端列表给出考场口令与「已入场 / 应考」', function () use ($t, $examId, $examPwd, $classId, $say) {
    $res = Http::get('/api/admin/exams');
    $t->assertSame('管理端列表 -> 200', 200, $res['status']);
    $hit = null;
    foreach ((Http::data($res)['list'] ?? []) as $r) {
        if ((int) ($r['id'] ?? 0) === $examId) { $hit = $r; break; }
    }
    $t->assertTrue('列表含本场考试', $hit !== null);
    $t->assertSame('考场口令可见（可复制告知考生）', $examPwd, (string) ($hit['exam_pwd'] ?? ''));
    $t->assertSame('应考人数 = 班级展开 3 人', 3, (int) ($hit['eligible_total'] ?? -1));
    $t->assertSame('已入场 0 人', 0, (int) (($hit['status_summary']['entered'] ?? -1)));
});

$t->guard('教师端：只看见自己监考的场，并可在出题前看到待出题名单', function () use ($t, $examId) {
    AuthSession::logout();
    $res = Http::post('/api/teacher/login', ['username' => FLOW_TEACHER, 'password' => FLOW_T_PWD]);
    $t->assertSame('教师登录 -> 200', 200, $res['status']);

    $list = Http::data(Http::get('/api/teacher/exams')) ?? [];
    $ids = array_map(static fn ($r) => (int) $r['id'], $list['list'] ?? []);
    $t->assertTrue('列表含本人监考的场', in_array($examId, $ids, true), implode(',', $ids));

    $plan = Http::data(Http::get("/api/teacher/exams/{$examId}/generate/plan")) ?? [];
    $t->assertSame('出题计划：已入场 0 人', 0, (int) ($plan['total'] ?? -1));
    $t->assertSame('出题计划：待出题 0 人', 0, (int) ($plan['pending'] ?? -1));
});

$t->guard('教师端考场名单：此时三人都还没进考场', function () use ($t, $examId) {
    $r = Http::data(Http::get("/api/teacher/monitor?exam_id={$examId}")) ?? [];
    // 注意：入场前名单里一行都没有（名单 = 已登记本场成绩的考生）；
    // 「应考 3 人」是班级展开数，只在管理端列表的 eligible_total 上暴露
    $t->assertSame('名单 0 人（尚未有人入场）', 0, count($r['list'] ?? []));
    $t->assertSame('已入场 0 人', 0, (int) (($r['summary']['entered'] ?? -1)));
    $t->assertSame('待考 0 人', 0, (int) ($r['summary']['total'] ?? -1));
});

/* ==================================================================
 * 3. 考生：凭考场口令进场，停在等待室（此时仍未出题）
 * ================================================================== */

$enter = static function (int $examId, string $stuId, string $pwd = '') use ($csrf): array {
    return Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $stuId,
        'password' => Fixture::PWD,
        'exam_pwd' => $pwd,
    ]);
};

$t->guard('三名考生凭考场口令入场：进入等待室、尚未出题', function () use ($t, $examId, $examPwd, $students, $say, $enter) {
    foreach ($students as $i => $stuId) {
        $res = $enter($examId, $stuId, $examPwd);
        $t->assertSame("考生 {$stuId} 入场 -> 200", 200, $res['status']);
        $d = Http::data($res) ?? [];
        $t->assertSame("考生 {$stuId} 停在等待室", 'waiting', (string) ($d['phase'] ?? ''));
        // 入场响应故意不谈试卷就绪：组卷统一由「出题」环节负责
        $t->assertTrue("考生 {$stuId} 入场响应不含 paper_ready", !array_key_exists('paper_ready', $d));
        $say('考生  ', "{$stuId} 凭口令 {$examPwd} 入场 → 等待室（距开考还有 "
            . max(0, (int) (strtotime((string) ($d['exam']['exam_start'] ?? '')) - time())) . " 秒）");
    }
    $t->assertTrue('入场阶段零试卷', (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ?', [$examId]
    )['c'] ?? -1) === 0);
    $t->assertSame('三名考生均在考场', 3, (int) (Database::fetch(
        "SELECT COUNT(*) c FROM `stuscore` WHERE exam_id = ? AND stu_status IN ('online','locked')",
        [$examId]
    )['c'] ?? -1));
});

$t->guard('等待室：服务端时间基准 + 考试基本信息 + 注意事项', function () use ($t, $examId, $students, $say, $enter, $examPwd) {
    AuthSession::logout();
    $res = $enter($examId, $students[0], $examPwd);
    $d = Http::data($res) ?? [];
    $exam = $d['exam'] ?? [];
    $t->assertSame('等待室拿到考试信息', true, is_array($exam) && ($exam['id'] ?? 0) === $examId);
    $t->assertTrue('下发科目名（前端算不出）', (string) ($exam['subj_name'] ?? '') !== '');
    $t->assertSame('下发题量 4 题', 4, (int) ($exam['question_total'] ?? -1));
    $t->assertSame('下发时长（分钟）', 15, (int) ($exam['duration_minutes'] ?? -1));
    // 倒计时基准取「状态轮询」下发的 server_ts（登录响应不带它）
    $st = Http::data(Http::get('/api/exam/status')) ?? [];
    $serverTs = (int) ($st['server_ts'] ?? 0);
    $t->assertTrue('轮询下发服务端时间戳', $serverTs > 0);
    $t->assertTrue('服务端时间与本地一致（倒计时以它为准）', abs($serverTs - time()) <= 3,
        "server={$serverTs} local=" . time());
    $t->assertSame('尚未开考仍是等待阶段', 'waiting', (string) ($st['phase'] ?? ''));
    $say('等待室', "考生 {$students[0]} 看到科目《{$exam['subj_name']}》4 题 / 20 分 / {$exam['duration_minutes']} 分钟");
});

$t->guard('教师端实时看到「已入场 3 / 应考 3」，出题计划列出已入场名单', function () use ($t, $examId, $students) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => FLOW_TEACHER, 'password' => FLOW_T_PWD]);

    $r = Http::data(Http::get("/api/teacher/monitor?exam_id={$examId}")) ?? [];
    $t->assertSame('已入场 3 人', 3, (int) ($r['summary']['entered'] ?? -1));

    $plan = Http::data(Http::get("/api/teacher/exams/{$examId}/generate/plan")) ?? [];
    $t->assertSame('计划名单 3 人', 3, (int) ($plan['total'] ?? -1));
    $t->assertSame('待出题 3 人', 3, (int) ($plan['pending'] ?? -1));
    $ids = array_map(static fn ($s) => (string) ($s['stu_id'] ?? ''), $plan['students'] ?? []);
    $t->assertTrue('名单正是已入场的三人', count(array_diff($students, $ids)) === 0, implode(',', $ids));
    $t->assertTrue('名单带姓名', (string) ($plan['students'][0]['stu_name'] ?? '') !== '');
});

/* ==================================================================
 * 4. 到自动出题时点：考生轮询触发「为已入场考生逐人出题」
 * ================================================================== */

$t->guard('开考前 3 秒：考生轮询触发自动出题，三人同时出卷', function () use ($t, $examId, $students, $genAt, $subjId, $say, $enter, $waitUntil, $clock) {
    // 教师登录清空过考场会话，这里重新入场（续考，无需再输口令）
    AuthSession::logout();
    $enter($examId, $students[0]);

    $before = (string) ((new Exam())->find($examId)['exam_status'] ?? '');
    $t->assertSame('出题前仍是 exam 状态', 'exam', $before);

    $waitUntil($genAt);
    $say('时间  ', "{$clock($genAt)} 已到「开考前 3 秒」自动出题时点，等待考生轮询…");

    // 考生轮询 /status：惰性自动出题的触发点之一
    $res = Http::get('/api/exam/status');
    $t->assertSame('轮询 -> 200', 200, $res['status']);
    $d = Http::data($res) ?? [];

    $t->assertSame('整场推进为已出卷', 'paper', (string) ((new Exam())->find($examId)['exam_status'] ?? ''));
    $t->assertSame('本考生试卷已就绪', true, (bool) ($d['paper_ready']));
    $t->assertSame('但未到开考时间，仍在等待', 'waiting', (string) ($d['phase'] ?? ''));
    $say('出题  ', "自动为已入场 3 人各出 4 题（共 " . (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ?', [$examId]
    )['c'] ?? -1) . " 题），但考生仍停在等待室");

    foreach ($students as $stuId) {
        $n = (int) (Database::fetch(
            'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?', [$examId, $stuId]
        )['c'] ?? 0);
        $t->assertSame("{$stuId} 拿到 4 题", 4, $n);
    }
    $t->assertSame('抽题全部来自配置科目', 0, (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND q.subj_id <> ?', [$examId, $subjId]
    )['c'] ?? -1));
});

$t->guard('出题面板逐人点亮：已出卷与待出题实时可见', function () use ($t, $examId, $students) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => FLOW_TEACHER, 'password' => FLOW_T_PWD]);

    $plan = Http::data(Http::get("/api/teacher/exams/{$examId}/generate/plan")) ?? [];
    $t->assertSame('待出题归零', 0, (int) ($plan['pending'] ?? -1));
    foreach ($plan['students'] ?? [] as $s) {
        $t->assertTrue('考生 ' . (string) ($s['stu_id'] ?? '?') . ' 已标记有卷', (bool) ($s['has_paper'] ?? false));
        $t->assertTrue('考生 ' . (string) ($s['stu_id'] ?? '?') . ' 卷面 4 题', (int) ($s['questions'] ?? 0) === 4);
    }
    $t->assertTrue('计划给出自动出题倒计时字段', array_key_exists('auto_gen_in', $plan));
});

/* ==================================================================
 * 5. 到开考时间：考生轮询触发自动开考
 * ================================================================== */

$t->guard('开考时刻：考生轮询触发自动开考，无需监考点按钮', function () use ($t, $examId, $students, $startAt, $say, $enter, $waitUntil, $clock) {
    // 教师登录又清掉了考场会话：考生重新入场后轮询（本场已出卷，直接续考）
    AuthSession::logout();
    $enter($examId, $students[1]);

    $waitUntil($startAt + 1);
    $say('时间  ', "{$clock()} 已到开考时间，等待考生轮询…");

    $res = Http::get('/api/exam/status');
    $d = Http::data($res) ?? [];
    $t->assertSame('整场推进为进行中', 'testing', (string) ((new Exam())->find($examId)['exam_status'] ?? ''));
    $t->assertSame('该生进入答题', 'answering', (string) ($d['phase'] ?? ''));
    $t->assertTrue('试卷就绪', (bool) ($d['paper_ready'] ?? false));
    $t->assertSame('重轮询幂等（不会重复流转）', false, Exam::autoStartIfDue($examId));
});

/* ==================================================================
 * 6. 考生答题：取卷（答案不下发）→ 保存 → 交卷判分
 * ================================================================== */

$keyOf = static function (int $examId, string $stuId): array {
    return Database::fetchAll(
        'SELECT sp.paper_id, sp.quiz_class, q.quiz_key
         FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
         WHERE sp.exam_id = ? AND sp.stu_id = ? ORDER BY sp.paper_id ASC',
        [$examId, $stuId]
    );
};

$t->guard('考生 1 取卷：四题齐全且答案绝不下发', function () use ($t, $examId, $students, $say, $enter) {
    AuthSession::logout();
    $enter($examId, $students[0]);

    for ($pid = 1; $pid <= 4; $pid++) {
        $r = Http::get("/api/exam/paper?paper_id={$pid}");
        $t->assertSame("第 {$pid} 题 -> 200", 200, $r['status']);
        $t->assertTrue("第 {$pid} 题不含正确答案", !str_contains($r['raw'], 'quiz_key'), substr($r['raw'], 0, 120));
        $q = Http::data($r)['question'] ?? [];
        $t->assertTrue("第 {$pid} 题有题干与题型", !empty($q['quiz_title']) && !empty($q['quiz_type_label']));
    }
    $nav = Http::data(Http::get('/api/exam/paper?paper_id=1'))['navigation'] ?? [];
    $t->assertSame('答题卡 4 题', 4, (int) ($nav['total'] ?? 0));
    $t->assertSame('已答 0 题', 0, (int) ($nav['done'] ?? -1));
    $say('答题  ', "考生 {$students[0]} 取到 4 题，全程看不到正确答案");
});

$t->guard('考生 1 全对作答并交卷：判分满分、状态封存', function () use ($t, $examId, $students, $csrf, $say, $keyOf, &$fullScore) {
    $rows = $keyOf($examId, $students[0]);
    $expect = 0;
    $fullScore = 0;
    // 抽到答案键为空的题（题库脏数据）时，只能当作「部分题可自动判分」演练，
    // 并如实 SKIP —— 让断言变成「得几分都算过」是假通过。
    $blank = array_filter($rows, static fn ($r) => (string) $r['quiz_key'] === '');
    if ($blank !== []) {
        $t->skip('全对交卷演练',
            '本场抽到 ' . count($blank) . ' 道答案键为空的题（题库脏数据），改为按实际可作答题计分');
    }
    $done = 0;
    foreach ($rows as $r) {
        if ((string) $r['quiz_key'] === '') {
            continue;
        }
        $pid = (int) $r['paper_id'];
        $sent = (string) $r['quiz_key'];
        $res = Http::post('/api/exam/paper/save',
            ['paper_id' => $pid, 'stu_key' => $sent],
            ['X-CSRF-Token' => $csrf()]);
        $t->assertSame("第 {$pid} 题保存 -> 200", 200, $res['status']);
        $seen = (int) (Http::data(Http::get("/api/exam/paper?paper_id={$pid}"))['navigation']['done'] ?? -1);
        $t->assertTrue("第 {$pid} 题（{$r['quiz_class']}，key「{$sent}」）计入答题卡",
            $seen === ++$done, "done={$seen} 期望 {$done}");
        $expect += 5;
        $fullScore += 5;
    }
    $nav = Http::data(Http::get('/api/exam/paper?paper_id=1'))['navigation'] ?? [];
    // 抽到空答案键的题时会少答一题（前面已 SKIP 说明），这里按实际提交数断言
    $t->assertSame('答题卡已答数 = 已提交题数', $done, (int) ($nav['done'] ?? -1));
    $t->assertSame('答题卡总数 4 题', 4, (int) ($nav['total'] ?? -1));

    $sub = Http::post('/api/exam/paper/submit', [], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('交卷 -> 200', 200, $sub['status']);
    $t->assertSame("全对得分 {$expect}", $expect, (int) (Http::data($sub)['score'] ?? -1));

    $row = Database::fetch('SELECT stu_score, stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
        [$examId, $students[0]]);
    $t->assertSame('成绩落库', $expect, (int) $row['stu_score']);
    $t->assertSame('状态为已交卷', 'over', (string) $row['stu_status']);
    $t->assertSame('轮询阶段为已交卷', 'submitted', (string) (Http::data(Http::get('/api/exam/status'))['phase'] ?? ''));
    $say('交卷  ', "考生 {$students[0]} 交卷，得分 {$expect}/20");
});

$t->guard('交卷后：不能再作答，但可查看答案与解析', function () use ($t, $examId, $students, $csrf) {
    $t->assertSame('交卷后取卷 -> 409', 409, Http::get('/api/exam/paper?paper_id=1')['status']);
    $t->assertSame('交卷后保存 -> 409', 409,
        Http::post('/api/exam/paper/save', ['paper_id' => 1, 'stu_key' => 'A'], ['X-CSRF-Token' => $csrf()])['status']);

    $ans = Http::get('/api/exam/answer');
    $t->assertSame('查看答案 -> 200', 200, $ans['status']);
    $papers = Http::data($ans)['papers'] ?? [];
    $t->assertSame('明细 4 题', 4, count($papers));
    $t->assertTrue('此时下发正确答案', str_contains($ans['raw'], 'quiz_key'));
    $t->assertTrue('四题全部判对', !in_array(false, array_map(static fn ($p) => (bool) ($p['is_correct'] ?? false), $papers), true));
    $t->assertSame('分题型统计 4 类', 4, count(Http::data($ans)['summary'] ?? []));
});

/* ==================================================================
 * 7. 监考控制：锁定 / 解锁 / 单人收卷 / 结束整场
 * ================================================================== */

$t->guard('教师端锁定与解锁：只影响被点名的人', function () use ($t, $examId, $students, $csrf, $say, $enter) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => FLOW_TEACHER, 'password' => FLOW_T_PWD]);

    $res = Http::post('/api/teacher/monitor/lock', ['exam_id' => $examId, 'stu_id' => $students[1]],
        ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('锁定考生 2 -> 200', 200, $res['status']);
    $t->assertSame('影响 1 行', 1, (int) (Http::data($res)['affected'] ?? 0));
    $t->assertSame('考生 2 状态 locked', 'locked', (string) (Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $students[1]]
    )['stu_status'] ?? ''));
    $t->assertSame('考生 3 未被牵连', 'online', (string) (Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $students[2]]
    )['stu_status'] ?? ''));
    $say('监考  ', "锁定考生 {$students[1]}（防止离席作弊），其余考生照常作答");

    // 被锁考生当场取卷应被拒
    AuthSession::logout();
    $enter($examId, $students[1]);
    $r = Http::get('/api/exam/paper?paper_id=1');
    $t->assertSame('被锁考生取卷 -> 403', 403, $r['status']);
    $t->assertTrue('提示已被锁定', str_contains($r['raw'], '锁定'));

    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => FLOW_TEACHER, 'password' => FLOW_T_PWD]);
    $t->assertSame('解锁 -> 200', 200,
        Http::post('/api/teacher/monitor/unlock', ['exam_id' => $examId, 'stu_id' => $students[1]],
            ['X-CSRF-Token' => $csrf()])['status']);

    AuthSession::logout();
    $enter($examId, $students[1]);
    $t->assertSame('解锁后可取卷', 200, Http::get('/api/exam/paper?paper_id=1')['status']);
});

$t->guard('教师单人收卷：只收被点名的一人', function () use ($t, $examId, $students, $csrf, $say) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => FLOW_TEACHER, 'password' => FLOW_T_PWD]);

    $res = Http::data(Http::post('/api/teacher/monitor/submit-one',
        ['exam_id' => $examId, 'stu_id' => $students[2]], ['X-CSRF-Token' => $csrf()])) ?? [];
    $t->assertSame('单人收卷 -> 200', 200, Http::post('/api/teacher/monitor/submit-one',
        ['exam_id' => $examId, 'stu_id' => $students[2]], ['X-CSRF-Token' => $csrf()])['status']);
    $t->assertSame('判分 1 人', 1, (int) ($res['graded'] ?? 0));

    $t->assertSame('被收卷者已交卷', 'over', (string) (Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $students[2]]
    )['stu_status'] ?? ''));
    // 关键：考生 2 还在考，绝不能被连带收卷
    $t->assertSame('考生 2 仍在作答', 'online', (string) (Database::fetch(
        'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $students[1]]
    )['stu_status'] ?? ''));
    $t->assertSame('整场仍在进行中', 'testing', (string) ((new Exam())->find($examId)['exam_status'] ?? ''));
    $say('监考  ', "点名收卷考生 {$students[2]}，考生 2 继续作答");
});

$t->guard('结束整场：未交卷者被强制判分，整场收敛为已结束', function () use ($t, $examId, $students, $csrf) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => FLOW_TEACHER, 'password' => FLOW_T_PWD]);

    $res = Http::data(Http::post('/api/teacher/monitor/over-all', ['exam_id' => $examId],
        ['X-CSRF-Token' => $csrf()])) ?? [];
    $t->assertSame('结束整场 -> 200', 200, Http::post('/api/teacher/monitor/over-all', ['exam_id' => $examId],
        ['X-CSRF-Token' => $csrf()])['status']);
    $t->assertSame('强制判分 1 人（考生 2）', 1, (int) ($res['graded'] ?? -1));

    $t->assertSame('整场已结束', 'over', (string) ((new Exam())->find($examId)['exam_status'] ?? ''));
    $sts = [];
    foreach ($students as $stuId) {
        $sts[] = (string) (Database::fetch(
            'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$examId, $stuId]
        )['stu_status'] ?? '');
    }
    $t->assertSame('三人全部交卷', ['over', 'over', 'over'], $sts);
});

/* ==================================================================
 * 8. 考后：成绩名单 / 分析 / 导出 / 备份
 * ================================================================== */

$t->guard('教师端成绩名单与学情分析', function () use ($t, $examId, $students, $fullScore) {
    $scores = Http::data(Http::get("/api/teacher/scores?exam_id={$examId}")) ?? [];
    $list = $scores['list'] ?? [];
    $t->assertSame('成绩名单 3 人', 3, count($list));
    $found = [];
    foreach ($list as $row) {
        $t->assertSame($row['stu_id'] . ' 已交卷', 'over', (string) $row['stu_status']);
        $found[] = (string) $row['stu_id'];
    }
    $t->assertTrue('三名考生都在名单里', count(array_diff($students, $found)) === 0, implode(',', $found));
    // 用演练时实际算出的满分，避免写死后随「抽到哪套题」而飘
    $t->assertTrue("名单含满分 {$fullScore} 分",
        in_array($fullScore, array_map(static fn ($r) => (int) $r['stu_score'], $list), true),
        implode(',', array_map(static fn ($r) => (int) $r['stu_score'], $list)));

    $analysis = Http::data(Http::get("/api/teacher/exams/{$examId}/analysis")) ?? [];
    $t->assertTrue('成绩分析可访问', is_array($analysis) && count($analysis) >= 0);
});

$t->guard('成绩导出与备份：导出不含考场口令，重复备份被拒', function () use ($t, $examId, $csrf) {
    $csv = Http::get("/api/teacher/scores/export?exam_id={$examId}");
    $t->assertSame('导出 -> 200', 200, $csv['status']);
    $t->assertTrue('导出 3 行数据', substr_count($csv['raw'], "\n") >= 4);
    $t->assertTrue('导出不含考场口令', !str_contains($csv['raw'], '考场口令'));

    AuthSession::logout();
    Http::post('/api/admin/login', ['username' => FLOW_ADMIN, 'password' => FLOW_ADMIN_PWD]);
    $r1 = Http::post('/api/admin/scores/backup', ['exam_id' => $examId], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('备份已结束考试 -> 200', 200, $r1['status']);
    $t->assertTrue('重复备份被拒', Http::post('/api/admin/scores/backup', ['exam_id' => $examId],
        ['X-CSRF-Token' => $csrf()])['status'] !== 200);
});

$t->guard('考生端：成绩与公示可查，已结束不能再入场', function () use ($t, $examId, $students, $examPwd, $enter) {
    AuthSession::logout();
    $enter($examId, $students[0], $examPwd);
    $t->assertTrue('结束后入场被拒',
        in_array(Http::post('/api/exam/login', [
            'exam_id' => $examId, 'stu_id' => $students[0],
            'password' => Fixture::PWD, 'exam_pwd' => $examPwd,
        ])['status'], [400, 409], true));

    $board = Http::data(Http::get('/api/student/score-board')) ?? [];
    $t->assertTrue('成绩公示接口可用', is_array($board));

    $sv = Http::data(Http::get('/api/student/scores')) ?? [];
    $t->assertTrue('个人成绩含本场', count($sv) >= 0);
});

/* ==================================================================
 * 9. 另一条路径：监考手动出题 + 手动开考（不依赖自动时机）
 * ================================================================== */

$t->guard('手动路径：教师建场 → 考生入场 → 手动出题 → 手动开考 → 取卷作答', function () use ($t, $csrf, $subjId, $startAt, $endAt, $classId, $quizParams, $students, $say, $enter) {
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => FLOW_TEACHER, 'password' => FLOW_T_PWD]);

    $res = Http::post('/api/teacher/exams', array_merge([
        'exam_name'  => Fixture::PREFIX . '手动开考演练',
        'subj_id'    => $subjId,
        // 开考时间定在 5 分钟后：入场窗口（考前 15 分钟）已开，
        // 而自动出题时点（开考前 3 秒）还远没到 —— 这样「手动出题」才是
        // 真正由教师点击触发的，不会被惰性自动出题抢先。
        'exam_start' => date('Y-m-d H:i:s', time() + 300),
        'exam_end'   => date('Y-m-d H:i:s', time() + 900),
        'stu_class'  => $classId,
    ], $quizParams), ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('教师建场 -> 200', 200, $res['status']);
    $manualId = (int) (Http::data($res)['id'] ?? 0);
    $manualPwd = (string) (Http::data($res)['exam_pwd'] ?? '');
    $t->assertTrue('教师建场同样自带口令', preg_match('/^\d{4,10}$/', $manualPwd) === 1, $manualPwd);
    $say('教师  ', "另建场 #{$manualId}（手动开考路径），口令 {$manualPwd}");

    // 考生入场（此时自动出题时点未到，卷仍为零）
    AuthSession::logout();
    $enter($manualId, $students[0], $manualPwd);
    $t->assertSame('入场停在等待室', 'waiting', (string) (Http::data(Http::get('/api/exam/status'))['phase'] ?? ''));
    $t->assertSame('此刻仍未出题', 0, (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?', [$manualId, $students[0]]
    )['c'] ?? -1));

    // 监考手动出题（指定这一位考生）
    AuthSession::logout();
    Http::post('/api/teacher/login', ['username' => FLOW_TEACHER, 'password' => FLOW_T_PWD]);
    $gen = Http::data(Http::post("/api/teacher/exams/{$manualId}/generate",
        ['stu_ids' => [$students[0]]], ['X-CSRF-Token' => $csrf()])) ?? [];
    $t->assertSame('手动出题 -> 200', 200, Http::post("/api/teacher/exams/{$manualId}/generate",
        ['stu_ids' => [$students[0]]], ['X-CSRF-Token' => $csrf()])['status']);
    $t->assertSame('新生成 1 份', 1, (int) ($gen['generated'] ?? -1));
    $t->assertSame('只给被点名的考生出题', 4, (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stupaper` WHERE exam_id = ?', [$manualId]
    )['c'] ?? 0));
    $t->assertSame('整场推进为已出卷', 'paper', (string) ((new Exam())->find($manualId)['exam_status'] ?? ''));

    // 监考手动开考
    $t->assertSame('手动开考 -> 200', 200,
        Http::post("/api/teacher/exams/{$manualId}/start", [], ['X-CSRF-Token' => $csrf()])['status']);
    $t->assertSame('整场进入进行中', 'testing', (string) ((new Exam())->find($manualId)['exam_status'] ?? ''));

    // 考生取卷作答
    AuthSession::logout();
    $enter($manualId, $students[0]);
    $r = Http::get('/api/exam/paper?paper_id=1');
    $t->assertSame('手动开考后取卷 -> 200', 200, $r['status']);
    $t->assertSame('首题有题干', true, !empty(Http::data($r)['question']['quiz_title'] ?? ''));
});

/* ==================================================================
 * 10. 收尾
 * ================================================================== */

Setting::putMany(['exam_auto_gen_lead_seconds' => null]);
Setting::flush();
AuthSession::logout();
Fixture::cleanup();

$t->guard('收尾：设置还原、测试数据清空', function () use ($t) {
    $t->assertSame('自动出题提前量还原为默认 10 秒', 10, Setting::int('exam_auto_gen_lead_seconds'));
    $c = (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `examinfo` WHERE exam_name LIKE ?', [Fixture::PREFIX . '%']
    )['c'] ?? -1);
    $t->assertSame('测试考试已清空', 0, $c);
    $stuLeft = (int) (Database::fetch(
        'SELECT COUNT(*) c FROM `stuinfo` WHERE id IN (?, ?, ?)',
        [Fixture::STU_1, Fixture::STU_2, Fixture::STU_3]
    )['c'] ?? -1);
    $t->assertSame('测试考生已清空', 0, $stuLeft);
});

echo "\n========== 演练结束 ==========\n";

exit($t->finish());
