<?php

declare(strict_types=1);

/**
 * B1 防作弊基础能力 —— 端到端回归。
 *
 * 覆盖：
 *  1) CheatGuard 旁路服务：记录异常 + 累计 cheat_count + 事件查询；
 *  2) 选项乱序：启用防作弊时生成试卷写入 option_order，关闭时为空（不影响判分口径）；
 *  3) 上报接口门禁：启用才落库，关闭时静默成功不记录（不泄露开关状态）；
 *  4) 多端互踢：登录写入设备令牌，另一设备登录改令牌，原会话被判定踢出（status 返回 phase:null / paper 返回 409）；
 *  5) 监查侧：名单带 cheat_count、异常记录接口可查（管理端 + 教师端）。
 *
 * 全部数据以 __TEST__ 前缀创建，finally 精确回收（含 cheat_event）。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Models\Quiz;
use App\Services\AuthSession;
use App\Services\CheatGuard;
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
echo "== B1 防作弊基础能力（切屏/水印/异常记录/选项乱序/多端互踢）==\n";

if (!Harness::dbAvailable()) {
    $t->skip('B1 防作弊', '数据库不可用');
    exit($t->finish());
}

const EXP_TEA = 'teacher1';
const EXP_TEA_PWD = 'teacher@2026';
const TEST_ADMIN = '__TEST__b1admin';
const TEST_ADMIN_PWD = 'b1pwd123';

$createdExamIds = [];
$cleanupAdmin = false;

/** 设置/撤销防作弊总开关（落库 siteconfig，并清请求级缓存） */
$setGuard = static function (bool $on): void {
    Setting::putMany(['enable_cheat_guard' => $on ? 1 : 0]);
};
/** 兜底：测试结束时务必关掉开关，避免污染其它用例 */
register_shutdown_function(static function () use ($setGuard) {
    try { $setGuard(false); } catch (\Throwable $e) { /* ignore */ }
});

/** 取某场多选题/单选题的 option_order 是否为合法乱序（排列） */
$orderIsValidPermutation = static function (string $quizOption, string $order): bool {
    if ($order === '') return false;
    $keys = array_map(static fn ($o) => (string) $o['key'], Quiz::parseOptions($quizOption));
    if (count($keys) < 2) return false;
    $given = str_split($order);
    return count($given) === count($keys)
        && array_diff($keys, $given) === []
        && array_diff($given, $keys) === [];
};

/** 选项键序列（用于校验 option_order 是排列） */
$optionKeysOf = static function (string $quizOption): array {
    return array_map(static fn ($o) => (string) $o['key'], Quiz::parseOptions($quizOption));
};

/** 教师登录并出题：返回 [examId, examPwd, csrf] */
$teacherPrepare = static function (array $overrides) use (&$createdExamIds): ?array {
    $row = Database::fetch('SELECT id FROM `teainfo` WHERE tea_name = ?', [EXP_TEA]);
    if ($row === null) {
        Database::query('INSERT INTO `teainfo` (tea_name, tea_pwd, avatar) VALUES (?, ?, ?)', [EXP_TEA, Password::hash(EXP_TEA_PWD), '']);
    } else {
        Database::query('UPDATE `teainfo` SET tea_pwd = ? WHERE id = ?', [Password::hash(EXP_TEA_PWD), $row['id']]);
    }
    AuthSession::logout();
    $login = Http::post('/api/teacher/login', ['username' => EXP_TEA, 'password' => EXP_TEA_PWD]);
    if ($login['status'] !== 200) return null;
    $csrf = Http::data($login)['csrf_token'] ?? '';

    // 教师端出题 / 开考要求教师拥有该考试（exam_tea 与登录教师一致）
    $fx = Fixture::createExam3(array_merge(['exam_tea' => EXP_TEA], $overrides));
    if ($fx === null) return null;
    $eid = (int) $fx['exam_id'];
    $createdExamIds[] = $eid;

    // 出题只面向已进入考场的考生：先让首位考生入场，否则出题得到 0 份试卷
    Database::query(
        "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
         VALUES (?, ?, 0, 'online', '') ON DUPLICATE KEY UPDATE stu_status = 'online'",
        [$eid, (string) $fx['students'][0]]
    );

    Http::post("/api/teacher/exams/{$eid}/generate", [], ['X-CSRF-Token' => $csrf]);
    return ['exam_id' => $eid, 'exam_pwd' => $fx['exam_pwd'], 'csrf' => $csrf, 'students' => $fx['students']];
};

