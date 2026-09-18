<?php

declare(strict_types=1);

/**
 * 考场到期（exam_end 已过）的判定与收敛 —— BUG-251 回归。
 *
 * 缺陷原貌
 * --------
 * 「考试是否进行中」此前只看 exam_status，不看时间。于是任何一场**忘记人工点
 * 「结束」**的考场会永远停在 testing，造成三个后果：
 *   ① 全站练习 / 模拟考试被 hasOngoingFormalExam() 永久冻结（所有考生
 *      拿到 reason='exam_ongoing'，后台开关也放不开）；
 *   ② 门户「进行中的考试」常驻一条幽灵考试（activeWithSubject）；
 *   ③ 该场名单里的考生被 isStudentInExam() 永久钉死在「考生在考」硬约束
 *      （reason='self_in_exam'，开关同样放不开）。
 * 真实数据现场：考场 #114 结束于 2026-09-14 19:37，到 09-18 仍在 testing，
 * 把整站练习冻结了 4 天。
 *
 * 修复后的契约（本文件逐条钉住）
 * ------------------------------
 *   A. exam_end 已过的考场不再计入「进行中 / 在考 / 待考」四项读取；
 *   B. Exam::autoEndIfDue() 负责把状态**收敛**为 over（整场判分，幂等）；
 *   C. exam_end 未配置（NULL / 空串 / 零值日期）不算过期，避免历史数据被判死；
 *   D. 未过期的考场行为完全不变（防「改过头」）。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Services\AuthSession;
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
echo "== 考场到期判定与自动收敛（BUG-251）==\n";

if (!Harness::dbAvailable()) {
    $t->skip('考场到期', '数据库不可用');
    exit($t->finish());
}

Fixture::cleanup();

/** 端到端段落使用的教师账号（与项目联调口令一致） */
const EXP_TEA = 'teacher1';
const EXP_TEA_PWD = 'teacher@2026';

/** 行集里是否存在指定考场 id */
$hasExam = static function (array $rows, int $examId): bool {
    foreach ($rows as $r) {
        if ((int) ($r['id'] ?? 0) === $examId) {
            return true;
        }
    }
    return false;
};

$ago      = static fn (int $sec = 60): string => date('Y-m-d H:i:s', time() - $sec);
$later    = static fn (int $sec = 3600): string => date('Y-m-d H:i:s', time() + $sec);
$statusOf = static fn (int $id): string => (string) ((new Exam())->find($id)['exam_status'] ?? '');
$stuStatus = static fn (int $examId, string $stuId): string => (string) (Database::fetch(
    'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
    [$examId, $stuId]
)['stu_status'] ?? '');

$fx = Fixture::createExam([
    'exam_status' => 'testing',
    'exam_start'  => $ago(120),
    'exam_end'    => $later(600),
]);
if ($fx === null) {
    $t->skip('考场到期', '夹具创建失败（题库无四题型齐备的科目）');
    exit($t->finish());
}

$examId = (int) $fx['exam_id'];
$stuA   = (string) $fx['stu_a'];
$stuB   = (string) $fx['stu_b'];
$class  = Fixture::CLASS_ID;

// 考生 A 入场并处于在线态（「人在考场内」），B 保持 waiting
Database::query("UPDATE `stuscore` SET stu_status = 'online' WHERE exam_id = ? AND stu_id = ?", [$examId, $stuA]);

