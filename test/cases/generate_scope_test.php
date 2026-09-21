<?php

declare(strict_types=1);

/**
 * 出题范围回归测试 —— 锁定「出题只给已进入考场的考生」这一轮业务调整：
 *
 *   BUG-260 出题只给进入考场的考生：未入场的考生（stu_status = waiting）不应分配试卷；
 *            入场窗口为「考试前 10 分钟」、开考后不可入场（由 Setting::exam_entry_lead_minutes 控制）。
 *   BUG-261 无考生入场时提示「无需出卷」：考场无人入场，监考点题应返回
 *            「本考场暂无考生入场，无需出卷」（entered = 0），而非强行给全班出卷。
 *
 * 覆盖两条路径：
 *   1) 服务层 ExamEngine::generateForClass —— 直接断言 entered / generated 与落库卷面；
 *   2) 控制器层 POST /api/admin/exams/{id}/generate —— 断言「无需出卷」提示与 entered 计数。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Services\AuthSession;
use App\Services\ExamEngine;
use App\Services\Password;
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
echo "== 出题范围回归（只给已进入考场的考生 + 无考生提示）==\n";

if (!Harness::dbAvailable()) {
    $t->skip('出题范围回归', '数据库不可用');
    exit($t->finish());
}

const EXP_ADMIN = '__TEST__genscopeadmin';
const EXP_ADMIN_PWD = 'genscope123';

Fixture::cleanup();
Database::query('DELETE FROM `admininfo` WHERE username = ?', [EXP_ADMIN]);

/** 懒建测试管理员（与 publish_cert_retake 同口径） */
$ensureAdmin = static function (): void {
    $arow = Database::fetch('SELECT id FROM `admininfo` WHERE username = ?', [EXP_ADMIN]);
    if ($arow === null) {
        Database::query(
            'INSERT INTO `admininfo` (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)',
            [EXP_ADMIN, Password::hash(EXP_ADMIN_PWD), 'systemAdmin', '']
        );
    } else {
        Database::query(
            'UPDATE `admininfo` SET password = ?, admin_power = ? WHERE id = ?',
            [Password::hash(EXP_ADMIN_PWD), 'systemAdmin', $arow['id']]
        );
    }
};

$adminLogin = static function (): array {
    AuthSession::logout();
    sess_forget('exam_session');
    $r = Http::post('/api/admin/login', ['username' => EXP_ADMIN, 'password' => EXP_ADMIN_PWD]);
    return ['status' => $r['status'], 'csrf' => (string) (Http::data($r)['csrf_token'] ?? '')];
};

$paperStuIds = static fn (int $examId): array => array_map(
    static fn (array $r): string => (string) $r['stu_id'],
    Database::fetchAll('SELECT DISTINCT stu_id FROM `stupaper` WHERE exam_id = ? ORDER BY stu_id', [$examId])
);

$markOnline = static function (int $examId, array $stuIds): void {
    if ($stuIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($stuIds), '?'));
    Database::query(
        "UPDATE `stuscore` SET stu_status = 'online' WHERE exam_id = ? AND stu_id IN ($ph)",
        [$examId, ...$stuIds]
    );
};

/* ============================================================ */
/* 1) 服务层：只给已进入考场的考生出卷（部分入场）            */
/* ============================================================ */
$t->guard('BUG-260 出题只覆盖已进入考场的考生（部分入场）', function () use ($t, $markOnline, $paperStuIds) {
    $fx = Fixture::createExam3(['exam_status' => 'exam']);
    if ($fx === null) {
        $t->skip('出题范围', '题库无可用题目');
        return;
    }
    $examId = (int) $fx['exam_id'];
    [$s1, $s2, $s3] = $fx['students']; // STU_1 / STU_2 / STU_3

    // 仅 STU_1、STU_2 入场（online），STU_3 留在 waiting（未入场）
    $markOnline($examId, [$s1, $s2]);

    $exam = (new Exam())->find($examId);
    $r = ExamEngine::generateForClass($examId, (array) $exam);

    $t->assertSame('entered = 2（仅两名已入场）', 2, $r['entered']);
    $t->assertSame('generated = 2', 2, $r['generated']);
    $t->assertSame('skipped = 0', 0, $r['skipped']);

    $papers = $paperStuIds($examId);
    $t->assertSame('试卷只发给 STU_1/STU_2', [$s1, $s2], $papers);
    $t->assertTrue('STU_3（未入场）没有试卷', !in_array($s3, $papers, true));
});

