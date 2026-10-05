<?php

declare(strict_types=1);

/**
 * 考前准备链路测试 —— 入场窗口 / 考场口令 / 到场统计 / 自动出题 / 出题进度。
 *
 * 需求（一句话）：考前 15 分钟内考生凭「建考试时就有的」考场口令进入考场，
 * 老师和管理员此时就能看到考场里的考生与统计；开考前 10 秒系统自动为
 * 已入场考生出题（也可监考手动出题），出题过程在管理页逐人可见；
 * 出题后到开考时间自动开考，也可手动开考。
 *
 * 本文件覆盖其中「出题之前」这一段，与 exam_flow_test（考生视角全流程）、
 * full_exam_e2e_test（三端串联）互补。
 *
 * 与既有约定一致：串行执行（并发跑会共用同一个 MySQL 互相 cleanup）。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Services\AuthSession;
use App\Services\ExamEngine;
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
echo "== 考前准备链路测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('考前准备链路', '数据库不可用');
    exit($t->finish());
}

Fixture::cleanup();
Setting::flush();

const PREP_ADMIN = 'admin';
const PREP_ADMIN_PWD = 'admin@2026';
const PREP_TEACHER = 'teacher1';
const PREP_T_PWD = 'teacher@2026';

/** 确保教师可登录（教师表可能被前序测试改动过） */
$row = Database::fetch('SELECT id FROM `teainfo` WHERE tea_name = ?', [PREP_TEACHER]);
if ($row === null) {
    Database::query(
        'INSERT INTO `teainfo` (tea_name, tea_pwd, avatar) VALUES (?, ?, ?)',
        [PREP_TEACHER, Password::hash(PREP_T_PWD), '']
    );
} else {
    Database::query('UPDATE `teainfo` SET tea_pwd = ? WHERE id = ?', [Password::hash(PREP_T_PWD), $row['id']]);
}

$csrf = static fn (): string => AuthSession::csrfToken();

/** 登录管理端（会清掉其它身份的会话） */
$loginAdmin = static function () use ($t): void {
    $res = Http::post('/api/admin/login', ['username' => PREP_ADMIN, 'password' => PREP_ADMIN_PWD]);
    $t->assertSame('管理员登录 -> 200', 200, $res['status']);
};

/** 登录教师端 */
$loginTeacher = static function () use ($t): void {
    // 注意字段名是 username（不是 tea_name）：与 /api/teacher/login 的校验规则一致
    $res = Http::post('/api/teacher/login', ['username' => PREP_TEACHER, 'password' => PREP_T_PWD]);
    $t->assertSame('教师登录 -> 200', 200, $res['status']);
};

/* ==================================================================
 * 1. 入场窗口：默认开考前 15 分钟
 * ================================================================== */

$t->guard('入场窗口默认 15 分钟', function () use ($t) {
    $t->assertSame('入场提前量 900 秒', 900, Exam::entryLeadSeconds());

    $exam = ['exam_start' => date('Y-m-d H:i:s', time() + 600), 'exam_status' => 'exam', 'exam_pwd' => '123456'];
    // entryOpensAt() 直接返回时间戳（不是字符串），别再套一层 strtotime
    $t->assertSame('入场开启时刻 = 开考前 15 分钟', 900, (int) (strtotime((string) $exam['exam_start']) - (int) Exam::entryOpensAt($exam)));
});

/* ==================================================================
 * 2. 考场口令：建立考试的当下就生成（管理端 / 教师端 / 补考）
 * ================================================================== */

$loginAdmin();

