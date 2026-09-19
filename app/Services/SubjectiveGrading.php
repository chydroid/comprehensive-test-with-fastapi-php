<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use Core\Database;

/**
 * A4 主观题批改。
 *
 * 背景：问答题（longtext）没有可自动比对的答案键，`ExamEngine::autoGrade()`
 * 对它只做 `continue`，交卷后其分值恒为 0。此前系统连「出一道问答题」的能力
 * 都没有（组卷矩阵只覆盖四种客观题），因此批阅链路是整条补上的：
 *
 *   A4 schema（examinfo.longtext_* / stupaper.quiz_score…）
 *     → Exam::TYPE_PREFIXES 纳入 longtext（能出卷、能作答）
 *     → 本服务（教师逐题给分）
 *     → ExamEngine::recomputeScore()（把主观分并回总分）
 *
 * 三条口径约定：
 *  1. **未批阅 ≠ 答错**：`quiz_score IS NULL` 的主观题既不计分，也不计入任何
 *     正确率分母，避免成绩在批阅前出现「虚假的 0 分」。
 *  2. **得分落在答卷行**：每份答卷的主观题一行一题，得分写回该行
 *     （quiz_score / quiz_comment / grader_name / graded_at），不另建汇总表，
 *     这样「谁在什么时候给谁打了多少分、写了什么评语」天然可追溯。
 *  3. **单题与卷面双重封顶**：单题得分不超过 examinfo 的同题型分值，
 *     总封顶由 ExamEngine::recomputeScore() 负责。
 */
final class SubjectiveGrading
{
    /** 主观题题型的 SQL IN 列表（值来自 Exam::SUBJECTIVE_TYPES，避免两处硬编码分叉） */
    private static function typesSql(): string
    {
        return "'" . implode("','", array_map(
            static fn (string $t): string => str_replace("'", '', $t),
            Exam::SUBJECTIVE_TYPES
        )) . "'";
    }

    /**
     * 待批阅总览：本场考试所有**已交卷**考生的主观题批阅进度。
     *
     * 只统计 LEFT(stu_status,4)='over' 的考生 —— 与 A2 成绩分析同一口径，
     * 未交卷考生的答卷还在变动，列出来只会让教师白批一遍。
     *
     * @return array{students:int,total_items:int,pending_items:int,pending_students:int,list:array}
     */
    public static function overview(int $examId): array
    {
        $rows = Database::fetchAll(
            'SELECT sp.stu_id,
                    si.stu_name, si.grade_id, si.class_id,
                    ss.stu_score, ss.stu_status,
                    COUNT(*) AS items,
                    SUM(CASE WHEN sp.quiz_score IS NULL THEN 1 ELSE 0 END) AS pending
             FROM `stupaper` sp
             INNER JOIN `stuscore` ss ON ss.exam_id = sp.exam_id AND ss.stu_id = sp.stu_id
             LEFT JOIN `stuinfo` si ON si.id = sp.stu_id
             WHERE sp.exam_id = ? AND sp.quiz_class IN (' . self::typesSql() . ")
               AND LEFT(ss.stu_status, 4) = 'over'
             GROUP BY sp.stu_id, si.stu_name, si.grade_id, si.class_id, ss.stu_score, ss.stu_status
             ORDER BY pending DESC, sp.stu_id ASC
             LIMIT 500",
            [$examId]
        );

        $students = 0;
        $totalItems = 0;
        $pendingItems = 0;
        $pendingStudents = 0;
        $list = [];
        foreach ($rows as $r) {
            $items = (int) $r['items'];
            $pending = (int) $r['pending'];
            $students++;
            $totalItems += $items;
            $pendingItems += $pending;
            if ($pending > 0) {
                $pendingStudents++;
            }
            $list[] = [
                'stu_id'    => (string) $r['stu_id'],
                'stu_name'  => (string) ($r['stu_name'] ?? ''),
                'grade_id'  => (string) ($r['grade_id'] ?? ''),
                'class_id'  => (string) ($r['class_id'] ?? ''),
                'stu_score' => (int) ($r['stu_score'] ?? 0),
                'items'     => $items,
                'graded'    => $items - $pending,
                'pending'   => $pending,
            ];
        }

        return [
            'students'         => $students,
            'total_items'      => $totalItems,
            'pending_items'    => $pendingItems,
            'pending_students' => $pendingStudents,
            'list'             => $list,
        ];
    }

    /**
     * 某考生的主观题答题卡（含参考答案与考生作答，供教师对照给分）。
     *
     * @return array{items:array,full_score:int,breakdown:array,graded:int,pending:int}
     */
    public static function paper(int $examId, string $stuId): array
    {
        $exam = (new Exam())->find($examId);
        $full = (int) ($exam['longtext_val'] ?? 0);

        $items = array_values(array_filter(
            ExamEngine::paperWithAnswers($examId, $stuId),
            static fn (array $r): bool => (bool) ($r['is_subjective'] ?? false)
        ));
        $graded = 0;
        foreach ($items as &$r) {
            $r['full_score'] = $full;
            if (!empty($r['graded'])) {
                $graded++;
            }
        }
        unset($r);

        return [
            'items'      => $items,
            'full_score' => $full,
            'graded'     => $graded,
            'pending'    => count($items) - $graded,
            'breakdown'  => ExamEngine::scoreBreakdown($examId, $stuId),
        ];
    }

    /**
     * 提交批阅并重算总分。
     *
     * @param array<int,array{paper_id?:int,score?:int,comment?:string}> $items
     * @return array{saved:int,skipped:int,breakdown:array}
     */
    public static function grade(int $examId, string $stuId, array $items, string $grader): array
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            throw new \RuntimeException("考试不存在: {$examId}");
        }
        $full = (int) ($exam['longtext_val'] ?? 0);

