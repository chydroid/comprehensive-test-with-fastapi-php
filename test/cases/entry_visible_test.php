<?php

declare(strict_types=1);

/**
 * 回归：考生「待考列表」在「已开放入场 + 入场窗口内」时必须显示「可入场」
 * （state=open / can_enter=true / pwd_ready=true）。
 *
 * 复现的 BUG：StudentController::exams() 在修 N+1 / 惰性开考的重构中，把原先
 * 「单独 find() 取 exam_pwd 喂给 entryState()」替换成了 pendingForStudent()
 * （其 SELECT 故意不含 exam_pwd）+ 只取 exam_status 的 freshMap。于是 entryState()
 * 再也读不到口令 → pwd_ready 恒 false → 未开考分支一律判 closed（不可入场），
 * 哪怕教师已点「开放入场」、考生也处在入场窗口内。旧的 unset 变成空操作。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
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
echo "== 考生待考列表·可入场可见性回归 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('入口', '数据库不可用');
    exit($t->finish());
}

Fixture::cleanup();

/* 先造考试：Fixture::build 会确保考生 STU_A 存在（含正确 class_id），否则登录时账号不存在 */
$exam = Fixture::createExam();
if ($exam === null) {
    $t->skip('造考试', '题库无可用题目');
    exit($t->finish());
}
$examId = (int) $exam['exam_id'];
echo "  夹具考试 #{$examId}（已开放入场，开考前 5 分钟）\n";

/* 以学生身份登录（个人中心登录态，拿到考生会话与 class_id） */
$login = Http::post('/api/student/login', [
    'username' => Fixture::STU_A,
    'password' => Fixture::PWD,
]);
$t->assertSame('学生登录成功', 200, $login['status']);
if ($login['status'] !== 200) {
    echo $login['raw'] . "\n";
    exit($t->finish());
}

/* 关键：考生列表接口 */
$res = Http::get('/api/student/exams');
$t->assertSame('列表接口 200', 200, $res['status']);
$list = Http::data($res)['list'] ?? [];
$found = null;
foreach ($list as $e) {
    if ((int) $e['id'] === $examId) {
        $found = $e;
        break;
    }
}
$t->assertTrue('列表含本场考试', $found !== null);

if ($found !== null) {
    $t->assertSame('已开放入场+窗内 → state=open', 'open', (string) ($found['state'] ?? ''));
    $t->assertTrue('已开放入场+窗内 → can_enter=true', !empty($found['can_enter']));
    $t->assertTrue('已开放入场 → pwd_ready=true', !empty($found['pwd_ready']));
    // 安全：口令是入场凭证，绝不可下发给考生
    $t->assertTrue('响应不含 exam_pwd', !array_key_exists('exam_pwd', $found));
}

/* 反向：未开放入场（exam_pwd=0）的考试，窗内也不应显示可入场 */
$closed = Fixture::createExam(['exam_pwd' => '0']);
if ($closed !== null) {
    $res2 = Http::get('/api/student/exams');
    $list2 = Http::data($res2)['list'] ?? [];
    $f2 = null;
    foreach ($list2 as $e) {
        if ((int) $e['id'] === (int) $closed['exam_id']) {
            $f2 = $e;
            break;
        }
    }
    if ($f2 !== null) {
        $t->assertTrue('未开放入场 → 不可入场', (string) ($f2['state'] ?? '') !== 'open');
    }
}

Fixture::cleanup();
exit($t->finish());