$t->guard('管理端新建考试即下发考场口令', function () use ($t, $csrf) {
    $diffField = 'mid';
    $subj = Database::fetch('SELECT id FROM `subject` ORDER BY id LIMIT 1');
    $res = Http::post('/api/admin/exams', [
        'exam_name'  => Fixture::PREFIX . '口令随建场',
        'exam_tea'   => PREP_TEACHER,
        'subj_id'    => (int) ($subj['id'] ?? 1),
        'exam_start' => date('Y-m-d H:i:s', time() + 1800),
        'exam_end'   => date('Y-m-d H:i:s', time() + 5400),
        'stu_class'  => Fixture::CLASS_ID3,
        "radio2_{$diffField}_sum" => 1, 'radio2_val' => 5,
    ], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('新建 -> 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $pwd = (string) ($d['exam_pwd'] ?? '');
    // 关键：考前就要能在考试信息里看到口令，而不是点「开放入场」才有
    $t->assertTrue('新建即有考场口令（4-10 位数字）', preg_match('/^\d{4,10}$/', $pwd) === 1, $pwd);
    $t->assertTrue('口令不是占位 0', $pwd !== '0', $pwd);
});

$loginTeacher();

$t->guard('教师端新建考试同样自带考场口令', function () use ($t, $csrf) {
    $subj = Database::fetch('SELECT id FROM `subject` ORDER BY id LIMIT 1');
    $res = Http::post('/api/teacher/exams', [
        'exam_name'  => Fixture::PREFIX . '教师建场口令',
        'subj_id'    => (int) ($subj['id'] ?? 1),
        'exam_start' => date('Y-m-d H:i:s', time() + 1800),
        'exam_end'   => date('Y-m-d H:i:s', time() + 5400),
        'stu_class'  => Fixture::CLASS_ID3,
        'radio2_mid_sum' => 1, 'radio2_val' => 5,
    ], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('新建 -> 200', 200, $res['status']);
    $pwd = (string) (Http::data($res)['exam_pwd'] ?? '');
    $t->assertTrue('教师端新建即有口令', preg_match('/^\d{4,10}$/', $pwd) === 1, $pwd);
});

/* ==================================================================
 * 3. 到场统计：出题之前就能看到「已入场 / 应考」
 * ================================================================== */

$fx = Fixture::createExam3([
    'exam_tea'   => PREP_TEACHER,
    // 5 分钟后开考：入场窗口（考前 15 分钟）此刻已开启，但远未到自动出题时点
    'exam_start' => date('Y-m-d H:i:s', time() + 300),
    'exam_end'   => date('Y-m-d H:i:s', time() + 3900),
]);
if ($fx === null) {
    $t->skip('考前准备链路', '题库无四种题型齐备的科目');
    Fixture::cleanup();
    exit($t->finish());
}
$examId = (int) $fx['exam_id'];
$stu1 = (string) Fixture::STU_1;
$stu2 = (string) Fixture::STU_2;
$examPwd = (string) $fx['exam_pwd'];
echo "  夹具考试 #{$examId}（考生 {$stu1}/{$stu2}/" . Fixture::STU_3 . "）\n";

/** 让考生入场（考场登录） */
$enter = static function (string $stuId) use ($examId, $examPwd): array {
    return Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $stuId,
        'password' => Fixture::PWD,
        'exam_pwd' => $examPwd,
    ]);
};

$t->guard('考生可在考前 15 分钟内入场，且此时尚未出题', function () use ($t, $enter, $stu1, $stu2, $examId) {
    foreach ([$stu1, $stu2] as $stuId) {
        $res = $enter($stuId);
        $t->assertSame("考生 {$stuId} 入场 -> 200", 200, $res['status']);
        $t->assertSame("考生 {$stuId} 进入等待室", 'waiting', (string) (Http::data($res)['phase'] ?? ''));
    }
    $row = Database::fetch(
        "SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id IN (?, ?)",
        [$examId, $stu1, $stu2]
    );
    $t->assertSame('入场后仍未出题（0 份卷）', 0, (int) ($row['c'] ?? -1));
});

$t->guard('统计含「已入场」人数', function () use ($t, $examId) {
    $s = (new Exam())->statusSummary($examId);
    $t->assertTrue('statusSummary 含 entered 字段', array_key_exists('entered', $s));
    $t->assertSame('已入场 2 人', 2, (int) ($s['entered'] ?? -1));
});

$loginAdmin();

$t->guard('管理端考试列表给出「已入场 / 应考」', function () use ($t, $examId) {
    $res = Http::get('/api/admin/exams');
    $t->assertSame('列表 -> 200', 200, $res['status']);
    $hit = null;
    foreach ((Http::data($res)['list'] ?? []) as $r) {
        if ((int) ($r['id'] ?? 0) === $examId) { $hit = $r; break; }
    }
    $t->assertTrue('列表含本场考试', $hit !== null);
    $t->assertSame('应考人数 = 3（班级展开）', 3, (int) ($hit['eligible_total'] ?? -1));
    $t->assertSame('已入场 2 人', 2, (int) ($hit['status_summary']['entered'] ?? -1));
});

/* ==================================================================
 * 4. 出题计划（逐人可见的名单）
 * ================================================================== */

$t->guard('出题计划列出已入场考生与待出题数', function () use ($t, $examId, $stu1, $stu2) {
    $res = Http::get("/api/admin/exams/{$examId}/generate/plan");
    $t->assertSame('出题计划 -> 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('已入场 2 人', 2, (int) ($d['total'] ?? -1));
    $t->assertSame('待出题 2 人', 2, (int) ($d['pending'] ?? -1));
    $ids = array_map(static fn ($s) => (string) ($s['stu_id'] ?? ''), $d['students'] ?? []);
    $t->assertTrue('名单含考生 1', in_array($stu1, $ids, true), implode(',', $ids));
    $t->assertTrue('名单含考生 2', in_array($stu2, $ids, true), implode(',', $ids));
    $t->assertTrue('给出姓名（不是只给准考证号）', (string) ($d['students'][0]['stu_name'] ?? '') !== '');
    $t->assertTrue('未入场考生不在名单内', !in_array((string) Fixture::STU_3, $ids, true));
});

$loginTeacher();

$t->guard('教师端出题计划：本人可见、他人考试 404', function () use ($t, $examId) {
    $res = Http::get("/api/teacher/exams/{$examId}/generate/plan");
    $t->assertSame('本人考试 -> 200', 200, $res['status']);

    // 他人的考试一律 404（不可探测存在性）
    $other = Fixture::createExam(['exam_tea' => 'teacher2']);
    if ($other !== null) {
        $res2 = Http::get('/api/teacher/exams/' . (int) $other['exam_id'] . '/generate/plan');
        $t->assertSame('他人考试 -> 404', 404, $res2['status']);
    }
});

/* ==================================================================
 * 5. 分批出题：只出指定的那一批，且逐人返回明细
 * ================================================================== */

$loginAdmin();

$t->guard('按 stu_ids 分批出题：只出指定的考生', function () use ($t, $examId, $stu1, $stu2, $csrf) {
    $res = Http::post("/api/admin/exams/{$examId}/generate", ['stu_ids' => [$stu1]], ['X-CSRF-Token' => $csrf()]);
    $t->assertSame('分批出题 -> 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('本批待出题 1 人', 1, (int) ($d['pending'] ?? -1));
    $t->assertSame('新生成 1 份', 1, (int) ($d['generated'] ?? -1));

    $details = $d['details'] ?? [];
    $t->assertSame('明细 1 条', 1, count($details));
    $t->assertSame('明细含准考证号', $stu1, (string) ($details[0]['stu_id'] ?? ''));
    $t->assertTrue('明细含姓名', (string) ($details[0]['stu_name'] ?? '') !== '');
    $t->assertTrue('明细含题数且 > 0', (int) ($details[0]['questions'] ?? 0) > 0);

    // 未被指定的人绝不能连带有卷：出卷范围严格按「实际入场 + 本次指定」
    $c2 = (int) (Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?', [$examId, $stu2]
    )['c'] ?? -1);
    $t->assertSame('未指定的考生仍无卷', 0, $c2);
});

$t->guard('出题计划实时反映「谁已出卷」', function () use ($t, $examId, $stu1) {
    $res = Http::get("/api/admin/exams/{$examId}/generate/plan");
    $d = Http::data($res) ?? [];
    $t->assertSame('待出题降为 1 人', 1, (int) ($d['pending'] ?? -1));
    $done = array_values(array_filter($d['students'] ?? [], static fn ($s) => (string) ($s['stu_id'] ?? '') === $stu1));
    $t->assertTrue('考生 1 已标记有卷', (bool) ($done[0]['has_paper'] ?? false));
    $t->assertTrue('考生 1 卷面题数 > 0', (int) ($done[0]['questions'] ?? 0) > 0);
});

$t->guard('第二批补齐剩余考生', function () use ($t, $examId, $stu2, $csrf) {
    $res = Http::post("/api/admin/exams/{$examId}/generate", ['stu_ids' => [$stu2]], ['X-CSRF-Token' => $csrf()]);
    $d = Http::data($res) ?? [];
    $t->assertSame('新生成 1 份', 1, (int) ($d['generated'] ?? -1));

    $plan = Http::data(Http::get("/api/admin/exams/{$examId}/generate/plan")) ?? [];
    $t->assertSame('待出题归零', 0, (int) ($plan['pending'] ?? -1));

    // 出题完成 → 状态推进到「已组卷」，到点才会自动开考
    $exam = (new Exam())->find($examId);
    $t->assertSame('状态推进为 paper', 'paper', (string) ($exam['exam_status'] ?? ''));
});

/* ==================================================================
 * 6. 自动出题：开考前 N 秒惰性触发
 * ================================================================== */

$t->guard('未到出题时点：不触发', function () use ($t) {
    $fx = Fixture::createExam(['exam_start' => date('Y-m-d H:i:s', time() + 3600), 'exam_end' => date('Y-m-d H:i:s', time() + 7200)]);
    if ($fx === null) { $t->skip('未到出题时点', '夹具创建失败'); return; }
    $id = (int) $fx['exam_id'];
    $t->assertTrue('返回 false', Exam::autoGenerateIfDue($id) === false);
    $t->assertSame('状态仍为 exam', 'exam', (string) ((new Exam())->find($id)['exam_status'] ?? ''));
});

$t->guard('已到出题时点：自动出卷并推进状态', function () use ($t) {
    // 开考前 5 秒（默认提前量 10 秒）→ 已过自动出题时点
    $fx = Fixture::createExam(['exam_start' => date('Y-m-d H:i:s', time() + 5), 'exam_end' => date('Y-m-d H:i:s', time() + 3600)]);
    if ($fx === null) { $t->skip('已到出题时点', '夹具创建失败'); return; }
    $id = (int) $fx['exam_id'];
    // 让一名考生入场：出题只面向已入场者
    Database::query("UPDATE `stuscore` SET stu_status = 'online' WHERE exam_id = ? AND stu_id = ?", [$id, Fixture::STU_A]);

    $t->assertTrue('已到点 -> 返回 true', Exam::autoGenerateIfDue($id) === true);
    $c = (int) (Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?', [$id, Fixture::STU_A]
    )['c'] ?? 0);
    $t->assertTrue('已入场考生拿到卷（4 题）', $c > 0, "题数 {$c}");
    $t->assertSame('状态推进为 paper', 'paper', (string) ((new Exam())->find($id)['exam_status'] ?? ''));

    // 幂等：再次调用不会再生成一套（题量不变）
    Exam::autoGenerateIfDue($id);
    $c2 = (int) (Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?', [$id, Fixture::STU_A]
    )['c'] ?? 0);
    $t->assertSame('重复触发不重复出卷', $c, $c2);
});