/* ============================================================ */
/* 2) 服务层：无人入场时 entered = 0                           */
/* ============================================================ */
$t->guard('BUG-261 无人入场时 generateForClass entered = 0', function () use ($t, $paperStuIds) {
    $fx = Fixture::createExam3(['exam_status' => 'exam']);
    if ($fx === null) {
        $t->skip('出题范围', '题库无可用题目');
        return;
    }
    $examId = (int) $fx['exam_id'];
    // 故意不调用 $markOnline —— 全部 waiting（未入场）

    $exam = (new Exam())->find($examId);
    $r = ExamEngine::generateForClass($examId, (array) $exam);

    $t->assertSame('entered = 0', 0, $r['entered']);
    $t->assertSame('generated = 0', 0, $r['generated']);
    $t->assertSame('无人入场不产生任何试卷', [], $paperStuIds($examId));
});

/* ============================================================ */
/* 3) 控制器层：无人入场 -> 「无需出卷」提示（HTTP）          */
/* ============================================================ */
$t->guard('BUG-261 控制器：无人入场返回「本考场暂无考生入场，无需出卷」', function () use ($t, $ensureAdmin, $adminLogin) {
    $ensureAdmin();
    $lg = $adminLogin();
    $t->assertSame('管理端登录 -> 200', 200, $lg['status']);

    $fx = Fixture::createExam3(['exam_status' => 'exam']);
    if ($fx === null) {
        $t->skip('出题范围', '题库无可用题目');
        return;
    }
    $examId = (int) $fx['exam_id'];
    // 无人入场

    $res = Http::post("/api/admin/exams/{$examId}/generate", [], ['X-CSRF-Token' => $lg['csrf']]);
    $t->assertSame('出題接口 -> 200（提示而非报错）', 200, $res['status']);
    $t->assertTrue(
        '提示「本考场暂无考生入场，无需出卷」',
        str_contains((string) (Http::message($res) ?? ''), '本考场暂无考生入场，无需出卷')
    );
    $d = Http::data($res) ?? [];
    $t->assertSame('data.entered = 0', 0, (int) ($d['entered'] ?? -1));
    $t->assertSame('data.generated = 0', 0, (int) ($d['generated'] ?? -1));
});

/* ============================================================ */
/* 4) 控制器层：全部入场 -> 正常出卷（HTTP）                  */
/* ============================================================ */
$t->guard('BUG-260 控制器：全部入场时为 3 名考生出卷', function () use ($t, $adminLogin, $markOnline, $paperStuIds) {
    $lg = $adminLogin();
    $t->assertSame('管理端登录 -> 200', 200, $lg['status']);

    $fx = Fixture::createExam3(['exam_status' => 'exam']);
    if ($fx === null) {
        $t->skip('出题范围', '题库无可用题目');
        return;
    }
    $examId = (int) $fx['exam_id'];
    $markOnline($examId, $fx['students']); // 三人全部入场

    $res = Http::post("/api/admin/exams/{$examId}/generate", [], ['X-CSRF-Token' => $lg['csrf']]);
    $t->assertSame('出題接口 -> 200', 200, $res['status']);
    $d = Http::data($res) ?? [];
    $t->assertSame('data.entered = 3', 3, (int) ($d['entered'] ?? -1));
    $t->assertSame('data.generated = 3', 3, (int) ($d['generated'] ?? -1));
    $t->assertSame('三人全部拿到试卷', $fx['students'], $paperStuIds($examId));
});

Fixture::cleanup();
Database::query('DELETE FROM `admininfo` WHERE username = ?', [EXP_ADMIN]);

exit($t->finish());
