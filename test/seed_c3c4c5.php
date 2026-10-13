<?php
// 为 c3c4c5 UI 冒烟造一个 teacher1 的「已结束正式考试」，使：
//   C5-1 教师端考试列表对已结束考试提供「问卷」入口
//   C4-1 批改页可渲染（真实题库无 longtext 主观题，故批改页为空态，断言容错空态）
// 用 __E2E__ 前缀，运行后由调用方按 exam_name 精确回收，绝不污染真实数据。
require 'core/helpers.php';
bootstrap();

const EXAM_NAME = '__E2E__c3c4c5_smoke';
const TEA = 'teacher1';
const STU = '165165';

// 幂等：先清后建
\Core\Database::query('DELETE FROM `examinfo` WHERE exam_name = ?', [EXAM_NAME]);

\Core\Database::query(
    "INSERT INTO `examinfo`
       (exam_name, subj_id, exam_tea, exam_class, exam_status, exam_start, exam_end, stu_class, paper_mode)
     VALUES (?, 158, ?, '正式', 'over', NOW(), NOW(), 0, 'random')",
    [EXAM_NAME, TEA]
);
$eid = (int) \Core\Database::lastInsertId();

// 关联一名考生成绩，使该考试成为「有考生、已结束」的真实记录
\Core\Database::query(
    "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
     VALUES (?, ?, 60, 'over', '123456')",
    [$eid, STU]
);

echo json_encode(['exam_id' => $eid, 'exam_name' => EXAM_NAME], JSON_UNESCAPED_UNICODE) . "\n";