$t->guard('无人入场也要推进为已组卷（否则到点不开考）', function () use ($t) {
    $fx = Fixture::createExam(['exam_start' => date('Y-m-d H:i:s', time() + 5), 'exam_end' => date('Y-m-d H:i:s', time() + 3600)]);
    if ($fx === null) { $t->skip('无人入场', '夹具创建失败'); return; }
    $id = (int) $fx['exam_id'];
    Exam::autoGenerateIfDue($id);
    $t->assertSame('状态仍推进为 paper', 'paper', (string) ((new Exam())->find($id)['exam_status'] ?? ''));
    $t->assertSame('没有产生任何卷', 0, (int) (Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ?', [$id]
    )['c'] ?? -1));
});

$t->guard('关闭自动出题（提前量=0）时不触发', function () use ($t) {
    Setting::putMany(['exam_auto_gen_lead_seconds' => 0]);
    Setting::flush();
    $fx = Fixture::createExam(['exam_start' => date('Y-m-d H:i:s', time() + 5), 'exam_end' => date('Y-m-d H:i:s', time() + 3600)]);
    if ($fx === null) { $t->skip('关闭自动出题', '夹具创建失败'); return; }
    $id = (int) $fx['exam_id'];
    $t->assertTrue('不触发', Exam::autoGenerateIfDue($id) === false);
    $t->assertSame('状态仍为 exam', 'exam', (string) ((new Exam())->find($id)['exam_status'] ?? ''));

    // 还原默认并清掉本例写入的设置行
    Setting::putMany(['exam_auto_gen_lead_seconds' => null]);
    Setting::flush();
    $t->assertSame('还原后默认 10 秒', 10, Setting::int('exam_auto_gen_lead_seconds'));
});

