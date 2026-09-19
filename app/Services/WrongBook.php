<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Quiz;
use Core\Database;

/**
 * 错题本服务（纯旁路，绝不参与业务事务）
 *
 * 三个落点调用本服务：
 * - 正式考试判分 ExamEngine::autoGrade（exam_type=formal）
 * - 模拟考试判分 ExerciseExamController::gradeMock（exam_type=mock）
 * - 在线练习校验 ExerciseController::check（exam_type=exercise）
 *
 * 与 Audit 服务同一原则：所有写操作包在 try/catch 内，失败只 error_log，
 * 绝不抛异常、不回滚、不影响主流程（考试/练习判分不受影响）。
 */
final class WrongBook
{
    /** exam_type 合法值 */
    private const TYPES = ['formal' => 1, 'mock' => 1, 'exercise' => 1];

    /**
     * 沉淀一道错题（覆盖写：重复答错累加次数并复位 mastered）。
     * @param string $stuId   准考证号
     * @param int    $quizId  题目 id
     * @param string $examType formal|mock|exercise
     * @param int|null $examId 来源考试 id（练习为 null）
     * @param int|null $paperId stupaper.paper_id（练习为 null）
     */
    public static function collect(
        string $stuId, int $quizId, string $examType,
        ?int $examId = null, ?int $paperId = null
    ): void {
        if (!isset(self::TYPES[$examType]) || $quizId <= 0) {
            return;
        }
        try {
            $now = date('Y-m-d H:i:s');
            Database::query(
                "INSERT INTO `wrong_book` (stu_id, quiz_id, exam_type, exam_id, paper_id, wrong_count, mastered, last_wrong_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 0, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    wrong_count   = wrong_count + 1,
                    mastered      = 0,
                    exam_type     = VALUES(exam_type),
                    exam_id       = VALUES(exam_id),
                    paper_id      = VALUES(paper_id),
                    last_wrong_at = VALUES(last_wrong_at),
                    updated_at    = VALUES(updated_at)",
                [$stuId, $quizId, $examType, $examId, $paperId, $now, $now, $now]
            );
        } catch (\Throwable $e) {
            error_log('[WrongBook] collect failed: ' . $e->getMessage());
        }
    }

    /** 批量沉淀（判分循环内调用，减少往返） */
    public static function collectMany(string $stuId, array $items, string $examType, ?int $examId = null): void
    {
        if ($items === []) {
            return;
        }
        foreach ($items as $it) {
            $quizId = (int) ($it['quiz_id'] ?? 0);
            $paperId = isset($it['paper_id']) ? (int) $it['paper_id'] : null;
            if ($quizId > 0) {
                self::collect($stuId, $quizId, $examType, $examId, $paperId);
            }
        }
    }

    /**
     * 重练答对 → 标记已掌握（mastered=1）。
     * 对不在错题本内的题目调用无副作用（仅影响匹配行）。
     */
    public static function markMastered(string $stuId, int $quizId): void
    {
        try {
            Database::query(
                "UPDATE `wrong_book` SET mastered = 1, updated_at = ? WHERE stu_id = ? AND quiz_id = ?",
                [date('Y-m-d H:i:s'), $stuId, $quizId]
            );
        } catch (\Throwable $e) {
            error_log('[WrongBook] markMastered failed: ' . $e->getMessage());
        }
    }