/** 考生入场（返回 [status, csrf, body]） */
$studentLogin = static function (int $examId, string $examPwd, string $stuId) {
    AuthSession::logout();
    sess_forget('exam_session');
    $r = Http::post('/api/exam/login', [
        'exam_id'  => $examId,
        'stu_id'   => $stuId,
        'password' => Fixture::PWD,
        'exam_pwd' => $examPwd,
    ]);
    return ['status' => $r['status'], 'csrf' => Http::data($r)['csrf_token'] ?? '', 'body' => Http::data($r) ?? []];
};

$stuStatusOf = static fn (int $eid, string $stuId): string => (string) (Database::fetch(
    'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$eid, $stuId]
)['stu_status'] ?? '');

$cheatCountOf = static fn (int $eid, string $stuId): int => (int) (Database::fetch(
    'SELECT cheat_count FROM `stuscore` WHERE exam_id = ? AND stu_id = ?', [$eid, $stuId]
)['cheat_count'] ?? 0);

$eventCount = static fn (int $eid): int => (int) (Database::fetch(
    'SELECT COUNT(*) AS c FROM `cheat_event` WHERE exam_id = ?', [$eid]
)['c'] ?? 0);

try {
    /* ==================================================================
     * 1. CheatGuard 旁路服务（不依赖开关）
     * ================================================================== */
    $setGuard(true);
    $srvExam = (Fixture::createExam(['exam_status' => 'exam']))['exam_id'];
    $createdExamIds[] = $srvExam;
    $srvStu = Fixture::STU_A;

    $t->guard('CheatGuard::report 写入事件并累计 cheat_count', function () use ($t, $srvExam, $srvStu, $cheatCountOf, $eventCount) {
        CheatGuard::report($srvExam, $srvStu, CheatGuard::TYPE_TAB_HIDDEN, '切屏');
        $t->assertSame('cheat_event 新增 1 条', 1, $eventCount($srvExam));
        $t->assertSame('stuscore.cheat_count = 1', 1, $cheatCountOf($srvExam, $srvStu));
    });

    $t->guard('CheatGuard::events 倒序返回且包含明细', function () use ($t, $srvExam, $srvStu) {
        $list = CheatGuard::events($srvExam);
        $t->assertSame('events 非空', true, count($list) > 0);
        $t->assertSame('最新事件类型为 tab_hidden', CheatGuard::TYPE_TAB_HIDDEN, $list[0]['event_type'] ?? '');
        $t->assertSame('事件携带考生号', $srvStu, (string) ($list[0]['stu_id'] ?? ''));
        $t->assertSame('事件携带明细', '切屏', (string) ($list[0]['detail'] ?? ''));
    });

    $t->guard('CheatGuard::countByExam 按考生聚合', function () use ($t, $srvExam, $srvStu) {
        CheatGuard::report($srvExam, $srvStu, CheatGuard::TYPE_BLUR, '失焦');
        $map = CheatGuard::countByExam($srvExam);
        $t->assertSame('该考生累计 2 次', 2, (int) ($map[$srvStu] ?? -1));
    });

    /* ==================================================================
     * 2. 选项乱序：开关联动
     * ================================================================== */
    // 2a 开关开：生成试卷写入 option_order
    $setGuard(true);
    $prep = $teacherPrepare(['exam_status' => 'exam']);
    if ($prep === null) {
        $t->skip('选项乱序（开）', '夹具/教师出题失败');
    } else {
        $eid = $prep['exam_id'];
        $stu = (string) $prep['students'][0];
        $t->guard('开启防作弊：生成试卷为单选/多选写入合法 option_order', function () use ($t, $eid, $stu, $orderIsValidPermutation) {
            $rows = Database::fetchAll(
                'SELECT sp.quiz_class, sp.option_order, q.quiz_option
                 FROM `stupaper` sp JOIN `quizlib` q ON q.id = sp.quiz_id
                 WHERE sp.exam_id = ? AND sp.stu_id = ? AND sp.quiz_class IN (\'radio2\',\'checkbox\')',
                [$eid, $stu]
            );
            $t->assertSame('存在单选/多选题目', true, count($rows) > 0);
            $allValid = true;
            foreach ($rows as $r) {
                if (!$orderIsValidPermutation((string) $r['quiz_option'], (string) $r['option_order'])) {
                    $allValid = false;
                    break;
                }
            }
            $t->assertSame('全部 option_order 为合法排列', true, $allValid);
        });
    }

    // 2b 开关关：生成试卷 option_order 必为空
    $setGuard(false);
    $prep2 = $teacherPrepare(['exam_status' => 'exam']);
    if ($prep2 === null) {
        $t->skip('选项乱序（关）', '夹具/教师出题失败');
    } else {
        $eid2 = $prep2['exam_id'];
        $stu2 = (string) $prep2['students'][0];
        $t->guard('关闭防作弊：option_order 全为空（不影响判分）', function () use ($t, $eid2, $stu2) {
            $rows = Database::fetchAll(
                'SELECT sp.option_order FROM `stupaper` sp WHERE sp.exam_id = ? AND sp.stu_id = ?',
                [$eid2, $stu2]
            );
            $emptyAll = true;
            foreach ($rows as $r) {
                if ((string) ($r['option_order'] ?? '') !== '') { $emptyAll = false; break; }
            }
            $t->assertSame('所有题目 option_order 为空', true, $emptyAll);
        });
    }

    /* ==================================================================
     * 3. 上报接口门禁（启用才落库）
     * ================================================================== */
    $setGuard(true);
    $prep3 = $teacherPrepare(['exam_status' => 'exam']);
    if ($prep3 === null) {
        $t->skip('上报门禁（开）', '夹具/教师出题失败');
    } else {
        $eid3 = $prep3['exam_id'];
        $stu3 = (string) $prep3['students'][0];
        $lg = $studentLogin($eid3, $prep3['exam_pwd'], $stu3);
        $t->assertSame('考生入场 -> 200', 200, $lg['status']);
        $t->assertSame('登录响应带 cheat_guard=1', 1, (int) ($lg['body']['cheat_guard'] ?? 0));
        $rep = Http::post('/api/exam/cheat', ['type' => 'tab_hidden', 'detail' => '切屏'], ['X-CSRF-Token' => $lg['csrf']]);
        $t->assertSame('上报切屏 -> 200', 200, $rep['status']);
        $t->assertSame('cheat_event 落库 1 条', 1, $eventCount($eid3));
        $t->assertSame('cheat_count 累计为 1', 1, $cheatCountOf($eid3, $stu3));
    }

    $setGuard(false);
    $prep4 = $teacherPrepare(['exam_status' => 'exam']);
    if ($prep4 === null) {
        $t->skip('上报门禁（关）', '夹具/教师出题失败');
    } else {
        $eid4 = $prep4['exam_id'];
        $stu4 = (string) $prep4['students'][0];
        $lg4 = $studentLogin($eid4, $prep4['exam_pwd'], $stu4);
        $t->assertSame('关闭时登录响应 cheat_guard=0', 0, (int) ($lg4['body']['cheat_guard'] ?? 1));
        $before = $eventCount($eid4);
        $rep4 = Http::post('/api/exam/cheat', ['type' => 'tab_hidden', 'detail' => 'x'], ['X-CSRF-Token' => $lg4['csrf']]);
        $t->assertSame('关闭时上报仍 -> 200（静默成功）', 200, $rep4['status']);
        $t->assertSame('关闭时不落库（事件数不变）', $before, $eventCount($eid4));
        $t->assertSame('关闭时 cheat_count 不变', 0, $cheatCountOf($eid4, $stu4));
    }

    /* ==================================================================
     * 4. 多端互踢
     * ================================================================== */
    $setGuard(true);
    $prep5 = $teacherPrepare(['exam_status' => 'exam']);
    if ($prep5 === null) {
        $t->skip('多端互踢', '夹具/教师出题失败');
    } else {
        $eid5 = $prep5['exam_id'];
        $stu5 = (string) $prep5['students'][0];
        $lg5 = $studentLogin($eid5, $prep5['exam_pwd'], $stu5);
        $t->assertSame('入场 -> 200', 200, $lg5['status']);
        // 模拟「另一台设备」重新登录：把数据库令牌改成新值
        $newToken = bin2hex(random_bytes(16));
        Database::query('UPDATE `stuscore` SET exam_token = ? WHERE exam_id = ? AND stu_id = ?', [$newToken, $eid5, $stu5]);

        $kicked = Http::get('/api/exam/status');
        $kbody = Http::data($kicked) ?? [];
        $t->assertTrue('被踢后 /api/exam/status 返回 phase=null', array_key_exists('phase', $kbody) && $kbody['phase'] === null);
        $t->assertSame('被踢后不再持有考场会话', '', (string) (sess_get('exam_session') ? 'y' : ''));

        // 对照：令牌一致时不被踢（新开一场，保持 DB=会话令牌）
        $prep6 = $teacherPrepare(['exam_status' => 'exam']);
        if ($prep6 !== null) {
            $eid6 = $prep6['exam_id'];
            $stu6 = (string) $prep6['students'][0];
            $lg6 = $studentLogin($eid6, $prep6['exam_pwd'], $stu6);
            $ok = Http::get('/api/exam/status');
            $ob = Http::data($ok) ?? [];
            $t->assertSame('令牌一致时不误踢（phase 非 null）', true, ($ob['phase'] ?? null) !== null);
        }
    }

    /* 4b. 多端互踢在答题接口（paper）侧：开考后被踢返回 409 */
    $setGuard(true);
    $prep7 = $teacherPrepare(['exam_status' => 'exam']);
    if ($prep7 === null) {
        $t->skip('多端互踢（答题接口）', '夹具/教师出题失败');
    } else {
        $eid7 = $prep7['exam_id'];
        $stu7 = (string) $prep7['students'][0];
        // 教师开考
        Http::post("/api/teacher/exams/{$eid7}/start", [], ['X-CSRF-Token' => $prep7['csrf']]);
        $lg7 = $studentLogin($eid7, $prep7['exam_pwd'], $stu7);
        $t->assertSame('开考后入场 -> 200', 200, $lg7['status']);
        $newToken7 = bin2hex(random_bytes(16));
        Database::query('UPDATE `stuscore` SET exam_token = ? WHERE exam_id = ? AND stu_id = ?', [$newToken7, $eid7, $stu7]);
        $paper = Http::get('/api/exam/paper?paper_id=1', [], ['X-CSRF-Token' => $lg7['csrf']]);
        $t->assertSame('被踢后取题 -> 409', 409, $paper['status']);
        $pbody = $paper['body'] ?? [];
        $t->assertSame('409 业务码为 40902（多端登录）', 40902, (int) ($pbody['code'] ?? 0));
    }

    /* ==================================================================
     * 5. 监查侧：名单带 cheat_count + 异常记录接口可查
     * ================================================================== */
    $setGuard(true);
    $prep8 = $teacherPrepare(['exam_status' => 'exam']);
    if ($prep8 === null) {
        $t->skip('监查侧异常记录', '夹具/教师出题失败');
    } else {
        $eid8 = $prep8['exam_id'];
        $stu8 = (string) $prep8['students'][0];
        // 先制造 1 条异常
        CheatGuard::report($eid8, $stu8, CheatGuard::TYPE_TAB_HIDDEN, '切屏');

        // 管理端账号
        $arow = Database::fetch('SELECT id FROM `admininfo` WHERE username = ?', [TEST_ADMIN]);
        if ($arow === null) {
            Database::query(
                'INSERT INTO `admininfo` (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)',
                [TEST_ADMIN, Password::hash(TEST_ADMIN_PWD), 'systemAdmin', '']
            );
            $cleanupAdmin = true;
        } else {
            Database::query('UPDATE `admininfo` SET password = ?, admin_power = ? WHERE id = ?', [Password::hash(TEST_ADMIN_PWD), 'systemAdmin', $arow['id']]);
            $cleanupAdmin = true;
        }
        AuthSession::logout();
        sess_forget('exam_session');
        $alogin = Http::post('/api/admin/login', ['username' => TEST_ADMIN, 'password' => TEST_ADMIN_PWD]);
        $t->assertSame('管理端登录 -> 200', 200, $alogin['status']);
        $acsrf = Http::data($alogin)['csrf_token'] ?? '';

        // 名单含 cheat_count
        $mon = Http::get("/api/admin/monitor?exam_id={$eid8}", [], ['X-CSRF-Token' => $acsrf]);
        $mdata = Http::data($mon) ?? [];
        $roster = $mdata['list'] ?? $mdata['roster'] ?? [];
        $hit = null;
        foreach ($roster as $r) {
            if ((string) ($r['stu_id'] ?? '') === $stu8) { $hit = $r; break; }
        }
        $t->assertSame('监考名单包含该考生', true, $hit !== null);
        $t->assertSame('名单作弊次数 = 1', 1, (int) (($hit ?? [])['cheat_count'] ?? -1));

        // 异常记录接口（管理端）
        $ce = Http::get("/api/admin/monitor/cheat-events?exam_id={$eid8}", [], ['X-CSRF-Token' => $acsrf]);
        $cedata = Http::data($ce) ?? [];
        $events = $cedata['events'] ?? [];
        $t->assertSame('管理端异常记录接口 -> 200', 200, $ce['status']);
        $t->assertSame('异常记录含 1 条', 1, count($events));
        $t->assertSame('记录类型为 tab_hidden', CheatGuard::TYPE_TAB_HIDDEN, (string) (($events[0] ?? [])['event_type'] ?? ''));

        // 异常记录接口（教师端）
        $tlogin = Http::post('/api/teacher/login', ['username' => EXP_TEA, 'password' => EXP_TEA_PWD]);
        $tcsrf = Http::data($tlogin)['csrf_token'] ?? '';
        $tce = Http::get("/api/teacher/monitor/cheat-events?exam_id={$eid8}", [], ['X-CSRF-Token' => $tcsrf]);
        $tcedata = Http::data($tce) ?? [];
        $tEvents = $tcedata['events'] ?? [];
        $t->assertSame('教师端异常记录接口 -> 200', 200, $tce['status']);
        $t->assertSame('教师端异常记录含 1 条', 1, count($tEvents));
    }

    // 无论断言成败都关闭开关
    $setGuard(false);
} finally {
    AuthSession::logout();
    sess_forget('exam_session');
    // 回收 cheat_event（Fixture::cleanup 不清理该表）
    foreach ($createdExamIds as $eid) {
        try { Database::query('DELETE FROM `cheat_event` WHERE exam_id = ?', [$eid]); } catch (\Throwable $e) { /* ignore */ }
    }
    if ($cleanupAdmin) {
        try { Database::query('DELETE FROM `admininfo` WHERE username = ?', [TEST_ADMIN]); } catch (\Throwable $e) { /* ignore */ }
    }
    Fixture::cleanup();
}

exit($t->finish());