$t->guard('考生轮询状态即触发自动出题（真实入口）', function () use ($t, $enter) {
    // 另起一场：2 秒后开考（已过自动出题时点），考生已在场内
    $fx = Fixture::createExam3(['exam_tea' => PREP_TEACHER, 'exam_start' => date('Y-m-d H:i:s', time() + 2), 'exam_end' => date('Y-m-d H:i:s', time() + 3600)]);
    if ($fx === null) { $t->skip('轮询触发自动出题', '夹具创建失败'); return; }
    $id = (int) $fx['exam_id'];
    $stu = (string) Fixture::STU_1;

    $res = Http::post('/api/exam/login', [
        'exam_id'  => $id,
        'stu_id'   => $stu,
        'password' => Fixture::PWD,
        'exam_pwd' => (string) $fx['exam_pwd'],
    ]);
    $t->assertSame('考生入场 -> 200', 200, $res['status']);

    // 此刻还没出题（入场窗口内、未到自动出题时点之外？这里 start=+2s 已过点，
    // 但入场动作本身不触发出题，故仍应无卷——除非登录接口自己出题）
    $before = (int) (Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?', [$id, $stu]
    )['c'] ?? -1);
    $t->assertSame('入场动作本身不出题', 0, $before);

    // 轮询一次状态：应顺带完成自动出题
    $st = Http::get('/api/exam/status');
    $t->assertSame('状态轮询 -> 200', 200, $st['status']);
    $after = (int) (Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?', [$id, $stu]
    )['c'] ?? 0);
    $t->assertTrue('轮询后已自动出题', $after > 0, "题数 {$after}");
});

/* ==================================================================
 * 收尾
 * ================================================================== */

Setting::flush();
AuthSession::logout();
Fixture::cleanup();

$t->guard('收尾：无残留测试考试', function () use ($t) {
    $c = (int) (Database::fetch(
        'SELECT COUNT(*) AS c FROM `examinfo` WHERE exam_name LIKE ?', [Fixture::PREFIX . '%']
    )['c'] ?? -1);
    $t->assertSame('测试考试已清空', 0, $c);
});

exit($t->finish());
