<?php

declare(strict_types=1);

/**
 * 成绩公示与隐私分级（文档 B4）+ 电子证书（C1）+ 补考/重考（C2）—— 端到端回归。
 *
 * 覆盖：
 *  A) 成绩公示粒度：private 仅本人 / class 本班 / public 全体；非本场考生一律拒绝；
 *     未结束场次不公示；名次为全场名次（并列同名次）；及格线与后台设置同口径。
 *  B) 电子证书：达标惰性签发、幂等（不重复发）、不达标拒绝、
 *     快照不随源数据变动、公开核验（姓名脱敏）、编号不可枚举、
 *     达标分高于满分的配置被拒。
 *  C) 补考：候选名单状态判定、生成补考场次（配置复制 + 名单落库）、
 *     已通过者/名单外考生/空名单/未结束场次一律拒绝、
 *     考生端可见性、考场准入只认名单、出题只给名单内考生、
 *     教师端生成强制归属本人 + 越权 404。
 *
 * 全部数据以 __TEST__ 前缀创建，finally 精确回收。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Services\AuthSession;
use App\Services\Certificate;
use App\Services\ExamRetake;
use App\Services\Password;
use App\Services\ScoreBoard;
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
echo "== 成绩公示与隐私分级 + 电子证书 + 补考/重考 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('成绩公示/证书/补考', '数据库不可用');
    exit($t->finish());
}

const EXP_ADMIN = '__TEST__pcradmin';
const EXP_ADMIN_PWD = 'pcrpwd123';
const TEA_A = 'teacher1';
const TEA_A_PWD = 'teacher@2026';
const TEA_B = 'teacher2';

$createdExamIds = [];
$cleanupAdmin = false;

/* ------------------------------------------------------------------ */
/* 助手                                                                */
/* ------------------------------------------------------------------ */

/** 直改 examinfo 字段（夹具只写入固定字段，新增列走 UPDATE 最省事） */
$setExam = static function (int $examId, array $fields): void {
    $cols = array_keys($fields);
    $sql = 'UPDATE `examinfo` SET ' . implode(', ', array_map(static fn ($c) => "`$c` = ?", $cols)) . ' WHERE id = ?';
    Database::query($sql, [...array_values($fields), $examId]);
};

/** 写入考生成绩与状态 */
$setScore = static function (int $examId, string $stuId, int $score, string $status = 'over'): void {
    Database::query(
        'UPDATE `stuscore` SET stu_score = ?, stu_status = ? WHERE exam_id = ? AND stu_id = ?',
        [$score, $status, $examId, $stuId]
    );
};

$examRow = static fn (int $examId): array => (new Exam())->find($examId) ?? [];

$certCount = static fn (int $examId, string $stuId): int => (int) (Database::fetch(
    'SELECT COUNT(*) AS c FROM `certificate` WHERE exam_id = ? AND stu_id = ?',
    [$examId, $stuId]
)['c'] ?? 0);

$certRow = static fn (int $examId, string $stuId): ?array => Database::fetch(
    'SELECT * FROM `certificate` WHERE exam_id = ? AND stu_id = ?',
    [$examId, $stuId]
);

$retakeRoster = static fn (int $examId): array => Database::fetchAll(
    'SELECT stu_id FROM `exam_retake_stu` WHERE exam_id = ? ORDER BY id',
    [$examId]
);

$paperStuIds = static fn (int $examId): array => array_map(
    static fn (array $r): string => (string) $r['stu_id'],
    Database::fetchAll('SELECT DISTINCT stu_id FROM `stupaper` WHERE exam_id = ? ORDER BY stu_id', [$examId])
);

/** 学生登录（切换角色后必须重新登录取新 csrf，否则后续写请求全部 419） */
$studentLogin = static function (string $stuId): array {
    AuthSession::logout();
    sess_forget('exam_session');
    $r = Http::post('/api/student/login', ['username' => $stuId, 'password' => Fixture::PWD]);
    return ['status' => $r['status'], 'csrf' => (string) (Http::data($r)['csrf_token'] ?? '')];
};

/** 教师登录 */
$teacherLogin = static function (string $name, string $pwd): array {
    AuthSession::logout();
    sess_forget('exam_session');
    $r = Http::post('/api/teacher/login', ['username' => $name, 'password' => $pwd]);
    return ['status' => $r['status'], 'csrf' => (string) (Http::data($r)['csrf_token'] ?? '')];
};

/**
 * 管理员登录。
 * 三种登录态共用同一个 $_SESSION 槽位，登录任一端都会顶掉前一个，
 * 因此每次从「教师/考生段」回到管理端写操作前都必须重新登录取新 CSRF，
 * 否则会拿到 401（会话已被顶掉）而不是预期的业务状态码。
 */
$adminLogin = static function (): array {
    AuthSession::logout();
    sess_forget('exam_session');
    $r = Http::post('/api/admin/login', ['username' => EXP_ADMIN, 'password' => EXP_ADMIN_PWD]);
    return ['status' => $r['status'], 'csrf' => (string) (Http::data($r)['csrf_token'] ?? '')];
};