        // 只允许改「本场考试的本人主观题」，其余 paper_id 一律忽略：
        // 批阅请求由前端构造，不能凭它写到别人的答卷或非主观题行上。
        $valid = [];
        foreach (Database::fetchAll(
            'SELECT paper_id FROM `stupaper`
             WHERE exam_id = ? AND stu_id = ? AND quiz_class IN (' . self::typesSql() . ')',
            [$examId, $stuId]
        ) as $r) {
            $valid[(int) $r['paper_id']] = true;
        }

        $saved = 0;
        $skipped = 0;
        Database::beginTransaction();
        try {
            foreach ($items as $it) {
                $paperId = (int) ($it['paper_id'] ?? 0);
                if (!isset($valid[$paperId])) {
                    $skipped++;
                    continue;
                }
                $score = (int) ($it['score'] ?? 0);
                if ($score < 0) {
                    $score = 0;
                }
                if ($full > 0 && $score > $full) {
                    $score = $full;   // 单题封顶
                }
                $comment = mb_substr(trim((string) ($it['comment'] ?? '')), 0, 500);

                Database::query(
                    'UPDATE `stupaper`
                     SET quiz_score = ?, quiz_comment = ?, grader_name = ?, graded_at = NOW()
                     WHERE exam_id = ? AND stu_id = ? AND paper_id = ? AND quiz_class IN (' . self::typesSql() . ')',
                    [$score, $comment, $grader, $examId, $stuId, $paperId]
                );
                $saved++;
            }

            $breakdown = ExamEngine::recomputeScore($examId, $stuId);
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return ['saved' => $saved, 'skipped' => $skipped, 'breakdown' => $breakdown];
    }

    /**
     * 撤销某考生全部主观题批阅（清空得分，总分回落为客观题得分）。
     * 用于教师误批后的回退，避免「只能改不能清」。
     */
    public static function revoke(int $examId, string $stuId): array
    {
        Database::query(
            'UPDATE `stupaper`
             SET quiz_score = NULL, quiz_comment = \'\', grader_name = \'\', graded_at = NULL
             WHERE exam_id = ? AND stu_id = ? AND quiz_class IN (' . self::typesSql() . ')',
            [$examId, $stuId]
        );
        return ExamEngine::recomputeScore($examId, $stuId);
    }
}