    /**
     * 列出某考生的错题本（按科目聚合题目信息）。
     * @param array{fastered?:int,subj_id?:int,quiz_class?:string,exam_type?:string} $filters
     * @return array{data:array,total:int,stats:array}
     */
    public static function list(string $stuId, array $filters, int $page, int $perPage): array
    {
        $where = ['wb.stu_id = ?'];
        $params = [$stuId];
        if (isset($filters['mastered'])) {
            $where[] = 'wb.mastered = ?';
            $params[] = $filters['mastered'] ? 1 : 0;
        }
        if (!empty($filters['subj_id'])) {
            $where[] = 'q.subj_id = ?';
            $params[] = (int) $filters['subj_id'];
        }
        if (!empty($filters['quiz_class'])) {
            $where[] = 'q.quiz_class = ?';
            $params[] = (string) $filters['quiz_class'];
        }
        if (!empty($filters['exam_type'])) {
            $where[] = 'wb.exam_type = ?';
            $params[] = (string) $filters['exam_type'];
        }
        $sqlWhere = implode(' AND ', $where);

        $total = (int) (Database::fetch(
            "SELECT COUNT(*) AS c FROM `wrong_book` wb
             INNER JOIN `quizlib` q ON q.id = wb.quiz_id
             WHERE {$sqlWhere}",
            $params
        )['c'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::fetchAll(
            "SELECT wb.id, wb.quiz_id, wb.exam_type, wb.exam_id, wb.wrong_count, wb.mastered, wb.last_wrong_at,
                    q.subj_id, s.subj_name, q.quiz_title, q.quiz_class, q.quiz_option, q.quiz_diff, q.quiz_pic_name
             FROM `wrong_book` wb
             INNER JOIN `quizlib` q ON q.id = wb.quiz_id
             INNER JOIN `subject` s ON s.id = q.subj_id
             WHERE {$sqlWhere}
             ORDER BY wb.wrong_count DESC, wb.last_wrong_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['data' => $rows, 'total' => $total, 'stats' => self::stats($stuId)];
    }

    /** 错题本统计（供卡片展示） */
    public static function stats(string $stuId): array
    {
        $rows = Database::fetchAll(
            "SELECT wb.mastered, q.subj_id, s.subj_name, COUNT(*) AS c, SUM(wb.wrong_count) AS wc
             FROM `wrong_book` wb
             INNER JOIN `quizlib` q ON q.id = wb.quiz_id
             INNER JOIN `subject` s ON s.id = q.subj_id
             WHERE wb.stu_id = ?
             GROUP BY wb.mastered, q.subj_id, s.subj_name",
            [$stuId]
        );
        $bySubject = [];
        $total = 0;
        $mastered = 0;
        $wrongTimes = 0;
        foreach ($rows as $r) {
            $sid = (int) $r['subj_id'];
            $name = $r['subj_name'];
            $bySubject[$sid] = $bySubject[$sid]
                ?? ['subj_id' => $sid, 'subj_name' => $name, 'total' => 0, 'mastered' => 0];
            $c = (int) $r['c'];
            $wc = (int) ($r['wc'] ?? 0);
            $bySubject[$sid]['total'] += $c;
            $wrongTimes += $wc;
            if ((int) $r['mastered'] === 1) {
                $bySubject[$sid]['mastered'] += $c;
                $mastered += $c;
            }
            $total += $c;
        }
        return [
            'total'      => $total,
            'mastered'   => $mastered,
            'unmastered' => $total - $mastered,
            'wrong_times'=> $wrongTimes,
            'by_subject' => array_values($bySubject),
        ];
    }

    /**
     * 抽取待重练题目（优先未掌握，同组按答错次数降序）。
     * 仅返回题目内容，不下发正确答案（答案走 check 接口）。
     * @return array{questions:array,total:int}
     */
    public static function practicePick(string $stuId, ?int $subjId, int $limit): array
    {
        $where = ['wb.stu_id = ?'];
        $params = [$stuId];
        if ($subjId > 0) {
            $where[] = 'q.subj_id = ?';
            $params[] = $subjId;
        }
        $sqlWhere = implode(' AND ', $where);
        $rows = Database::fetchAll(
            "SELECT wb.quiz_id,
                    q.quiz_title, q.quiz_class, q.quiz_option, q.quiz_pic_name, q.quiz_diff,
                    s.subj_name, wb.mastered
             FROM `wrong_book` wb
             INNER JOIN `quizlib` q ON q.id = wb.quiz_id
             INNER JOIN `subject` s ON s.id = q.subj_id
             WHERE {$sqlWhere}
             ORDER BY wb.mastered ASC, wb.wrong_count DESC
             LIMIT {$limit}",
            $params
        );
        $questions = [];
        foreach ($rows as $r) {
            $questions[] = Quiz::withoutAnswer($r);
        }
        return ['questions' => $questions, 'total' => count($questions)];
    }

    /** 清空某考生的错题本（自清理 / 管理用） */
    public static function clear(string $stuId): int
    {
        try {
            return Database::query('DELETE FROM `wrong_book` WHERE stu_id = ?', [$stuId])->rowCount();
        } catch (\Throwable $e) {
            error_log('[WrongBook] clear failed: ' . $e->getMessage());
            return 0;
        }
    }
}