register_shutdown_function(static function () use (&$cleanupAdmin) {
    try {
        if ($cleanupAdmin) {
            Database::query('DELETE FROM `admininfo` WHERE username = ?', [EXP_ADMIN]);
        }
    } catch (\Throwable $e) { /* ignore */ }
    Fixture::cleanup();
});

/* ------------------------------------------------------------------ */

try {
    /* ==================================================================
     * A. 成绩公示与隐私分级
     * ================================================================== */
    $fx = Fixture::createExam3();
    if ($fx === null) {
        $t->skip('成绩公示/证书/补考', '夹具创建失败（题库缺少四题型同难度的科目）');
        exit($t->finish());
    }
    $examId = (int) $fx['exam_id'];
    $createdExamIds[] = $examId;
    [$s1, $s2, $s3] = $fx['students'];

    // 一场已结束、满分 20 的考试：s1 满分、s2/s3 各 10 分（并列）
    $setExam($examId, ['exam_status' => 'over', 'exam_score' => 20]);
    $setScore($examId, $s1, 20);
    $setScore($examId, $s2, 10);
    $setScore($examId, $s3, 10);

    $lg1 = $studentLogin($s1);
    $t->assertSame('考生登录 -> 200', 200, $lg1['status']);

    /* --- A1. private（默认）：只看到自己 --- */
    $t->guard('A1 private 粒度：仅本人', function () use ($t, $examId, $s1, $s2) {
        $res = Http::get("/api/student/score-board?exam_id={$examId}");
        $d = Http::data($res) ?? [];
        $t->assertSame('接口 200', 200, $res['status']);
        $t->assertSame('allowed', true, (bool) ($d['allowed'] ?? false));
        $t->assertSame('visibility=private', Exam::VIS_PRIVATE, (string) ($d['visibility'] ?? ''));
        $t->assertSame('不可见他人', false, (bool) ($d['can_view_others'] ?? true));
        $t->assertSame('rows 为空', 0, count($d['rows'] ?? []));
        $t->assertSame('自己的名次为 1', 1, (int) ($d['me']['rank'] ?? 0));
        $t->assertSame('自己的分数 20', 20, (int) ($d['me']['score'] ?? 0));
        $t->assertSame('is_me 标记', true, (bool) ($d['me']['is_me'] ?? false));
        $t->assertSame('及格分 = 20×60%', 12, (int) ($d['pass_score'] ?? 0));
        $t->assertTrue('不泄露他人学号', !str_contains(json_encode($d['me'] ?? []), $s2));
    });

    /* --- A2. class 粒度：本班可见 --- */
    $t->guard('A2 class 粒度：本班三名考生互见', function () use ($t, $examId, $setExam) {
        $setExam($examId, ['score_visibility' => Exam::VIS_CLASS]);
        $d = Http::data(Http::get("/api/student/score-board?exam_id={$examId}")) ?? [];
        $t->assertSame('visibility=class', Exam::VIS_CLASS, (string) ($d['visibility'] ?? ''));
        $t->assertSame('本班 3 人全部入榜', 3, count($d['rows'] ?? []));
        $t->assertSame('可见他人', true, (bool) ($d['can_view_others'] ?? false));
        $ranks = array_map(static fn (array $r): int => (int) $r['rank'], $d['rows'] ?? []);
        $t->assertSame('名次（并列同名次）', [1, 2, 2], $ranks);
        $t->assertSame('榜首 20 分', 20, (int) ($d['rows'][0]['score'] ?? 0));
        $t->assertSame('末位未通过', false, (bool) ($d['rows'][2]['passed'] ?? true));
        $t->assertSame('scope_label 非空', true, (string) ($d['scope_label'] ?? '') !== '');
    });

    /* --- A3. public 粒度 --- */
    $t->guard('A3 public 粒度：全体考生可见', function () use ($t, $examId, $setExam) {
        $setExam($examId, ['score_visibility' => Exam::VIS_PUBLIC]);
        $d = Http::data(Http::get("/api/student/score-board?exam_id={$examId}")) ?? [];
        $t->assertSame('visibility=public', Exam::VIS_PUBLIC, (string) ($d['visibility'] ?? ''));
        $t->assertSame('3 人入榜', 3, count($d['rows'] ?? []));
    });

    /* --- A4. 非法值回落 private --- */
    $t->guard('A4 非法粒度值回落 private（绝不误公开）', function () use ($t, $examId, $setExam) {
        $setExam($examId, ['score_visibility' => 'everyone']);
        $d = Http::data(Http::get("/api/student/score-board?exam_id={$examId}")) ?? [];
        $t->assertSame('回落 private', Exam::VIS_PRIVATE, (string) ($d['visibility'] ?? ''));
        $t->assertSame('rows 为空', 0, count($d['rows'] ?? []));
        $setExam($examId, ['score_visibility' => Exam::VIS_CLASS]);
    });

    /* --- A5. 非本场考生拒绝（防枚举） --- */
    $t->guard('A5 未参加本场考试者被拒', function () use ($t, $examId, $studentLogin) {
        // 需要一名「真实存在但与主场次无关」的考生：先落一场无关场次，
        // 顺带把 __TEST__班 的 STU_A/STU_B 建出来（夹具按姓名前缀回收）。
        Fixture::createExam(['exam_status' => 'over', 'exam_name' => Fixture::PREFIX . '无关场次']);
        $lg = $studentLogin(Fixture::STU_A);
        $t->assertSame('考生登录 -> 200', 200, $lg['status']);
        $res = Http::get("/api/student/score-board?exam_id={$examId}");
        $d = Http::data($res) ?? [];
        $t->assertSame('接口 200', 200, $res['status']);
        $t->assertSame('allowed=false', false, (bool) ($d['allowed'] ?? true));
        $t->assertSame('rows 为空', 0, count($d['rows'] ?? []));
    });

    /* --- A6. 未结束的考试不公示 --- */
    $t->guard('A6 未结束场次不公示', function () use ($t, $setExam, $studentLogin, $s1) {
        $live = Fixture::createExam3(['exam_status' => 'testing']);
        $liveId = (int) $live['exam_id'];
        $setExam($liveId, ['exam_score' => 20]);
        $studentLogin($s1);
        $d = Http::data(Http::get("/api/student/score-board?exam_id={$liveId}")) ?? [];
        $t->assertSame('allowed=false', false, (bool) ($d['allowed'] ?? true));
        $t->assertSame('reason 提示尚未结束', true, str_contains((string) ($d['reason'] ?? ''), '尚未结束'));
        // 清理该场次（夹具 cleanup 只按 exam_name 前缀回收，这里手动补齐关联行）
        Database::query('DELETE FROM `stupaper` WHERE exam_id = ?', [$liveId]);
        Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$liveId]);
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$liveId]);
    });

    /* --- A7. 我的成绩列表携带公示字段 --- */
    $t->guard('A7 我的成绩列表携带 score_visibility / 证书号', function () use ($t, $examId, $studentLogin, $s1) {
        $studentLogin($s1);
        $d = Http::data(Http::get('/api/student/scores')) ?? [];
        $hit = null;
        foreach ($d['list'] ?? [] as $row) {
            if ((int) $row['exam_id'] === $examId) {
                $hit = $row;
            }
        }
        $t->assertTrue('命中本场成绩行', $hit !== null);
        $t->assertSame('携带 score_visibility', Exam::VIS_CLASS, (string) ($hit['score_visibility'] ?? ''));
        $t->assertSame('can_view_board=true（class/public）', true, (bool) ($hit['can_view_board'] ?? false));
        $t->assertTrue('携带 cert_no 字段（未发证为空串）', array_key_exists('cert_no', (array) $hit));
    });

    /* ==================================================================
     * B. 电子证书
     * ================================================================== */
    $t->guard('B1 未启用证书时「我的证书」为空', function () use ($t, $studentLogin, $s1) {
        $studentLogin($s1);
        $d = Http::data(Http::get('/api/student/certificates')) ?? [];
        $t->assertSame('证书数 0', 0, (int) ($d['stats']['total'] ?? -1));
    });

    $certNo = '';
    $t->guard('B2 达标后惰性签发（幂等）', function () use ($t, $examId, $s1, $s2, $setExam, $studentLogin, $certCount, $certRow, &$certNo) {
        $setExam($examId, ['cert_threshold' => 15]);   // s1=20 达标，s2/s3=10 不达标
        $studentLogin($s1);
        $d = Http::data(Http::get('/api/student/certificates')) ?? [];
        $t->assertSame('证书数 1', 1, (int) ($d['stats']['total'] ?? -1));
        $t->assertSame('最高分 20', 20, (int) ($d['stats']['best'] ?? -1));
        $row = $certRow($examId, $s1);
        $t->assertTrue('落库一条', $row !== null);
        $t->assertSame('快照姓名', '__TEST__考生1', (string) ($row['stu_name'] ?? ''));
        $t->assertSame('快照满分', 20, (int) ($row['total_score'] ?? 0));
        $t->assertSame('快照达标分', 15, (int) ($row['threshold'] ?? 0));
        $t->assertTrue('证书编号非空', (string) ($row['cert_no'] ?? '') !== '');
        $t->assertTrue('编号带 CT 前缀', str_starts_with((string) ($row['cert_no'] ?? ''), Certificate::PREFIX));
        $certNo = (string) ($row['cert_no'] ?? '');

        // 再请求两次：不得重复签发
        Http::get('/api/student/certificates');
        Http::get("/api/student/certificates/{$examId}");
        $t->assertSame('幂等：仍只有 1 条', 1, $certCount($examId, $s1));

        // 不达标者不签发
        $t->assertSame('未达标者无证书', 0, $certCount($examId, $s2));
    });

    $t->guard('B3 单张证书接口', function () use ($t, $examId, $s2, $studentLogin) {
        $studentLogin(Fixture::STU_1);   // = 9000003
        $res = Http::get("/api/student/certificates/{$examId}");
        $d = Http::data($res) ?? [];
        $t->assertSame('200', 200, $res['status']);
        $t->assertSame('持证人姓名', '__TEST__考生1', (string) ($d['holder'] ?? ''));
        $t->assertSame('qualified', true, (bool) ($d['qualified'] ?? false));
        $t->assertTrue('返回证书行', isset($d['certificate']['cert_no']));

        // 未达标考生：404（不区分「未达标/未启用/未结束」，避免探测）
        $studentLogin($s2);
        $res2 = Http::get("/api/student/certificates/{$examId}");
        $t->assertSame('未达标 -> 404', 404, $res2['status']);
    });

    $t->guard('B4 公开核验：姓名脱敏', function () use ($t, $certNo) {
        AuthSession::logout();
        sess_forget('exam_session');
        $res = Http::get('/api/public/certificates/verify?cert_no=' . urlencode($certNo));
        $d = Http::data($res) ?? [];
        $t->assertSame('未登录也可核验 -> 200', 200, $res['status']);
        $t->assertSame('valid=true', true, (bool) ($d['valid'] ?? false));
        $cert = $d['certificate'] ?? [];
        $t->assertSame('编号回显一致', $certNo, (string) ($cert['cert_no'] ?? ''));
        $t->assertSame('姓名脱敏（首尾保留）', '__TEST__考生1' === (string) ($cert['stu_name'] ?? '')
            ? '未脱敏' : 'ok', 'ok');
        $t->assertTrue('不返回明文全名', (string) ($cert['stu_name'] ?? '') !== '__TEST__考生1');
        $t->assertTrue('脱敏后含星号', str_contains((string) ($cert['stu_name'] ?? ''), '*'));
        $t->assertSame('分数', 20, (int) ($cert['score'] ?? 0));
    });

    $t->guard('B5 核验：未知编号 / 空编号', function () use ($t) {
        AuthSession::logout();
        $r1 = Http::get('/api/public/certificates/verify?cert_no=CT00000000DEADBEEF0000000000000000');
        $t->assertSame('未知编号 valid=false', false, (bool) ((Http::data($r1)['valid'] ?? true)));
        $r2 = Http::get('/api/public/certificates/verify');
        $t->assertSame('缺参数 -> 200 且 valid=false', 200, $r2['status']);
        $t->assertSame('valid=false', false, (bool) ((Http::data($r2)['valid'] ?? true)));
    });

    $t->guard('B6 证书正文为快照，不随考试改名而变', function () use ($t, $examId, $setExam, $certRow, $studentLogin, $s1) {
        $setExam($examId, ['exam_name' => '__TEST__改名后的考试']);
        $row = $certRow($examId, $s1);
        $t->assertSame('证书上的考试名未变', '__TEST__自动化考试', (string) ($row['exam_name'] ?? ''));
        // 学生端「我的证书」同样回显快照
        $studentLogin($s1);
        $d = Http::data(Http::get('/api/student/certificates')) ?? [];
        $t->assertSame('列表回显快照名', '__TEST__自动化考试', (string) ($d['list'][0]['exam_name'] ?? ''));
        $setExam($examId, ['exam_name' => Fixture::PREFIX . '自动化考试']);
    });

    $t->guard('B7 编号不可枚举（随机段）', function () use ($t, $examId, $s2, $setExam, $studentLogin) {
        // 给 s2 也发一张（把达标分降到 10），两张证的编号不得呈规律
        $setExam($examId, ['cert_threshold' => 10]);
        $studentLogin($s2);
        Http::get('/api/student/certificates');
        $a = (string) (Database::fetch('SELECT cert_no FROM `certificate` WHERE exam_id = ? AND stu_id = ?', [$examId, Fixture::STU_2])['cert_no'] ?? '');
        $b = (string) (Database::fetch('SELECT cert_no FROM `certificate` WHERE exam_id = ? AND stu_id = ?', [$examId, Fixture::STU_1])['cert_no'] ?? '');
        $t->assertTrue('s2 已发证', $a !== '');
        $t->assertTrue('两证编号不同', $a !== $b);
        $t->assertTrue('编号长度一致（CT+8位日期+32位随机）', strlen($a) === strlen($b));
        $t->assertTrue('编号不含准考证号', !str_contains($a, Fixture::STU_2));
        $setExam($examId, ['cert_threshold' => 15]);
    });

    /* --- B8. 管理员配置校验 --- */
    $arow = Database::fetch('SELECT id FROM `admininfo` WHERE username = ?', [EXP_ADMIN]);
    if ($arow === null) {
        Database::query(
            'INSERT INTO `admininfo` (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)',
            [EXP_ADMIN, Password::hash(EXP_ADMIN_PWD), 'systemAdmin', '']
        );
    } else {
        Database::query('UPDATE `admininfo` SET password = ?, admin_power = ? WHERE id = ?', [Password::hash(EXP_ADMIN_PWD), 'systemAdmin', $arow['id']]);
    }
    $cleanupAdmin = true;
    AuthSession::logout();
    sess_forget('exam_session');
    $alogin = Http::post('/api/admin/login', ['username' => EXP_ADMIN, 'password' => EXP_ADMIN_PWD]);
    $t->assertSame('管理端登录 -> 200', 200, $alogin['status']);
    $acsrf = (string) (Http::data($alogin)['csrf_token'] ?? '');

    $t->guard('B8 证书达标分不得高于满分', function () use ($t, $acsrf) {
        $editable = Fixture::createExam(['exam_status' => 'exam']);
        $eid = (int) $editable['exam_id'];
        $row = (new Exam())->find($eid);
        // 组卷参数原样复制夹具配置：题库容量校验（strictStock）才不会因难度错位而误报
        $payload = [
            'exam_name'        => Fixture::PREFIX . '配置校验考试',
            'subj_id'          => (int) $row['subj_id'],
            'exam_date'        => date('Y-m-d', strtotime((string) $row['exam_start'])),
            'exam_start_time'  => '23:50',
            'exam_end_time'    => '23:59',
            'stu_class'        => $row['stu_class'],
            'cert_threshold'   => 999,
            'score_visibility' => 'class',
        ];
        foreach (Exam::TYPE_PREFIXES as $tp) {
            foreach (['easy_sum', 'mid_sum', 'hard_sum'] as $seg) {
                $payload["{$tp}_{$seg}"] = (int) ($row["{$tp}_{$seg}"] ?? 0);
            }
            $payload["{$tp}_val"] = (int) ($row["{$tp}_val"] ?? 0);
        }

        $res = Http::put("/api/admin/exams/{$eid}", $payload, ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('达标分 999 > 满分 -> 400', 400, $res['status']);
        $t->assertTrue('错误信息点明达标分与满分', str_contains((string) (Http::message($res) ?? ''), '证书达标分'));

        // 合法值应保存成功，且公示粒度一并写入
        $payload['cert_threshold'] = 15;
        $ok = Http::put("/api/admin/exams/{$eid}", $payload, ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('达标分 15 -> 200', 200, $ok['status']);
        $saved = (new Exam())->find($eid);
        $t->assertSame('cert_threshold 落库', 15, (int) ($saved['cert_threshold'] ?? 0));
        $t->assertSame('score_visibility 落库', 'class', (string) ($saved['score_visibility'] ?? ''));

        Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$eid]);
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$eid]);
    });

    /* ==================================================================
     * C. 补考 / 重考
     * ================================================================== */
    // 源场次：s1 通过（20/20），s2/s3 未通过（10/20，及格线 60% → 12 分）
    $t->guard('C1 候选名单：区分未通过 / 缺考 / 已通过', function () use ($t, $examId) {
        $d = ExamRetake::candidates($examId);
        $t->assertSame('及格分 12', 12, (int) ($d['pass_score'] ?? 0));
        $t->assertSame('及格线 60%', 60, (int) ($d['pass_percent'] ?? 0));
        $t->assertSame('未通过 2 人', 2, (int) ($d['counts'][ExamRetake::STATE_FAILED] ?? -1));
        $t->assertSame('已通过 1 人', 1, (int) ($d['counts'][ExamRetake::STATE_PASSED] ?? -1));
        $t->assertSame('名单共 3 人', 3, (int) ($d['counts']['total'] ?? -1));
        $t->assertSame('排序：未通过在前', ExamRetake::STATE_FAILED, (string) ($d['list'][0]['state'] ?? ''));
        $t->assertSame('名单末位为已通过', ExamRetake::STATE_PASSED, (string) ($d['list'][2]['state'] ?? ''));
        $t->assertTrue('携带姓名', (string) ($d['list'][0]['stu_name'] ?? '') !== '');
    });

    $retakeId = 0;
    $t->guard('C2 生成补考：配置复制 + 名单落库', function () use ($t, $examId, $acsrf, &$retakeId, $retakeRoster, $examRow) {
        $res = Http::post("/api/admin/exams/{$examId}/retake", [
            'stu_ids'    => [Fixture::STU_2, Fixture::STU_3],
            'exam_start' => date('Y-m-d H:i:s', time() + 3600),
            'exam_end'   => date('Y-m-d H:i:s', time() + 7200),
        ], ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('生成补考 -> 200', 200, $res['status']);
        $d = Http::data($res) ?? [];
        $retakeId = (int) ($d['exam_id'] ?? 0);
        $t->assertTrue('返回新考试 id', $retakeId > 0);
        $t->assertSame('名单人数 2', 2, (int) ($d['roster'] ?? 0));

        $row = $examRow($retakeId);
        $src = $examRow($examId);
        $t->assertSame('retake_of 指向源场次', $examId, (int) ($row['retake_of'] ?? 0));
        $t->assertTrue('名称带（补考）', str_ends_with((string) ($row['exam_name'] ?? ''), '（补考）'));
        $t->assertSame('未开考状态', 'exam', (string) ($row['exam_status'] ?? ''));
        $t->assertSame('科目复制', (int) $src['subj_id'], (int) $row['subj_id']);
        $t->assertSame('满分复制', (int) $src['exam_score'], (int) $row['exam_score']);
        $t->assertSame('组卷参数复制', (int) $src['radio1_mid_sum'], (int) $row['radio1_mid_sum']);
        $t->assertSame('公示粒度复制', (string) $src['score_visibility'], (string) $row['score_visibility']);
        $t->assertSame('达标分复制', (int) $src['cert_threshold'], (int) $row['cert_threshold']);
        $t->assertTrue('补考自身不是别场补考', Exam::isRetake($row));

        $roster = $retakeRoster($retakeId);
        $t->assertSame('名单落库 2 行', 2, count($roster));
        $ids = array_map(static fn (array $r): string => (string) $r['stu_id'], $roster);
        $t->assertSame('名单内容正确', [Fixture::STU_2, Fixture::STU_3], $ids);
    });

    $t->guard('C3 名单校验：已通过 / 名单外 / 空名单一律拒绝', function () use ($t, $examId, $acsrf) {
        $times = ['exam_start' => date('Y-m-d H:i:s', time() + 3600), 'exam_end' => date('Y-m-d H:i:s', time() + 7200)];

        $r1 = Http::post("/api/admin/exams/{$examId}/retake", $times + ['stu_ids' => [Fixture::STU_1]], ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('已通过者 -> 400', 400, $r1['status']);
        $t->assertTrue('提示已通过', str_contains((string) (Http::message($r1) ?? ''), '已通过'));

        $r2 = Http::post("/api/admin/exams/{$examId}/retake", $times + ['stu_ids' => [Fixture::STU_A]], ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('名单外考生 -> 400', 400, $r2['status']);
        $t->assertTrue('提示不属于该场考试', str_contains((string) (Http::message($r2) ?? ''), '不属于'));

        $r3 = Http::post("/api/admin/exams/{$examId}/retake", $times + ['stu_ids' => []], ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('空名单 -> 400', 400, $r3['status']);

        // 允许显式勾选「包含已通过」时放行
        $r4 = Http::post("/api/admin/exams/{$examId}/retake", $times + ['stu_ids' => [Fixture::STU_1], 'allow_passed' => 1], ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('allow_passed=1 -> 200', 200, $r4['status']);
        $extraId = (int) (Http::data($r4)['exam_id'] ?? 0);
        Database::query('DELETE FROM `exam_retake_stu` WHERE exam_id = ?', [$extraId]);
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$extraId]);
    });

    $t->guard('C4 未结束的考试不能生成补考', function () use ($t, $acsrf, $setExam) {
        $live = Fixture::createExam3(['exam_status' => 'testing']);
        $liveId = (int) $live['exam_id'];
        $res = Http::post("/api/admin/exams/{$liveId}/retake", [
            'stu_ids'    => [Fixture::STU_1],
            'exam_start' => date('Y-m-d H:i:s', time() + 3600),
            'exam_end'   => date('Y-m-d H:i:s', time() + 7200),
        ], ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('进行中 -> 400', 400, $res['status']);
        $t->assertTrue('提示只有已结束可补考', str_contains((string) (Http::message($res) ?? ''), '已结束'));
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$liveId]);
    });

    $t->guard('C5 时间校验：缺时间 / 结束早于开始', function () use ($t, $examId, $acsrf) {
        $r1 = Http::post("/api/admin/exams/{$examId}/retake", ['stu_ids' => [Fixture::STU_2]], ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('缺时间 -> 400', 400, $r1['status']);
        $r2 = Http::post("/api/admin/exams/{$examId}/retake", [
            'stu_ids'    => [Fixture::STU_2],
            'exam_start' => date('Y-m-d H:i:s', time() + 7200),
            'exam_end'   => date('Y-m-d H:i:s', time() + 3600),
        ], ['X-CSRF-Token' => $acsrf]);
        $t->assertSame('结束早于开始 -> 400', 400, $r2['status']);
    });

    $t->guard('C6 考场准入：补考只认名单', function () use ($retakeId, $t, $examId, $examRow, $retakeRoster) {
        $retake = $examRow($retakeId);
        $src = $examRow($examId);
        $class = Fixture::CLASS_ID3;
        $t->assertSame('名单内考生可入场', true, Exam::isStudentEligible($retake, $class, Fixture::STU_2));
        $t->assertSame('同班但不在名单 -> 拒绝', false, Exam::isStudentEligible($retake, $class, Fixture::STU_1));
        $t->assertSame('完全无关考生 -> 拒绝', false, Exam::isStudentEligible($retake, Fixture::CLASS_ID, Fixture::STU_A));
        $t->assertSame('不提供准考证号 -> 拒绝', false, Exam::isStudentEligible($retake, $class));
        $t->assertSame('普通场次仍按班级放行', true, Exam::isStudentEligible($src, $class, Fixture::STU_1));
        $t->assertTrue('retakeRoster 可读', count($retakeRoster($retakeId)) === 2);
    });

    $t->guard('C7 出题只给名单内考生', function () use ($t, $retakeId, $paperStuIds, $examRow, $retakeRoster) {
        $retake = $examRow($retakeId);
        $t->assertSame('名单来源 = 补考名单', [Fixture::STU_2, Fixture::STU_3], Exam::studentIdsForExam($retake));
        // 出题只给已进入考场的考生：模拟两名名单内考生已入场（online）。
        // 注意：$retakeRoster 返回的是「行数组」(每行含 stu_id)，必须用
        // Exam::studentIdsForExam() 取扁平的准考证号列表，否则会把数组当参数绑定导致建行失败。
        foreach (Exam::studentIdsForExam($retake) as $sid) {
            Database::query(
                "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
                 VALUES (?, ?, 0, 'online', '') ON DUPLICATE KEY UPDATE stu_status = 'online'",
                [$retakeId, $sid]
            );
        }
        // 直接走组卷（等价于管理端「出题」按钮）
        $r = \App\Services\ExamEngine::generateForClass($retakeId, $retake);
        $t->assertSame('组卷覆盖 2 名已入场考生', 2, (int) ($r['entered'] ?? -1));
        $t->assertTrue('确实生成了试卷（题库充足）', (int) ($r['generated'] ?? 0) > 0);
        $papers = $paperStuIds($retakeId);
        $t->assertSame('只生成 2 份试卷', 2, count($papers));
        $t->assertSame('试卷只属于名单内考生', [Fixture::STU_2, Fixture::STU_3], $papers);
    });

    $t->guard('C8 考生端：补考场次出现在待考列表', function () use ($t, $retakeId, $studentLogin) {
        $studentLogin(Fixture::STU_2);
        $d = Http::data(Http::get('/api/student/exams')) ?? [];
        $ids = array_map(static fn (array $e): int => (int) $e['id'], $d['list'] ?? []);
        $t->assertTrue('名单内考生可见补考', in_array($retakeId, $ids, true));

        $studentLogin(Fixture::STU_A);
        $d2 = Http::data(Http::get('/api/student/exams')) ?? [];
        $ids2 = array_map(static fn (array $e): int => (int) $e['id'], $d2['list'] ?? []);
        $t->assertTrue('名单外考生不可见', !in_array($retakeId, $ids2, true));
    });

    $t->guard('C9 教师端：候选名单 + 生成并强制归属本人', function () use ($t, $examId, $setExam, $teacherLogin, $examRow, $retakeRoster) {
        $setExam($examId, ['exam_tea' => TEA_A]);
        $lg = $teacherLogin(TEA_A, TEA_A_PWD);
        $t->assertSame('教师登录 -> 200', 200, $lg['status']);

        $cand = Http::get("/api/teacher/exams/{$examId}/retake-candidates");
        $cd = Http::data($cand) ?? [];
        $t->assertSame('候选接口 200', 200, $cand['status']);
        $t->assertSame('未通过 2 人', 2, (int) ($cd['counts'][ExamRetake::STATE_FAILED] ?? -1));

        // 教师端无权限点校验，但写操作仍需 CSRF
        $res = Http::post("/api/teacher/exams/{$examId}/retake", [
            'stu_ids'    => [Fixture::STU_2],
            'exam_start' => date('Y-m-d H:i:s', time() + 3600),
            'exam_end'   => date('Y-m-d H:i:s', time() + 7200),
            'exam_tea'   => 'someone-else',   // 必须被忽略
        ], ['X-CSRF-Token' => $lg['csrf']]);
        $t->assertSame('教师生成补考 -> 200', 200, $res['status']);
        $tid = (int) (Http::data($res)['exam_id'] ?? 0);
        $row = $examRow($tid);
        $t->assertSame('归属强制为登录教师', TEA_A, (string) ($row['exam_tea'] ?? ''));
        $t->assertSame('名单 1 人', 1, count($retakeRoster($tid)));

        // 越权：teacher2 名下考试对 teacher1 一律 404
        $other = Fixture::createExam3(['exam_status' => 'over', 'exam_tea' => TEA_B]);
        $otherId = (int) $other['exam_id'];
        $deny = Http::get("/api/teacher/exams/{$otherId}/retake-candidates");
        $t->assertSame('他人考试候选 -> 404', 404, $deny['status']);
        $deny2 = Http::post("/api/teacher/exams/{$otherId}/retake", [
            'stu_ids'    => [Fixture::STU_1],
            'exam_start' => date('Y-m-d H:i:s', time() + 3600),
            'exam_end'   => date('Y-m-d H:i:s', time() + 7200),
        ], ['X-CSRF-Token' => $lg['csrf']]);
        $t->assertSame('他人考试生成补考 -> 404', 404, $deny2['status']);

        Database::query('DELETE FROM `exam_retake_stu` WHERE exam_id = ?', [$tid]);
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$tid]);
    });

    $t->guard('C10 删除补考场次级联清理名单', function () use ($t, $adminLogin, $retakeRoster, $retakeId) {
        $al = $adminLogin();
        $t->assertSame('管理员重新登录 -> 200', 200, $al['status']);
        $before = count($retakeRoster($retakeId));
        $t->assertSame('删除前名单 2 行', 2, $before);
        $res = Http::delete("/api/admin/exams/{$retakeId}", ['X-CSRF-Token' => $al['csrf']]);
        $t->assertSame('删除 -> 200', 200, $res['status']);
        $t->assertSame('名单已级联清理', 0, count($retakeRoster($retakeId)));
    });

    /* --- C11. 补考场次同样参与成绩公示与证书 --- */
    $t->guard('C11 补考场次同样参与成绩公示', function () use ($t, $examId, $adminLogin, $setExam, $setScore, $studentLogin, $examRow) {
        $al = $adminLogin();
        $res = Http::post("/api/admin/exams/{$examId}/retake", [
            'stu_ids'    => [Fixture::STU_2, Fixture::STU_3],
            'exam_start' => date('Y-m-d H:i:s', time() + 3600),
            'exam_end'   => date('Y-m-d H:i:s', time() + 7200),
        ], ['X-CSRF-Token' => $al['csrf']]);
        $t->assertSame('生成第二场补考 -> 200', 200, $res['status']);
        $rid = (int) (Http::data($res)['exam_id'] ?? 0);
        $t->assertTrue('拿到补考 id', $rid > 0);

        // 走真实流程：先出题（为名单内两人建成绩行），再让该场结束并出分
        $retakeRow = $examRow($rid);
        // 出题只给已进入考场的考生：模拟名单内考生已入场（online）
        foreach (Exam::studentIdsForExam($retakeRow) as $sid) {
            Database::query(
                "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
                 VALUES (?, ?, 0, 'online', '') ON DUPLICATE KEY UPDATE stu_status = 'online'",
                [$rid, $sid]
            );
        }
        \App\Services\ExamEngine::generateForClass($rid, $retakeRow);
        $setScore($rid, Fixture::STU_2, 18);   // 补考通过
        $setScore($rid, Fixture::STU_3, 6);    // 仍未通过
        $setExam($rid, ['exam_status' => 'over', 'score_visibility' => Exam::VIS_CLASS]);

        $studentLogin(Fixture::STU_2);
        $d = Http::data(Http::get("/api/student/score-board?exam_id={$rid}")) ?? [];
        $t->assertSame('补考场次可公示', true, (bool) ($d['allowed'] ?? false));
        $t->assertSame('两人入榜', 2, count($d['rows'] ?? []));
        $t->assertSame('我的名次 1', 1, (int) ($d['me']['rank'] ?? 0));
        $t->assertSame('我的分数 18', 18, (int) ($d['me']['score'] ?? 0));
        $t->assertSame('补考同样按及格线判定', true, (bool) ($d['me']['passed'] ?? false));

        // 补考达标同样发证（达标分 15 由源场次复制而来）
        $cres = Http::get("/api/student/certificates/{$rid}");
        $t->assertSame('补考达标 -> 发证 200', 200, $cres['status']);
        $cert = Http::data($cres)['certificate'] ?? [];
        $t->assertSame('补考证书分数 18', 18, (int) ($cert['score'] ?? 0));
        $t->assertTrue('补考证书考试名含（补考）', str_contains((string) ($cert['exam_name'] ?? ''), '（补考）'));

        Database::query('DELETE FROM `certificate` WHERE exam_id = ?', [$rid]);
        Database::query('DELETE FROM `stupaper` WHERE exam_id = ?', [$rid]);
        Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$rid]);
        Database::query('DELETE FROM `exam_retake_stu` WHERE exam_id = ?', [$rid]);
        Database::query('DELETE FROM `examinfo` WHERE id = ?', [$rid]);
    });

    $t->guard('C12 生成补考接口受 CSRF 保护', function () use ($t, $examId, $adminLogin) {
        $al = $adminLogin();
        $res = Http::post("/api/admin/exams/{$examId}/retake", [
            'stu_ids'    => [Fixture::STU_2],
            'exam_start' => date('Y-m-d H:i:s', time() + 3600),
            'exam_end'   => date('Y-m-d H:i:s', time() + 7200),
        ], ['X-CSRF-Token' => 'invalid-token']);
        $t->assertSame('错误令牌 -> 419', 419, $res['status']);
    });
} finally {
    foreach ($createdExamIds as $id) {
        try {
            Database::query('DELETE FROM `exam_retake_stu` WHERE exam_id = ?', [$id]);
            Database::query('DELETE FROM `certificate` WHERE exam_id = ?', [$id]);
        } catch (\Throwable $e) { /* ignore */ }
    }
    Fixture::cleanup();
    AuthSession::logout();
    sess_forget('exam_session');
}

exit($t->finish());
