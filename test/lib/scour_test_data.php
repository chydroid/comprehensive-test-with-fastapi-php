<?php
declare(strict_types=1);

/**
 * 跨用例污染清扫：在每个后端回归用例启动前执行，删除一切测试夹具产生的
 * 残留数据，保证每个用例都在「干净库」上运行。
 *
 * 为什么需要它（根因）：
 *   后端回归用例共享同一个 MySQL 库，且串行运行。个别「全流程 / 端到端」用例
 *   在 CI（Linux / 不同时序）下若中途崩溃，可能漏执行 Fixture::cleanup()，
 *   留下一个 testing 考场。该考场会让 hasOngoingFormalExam() 全局返回 true，
 *   冻结全站练习 / 模拟考试，导致后续用例（错题本、到期判定等）大量 403 失败。
 *   本脚本在每个用例前无条件清扫，从根上切断这种跨用例污染。
 *
 * 只删除测试前缀的数据（__TEST__ 来自 Fixture，__E2E__ 来自 e2e 脚本），
 * 绝不触碰真实业务数据。同时清理可能因崩溃残留的 wrong_book 备份表，
 * 避免 wrong_book_test 的 RENAME 故障注入测试因旧备份表存在而误伤。
 */

require __DIR__ . '/../../core/helpers.php';
bootstrap();

$prefixes = ["'__TEST__%'", "'__E2E__%'"];
$where = 'exam_name LIKE ' . implode(' OR exam_name LIKE ', $prefixes);

// 先按 exam_id 清理关联表（避免外键 / 级联遗漏），再删 examinfo
$ids = \Core\Database::fetchAll("SELECT id FROM `examinfo` WHERE {$where}");
foreach ($ids as $r) {
    $id = (int) $r['id'];
    foreach (['stupaper', 'stuscore', 'stuscorebak', 'exam_retake_stu', 'certificate', 'exam_manual_quiz'] as $t) {
        try {
            \Core\Database::query("DELETE FROM `{$t}` WHERE exam_id = ?", [$id]);
        } catch (\Throwable $e) {
            // 表可能不存在（旧库结构差异），忽略
        }
    }
}
if ($ids !== []) {
    \Core\Database::query("DELETE FROM `examinfo` WHERE {$where}");
}

// 清理测试夹具考生（9000001~9000005 为 Fixture 保留段）
foreach (['9000001', '9000002', '9000003', '9000004', '9000005'] as $sid) {
    try {
        \Core\Database::query('DELETE FROM `stupaper`  WHERE stu_id = ?', [$sid]);
        \Core\Database::query('DELETE FROM `stuscore`  WHERE stu_id = ?', [$sid]);
        \Core\Database::query('DELETE FROM `stuinfo`   WHERE id = ?', [$sid]);
    } catch (\Throwable $e) {
    }
}

// 清理可能因崩溃残留的 wrong_book 备份表（wrong_book_test 故障注入用）
foreach (['wrong_book_bak_test', 'wrong_book_bak'] as $bak) {
    try {
        \Core\Database::query("DROP TABLE IF EXISTS `{$bak}`");
    } catch (\Throwable $e) {
    }
}

echo 'scour: removed ' . count($ids) . " test exam(s)\n";
