<?php
// 为 waitroom / lifecycle 两个 UI 冒烟造两个独立的可考考场（各 2 名夹具考生）。
// 考生账号：9000001 / 9000002，密码 testPwd123（Fixture::PWD）。
require 'core/helpers.php';
bootstrap();
require 'test/lib/Fixture.php';

function bindTeacher(int $examId, string $teacherName): void {
    \Core\Database::query(
        "UPDATE `examinfo` SET exam_tea = ? WHERE id = ?",
        [$teacherName, $examId]
    );
}

$a = \Test\Fixture::createExam();
$b = \Test\Fixture::createExam();
if ($a !== null) { bindTeacher((int) $a['exam_id'], 'teacher1'); }
if ($b !== null) { bindTeacher((int) $b['exam_id'], 'teacher1'); }
if ($a === null || $b === null) {
    fwrite(STDERR, "造场失败\n");
    exit(1);
}
echo json_encode([
    'waitroom'  => ['exam_id' => $a['exam_id'], 'exam_pwd' => $a['exam_pwd'], 'stu_a' => $a['stu_a']],
    'lifecycle' => ['exam_id' => $b['exam_id'], 'exam_pwd' => $b['exam_pwd'], 'stu_a' => $b['stu_a']],
], JSON_UNESCAPED_UNICODE) . "\n";