try {
    /* ==================================================================
     * 1. 未过期：行为必须与修复前完全一致（防「改过头」）
     * ================================================================== */
    $t->guard('D 未过期考场仍算「进行中」（防改过头）', function () use ($t, $examId, $stuA, $class, $statusOf, $hasExam) {
        $t->assertSame('前置：状态为进行中', 'testing', $statusOf($examId));
        $t->assertSame('hasOngoingFormalExam() -> true', true, Exam::hasOngoingFormalExam());
        $t->assertSame('isStudentInExam(已在线考生) -> true', true, Exam::isStudentInExam($stuA));
        $t->assertSame('activeWithSubject() 含本场', true, $hasExam(Exam::activeWithSubject(), $examId));
        $t->assertSame('pendingForStudent() 含本场', true, $hasExam(Exam::pendingForStudent($stuA, $class), $examId));
        $t->assertSame('autoEndIfDue() 不动未过期考场', false, Exam::autoEndIfDue($examId));
        $t->assertSame('状态仍为进行中', 'testing', $statusOf($examId));
    });

    /* ==================================================================
     * 2. 过期：四项读取立即排除（不依赖状态是否已收敛）
     * ================================================================== */
    Database::query('UPDATE `examinfo` SET exam_end = ? WHERE id = ?', [$ago(60), $examId]);

    $t->guard('A1/A2/A3 过期后不再计入「进行中 / 在考」', function () use ($t, $examId, $stuA, $stuB, $statusOf) {
        // 读侧必须是「立即正确」的：不能等 autoEndIfDue() 被谁访问到才生效，
        // 否则一场没人访问的过期考场会一直冻结全站。
        $t->assertSame('状态仍停留 testing（未收敛，前置条件）', 'testing', $statusOf($examId));
        $t->assertSame('hasOngoingFormalExam() -> false（全站练习/模拟解冻）', false, Exam::hasOngoingFormalExam());
        $t->assertSame('isStudentInExam(在线考生) -> false', false, Exam::isStudentInExam($stuA));
        $t->assertSame('isStudentInExam(未入场考生) -> false', false, Exam::isStudentInExam($stuB));
        $t->assertSame('模拟考试暂停解除（原 reason=self_in_exam）', ['paused' => false, 'reason' => ''], Exam::mockPause($stuA));
        $t->assertSame('在线练习暂停解除', ['paused' => false, 'reason' => ''], Exam::exercisePause($stuA));
        $t->assertSame('其他考生也不再被全局层冻结', ['paused' => false, 'reason' => ''], Exam::mockPause($stuB));
    });

    $t->guard('A4 过期后从「进行中列表 / 待考列表」消失', function () use ($t, $examId, $stuA, $class, $hasExam) {
        $t->assertSame('activeWithSubject() 不含本场', false, $hasExam(Exam::activeWithSubject(), $examId));
        $t->assertSame('pendingForStudent() 不含本场', false, $hasExam(Exam::pendingForStudent($stuA, $class), $examId));
    });

    /* ==================================================================
     * 3. autoEndIfDue()：把状态收敛为 over（整场判分 + 幂等）
     * ================================================================== */
    $t->guard('B autoEndIfDue() 整场收敛为 over 且判分', function () use ($t, $examId, $stuA, $stuB, $statusOf, $stuStatus) {
        $t->assertSame('本次发生了状态推进', true, Exam::autoEndIfDue($examId));
        $t->assertSame('整场状态 -> over', 'over', $statusOf($examId));
        $t->assertSame('在线考生 A 被强制交卷', true, str_starts_with($stuStatus($examId, $stuA), 'over'));
        $t->assertSame('未入场考生 B 也被强制交卷', true, str_starts_with($stuStatus($examId, $stuB), 'over'));
        $t->assertSame('已结束时重复调用幂等（不再推进）', false, Exam::autoEndIfDue($examId));
        $t->assertSame('重复调用后状态仍为 over', 'over', $statusOf($examId));
        $t->assertSame('收敛后 hasOngoingFormalExam() 仍为 false', false, Exam::hasOngoingFormalExam());
    });

    $t->guard('B 收敛后成绩不被二次判分覆盖（幂等门禁）', function () use ($t, $examId, $stuA) {
        $first = (int) (Database::fetch(
            'SELECT stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuA]
        )['stu_score'] ?? -1);
        // 人为把成绩改成一个可识别的值，再触发一次收敛：幂等门禁必须保住它
        Database::query('UPDATE `stuscore` SET stu_score = 77 WHERE exam_id = ? AND stu_id = ?', [$examId, $stuA]);
        Exam::autoEndIfDue($examId);
        $after = (int) (Database::fetch(
            'SELECT stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuA]
        )['stu_score'] ?? -1);
        $t->assertSame('已交卷成绩不被重复判分覆盖', 77, $after);
        $t->assertSame('首次判分确实执行过（非空转）', true, $first >= 0);
    });

    /* ==================================================================
     * 4. 边界：exam_end 未配置不算过期
     *
     * 注意：本机 sql_mode 含 STRICT_TRANS_TABLES，datetime 列**拒绝空串**
     * （1292），故「空串」只作为不可写入的分支记录，不作为产品断言。
     * ================================================================== */
    $t->guard('C exam_end 未配置（NULL/零值日期）不算过期', function () use ($t, $examId, $statusOf) {
        $restore = static function () use ($examId): void {
            Database::query(
                "UPDATE `examinfo` SET exam_status = 'over', exam_end = ? WHERE id = ?",
                [date('Y-m-d H:i:s', time() - 600), $examId]
            );
        };
        try {
            foreach ([
                'NULL'   => null,
                '零值日期' => '0000-00-00 00:00:00',
            ] as $label => $value) {
                try {
                    Database::query(
                        "UPDATE `examinfo` SET exam_status = 'testing', exam_end = ? WHERE id = ?",
                        [$value, $examId]
                    );
                } catch (\Throwable $e) {
                    // 环境（sql_mode）拒绝该值：诚实标注，而不是伪装通过
                    $t->skip("{$label}：本机 MySQL 拒绝写入该值", $e->getMessage());
                    continue;
                }
                $t->assertSame("{$label}：不误判为过期（不自动结束）", false, Exam::autoEndIfDue($examId));
                $t->assertSame("{$label}：状态保持进行中", 'testing', $statusOf($examId));
            }

            // 空串：STRICT 模式拒绝写入 datetime 列，SQL 里的 `= ''` 分支属防御性兜底
            try {
                Database::query("UPDATE `examinfo` SET exam_end = '' WHERE id = ?", [$examId]);
                $t->assertSame('空串：不误判为过期', false, Exam::autoEndIfDue($examId));
            } catch (\Throwable $e) {
                $t->skip('空串：STRICT 模式拒绝写入 datetime', '1292 Incorrect datetime value');
            }
        } finally {
            // 必须无条件收束：一场 exam_end 为 NULL 的 testing 考场在业务上是
            // 「未过期」的，留着它会反过来冻结后续用例（本用例第一版即栽在这里）
            $restore();
        }
        $t->assertSame('本场已收束为 over', 'over', $statusOf($examId));
    });

    /* ==================================================================
     * 5. 端到端：考生轮询触发整场结束（真实调用路径）
     *
     * 走 ExamController::status()（答题页唯一的启动/轮询入口）。它内部
     * 先 autoStartIfDue 再 autoEndIfDue —— 修复前这里只判分「当前考生」，
     * 整场永远停在 testing，正是全站冻结的源头。
     * ================================================================== */
    $t->guard('E2E 考生轮询 /api/exam/status 触发整场结束', function () use ($t, $ago, $later, $statusOf, $stuStatus) {
        AuthSession::logout();
        $row = Database::fetch('SELECT id FROM `teainfo` WHERE tea_name = ?', [EXP_TEA]);
        if ($row === null) {
            Database::query("INSERT INTO `teainfo` (tea_name, tea_pwd, avatar) VALUES (?, ?, '')", [EXP_TEA, Password::hash(EXP_TEA_PWD)]);
        } else {
            Database::query('UPDATE `teainfo` SET tea_pwd = ? WHERE id = ?', [Password::hash(EXP_TEA_PWD), $row['id']]);
        }

        $e2e = Fixture::createExam3([
            'exam_tea'    => EXP_TEA,
            'exam_status' => 'exam',
            'exam_start'  => $later(180),
            'exam_end'    => $later(3600),
        ]);
        if ($e2e === null) {
            $t->skip('E2E 考场到期收敛', '夹具创建失败');
            return;
        }
        $eid = (int) $e2e['exam_id'];
        $stu = (string) $e2e['students'][0];

        // 教师登录 -> 出题（exam → paper）
        $login = Http::post('/api/teacher/login', ['username' => EXP_TEA, 'password' => EXP_TEA_PWD]);
        $t->assertSame('教师登录 -> 200', 200, $login['status']);
        Http::post("/api/teacher/exams/{$eid}/generate", [], ['X-CSRF-Token' => AuthSession::csrfToken()]);
        $t->assertSame('出题后状态为已排卷', 'paper', $statusOf($eid));

        // 考生入场（等待室）
        AuthSession::logout();
        $enter = Http::post('/api/exam/login', [
            'exam_id'  => $eid,
            'stu_id'   => $stu,
            'password' => Fixture::PWD,
            'exam_pwd' => $e2e['exam_pwd'],
        ]);
        $t->assertSame('考生入场 -> 200', 200, $enter['status']);

        // 时间流逝：开考时间与结束时间都拨到过去（等价于「考试已超时」）
        Database::query(
            'UPDATE `examinfo` SET exam_start = ?, exam_end = ? WHERE id = ?',
            [$ago(7200), $ago(600), $eid]
        );

        $res    = Http::get('/api/exam/status');
        $status = Http::data($res) ?? [];
        $t->assertSame('轮询 -> 200', 200, $res['status']);
        $t->assertSame('整场状态被轮询收敛为 over', 'over', $statusOf($eid));
        $t->assertSame('响应内 exam_status 为 over', 'over', (string) ($status['exam']['exam_status'] ?? ''));
        $t->assertSame('考生阶段为已交卷', 'submitted', (string) ($status['phase'] ?? ''));
        $t->assertSame('考生成绩记录已交卷', true, str_starts_with($stuStatus($eid, $stu), 'over'));
        $t->assertSame('全站「进行中正式考试」判定恢复为 false', false, Exam::hasOngoingFormalExam());
        $t->assertSame('重复轮询不再重复结束（幂等）', false, Exam::autoEndIfDue($eid));
    });
} finally {
    // 无论断言成败都精确回收夹具，避免留下的 testing 考场反过来冻结其它测试
    AuthSession::logout();
    Fixture::cleanup();
}

exit($t->finish());
