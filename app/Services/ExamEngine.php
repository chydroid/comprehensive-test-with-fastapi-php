<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use App\Models\Quiz;
use Core\Database;

/**
 * 考试引擎：组卷、作答保存、自动判分。
 *
 * 从旧系统的 Paper / Exam / Question 三个模型抽取而成，行为保持一致但修正了以下缺陷：
 *
 * 1. **组卷可重入**：已有试卷时直接返回，不会重复排卷（旧系统同样如此，此处显式保留）。
 * 2. **组卷缺题可见**：旧系统若某题型题库不足，静默产生"少题"试卷，考生与教师都不知情。
 *    本实现返回 warnings，由调用方决定是否阻断。
 * 3. **判分归一化统一**：判断题 Y/N→A/B 的兼容逻辑收敛到 Quiz::normalizeAnswer，
 *    避免旧系统在 review() 中忘记归一化导致的对照答案误判。
 * 4. **随机抽题**：使用 ORDER BY RAND()（与旧系统一致）。题量千级时性能可接受；
 *    后续可换为「预生成随机 id 列表 + 主键 IN」以进一步优化。
 */
final class ExamEngine
{
    /** 抽题顺序与难度映射（与旧系统一致） */
    private const DIFF_ORDER = ['easy' => 'Y', 'mid' => 'Z', 'hard' => 'N'];

    /**
     * 为考生组卷。
     *
     * @return array{generated:bool, warnings:array<int,array{type:string,diff:string,need:int,have:int}>}
     */
    public static function generatePaper(int $examId, string $stuId): array
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            return ['generated' => false, 'warnings' => []];
        }

        // 已有试卷 → 幂等返回，不重复排卷
        $existing = Database::fetch(
            'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        if ((int) ($existing['c'] ?? 0) > 0) {
            return ['generated' => false, 'warnings' => []];
        }

        $warnings = [];

        Database::beginTransaction();
        try {
            // 确保成绩记录存在（考场口令/状态管理依赖它）
            $hasScore = Database::fetch(
                'SELECT id FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
                [$examId, $stuId]
            );
            if ($hasScore === null) {
                self::createScore($examId, $stuId, '');
            }

            $paperId = 1;
            $subjId = (int) ($exam['subj_id'] ?? 0);

            foreach (Exam::TYPE_PREFIXES as $type) {
                foreach (self::DIFF_ORDER as $field => $diffCode) {
                    $need = (int) ($exam["{$type}_{$field}_sum"] ?? 0);
                    if ($need <= 0) {
                        continue;
                    }
                    $questions = self::drawQuestions($subjId, $type, $diffCode, $need);
                    if (count($questions) < $need) {
                        // 缺题：记录但不中断，保证已抽到的题仍可用（行为与旧系统一致，但不再静默）
                        $warnings[] = [
                            'type' => $type,
                            'diff' => $diffCode,
                            'need' => $need,
                            'have' => count($questions),
                        ];
                    }
                    foreach ($questions as $q) {
                        Database::query(
                            'INSERT INTO `stupaper`
                                (exam_id, stu_id, paper_id, quiz_id, quiz_class, stu_key, quiz_status)
                             VALUES (?, ?, ?, ?, ?, \'\', 0)',
                            [$examId, $stuId, $paperId, (int) $q['id'], $type]
                        );
                        $paperId++;
                    }
                }
            }

            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return ['generated' => true, 'warnings' => $warnings];
    }

    /** 随机抽题：按科目 + 题型 + 难度 */
    private static function drawQuestions(int $subjId, string $type, string $diffCode, int $limit): array
    {
        $limit = max(1, $limit);
        return Database::fetchAll(
            'SELECT id FROM `quizlib`
             WHERE subj_id = ? AND quiz_class = ? AND quiz_diff = ?
             ORDER BY RAND() LIMIT ' . $limit,
            [$subjId, $type, $diffCode]
        );
    }

    /** 创建成绩记录（stu_pwd 为考场口令快照） */
    public static function createScore(int $examId, string $stuId, string $examPwd): void
    {
        Database::query(
            "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
             VALUES (?, ?, 0, 'waiting', ?)",
            [$examId, $stuId, $examPwd]
        );
    }

    /**
     * 自动判分（客观题）。返回本次得分。
     * longtext（问答题）不计分，留待人工批阅。
     */
    public static function autoGrade(int $examId, string $stuId): int
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            return 0;
        }
        $valMap = [
            'radio1'   => (int) ($exam['radio1_val'] ?? 0),
            'radio2'   => (int) ($exam['radio2_val'] ?? 0),
            'checkbox' => (int) ($exam['checkbox_val'] ?? 0),
            'text'     => (int) ($exam['text_val'] ?? 0),
            'longtext' => 0,
        ];

        $rows = Database::fetchAll(
            'SELECT sp.paper_id, sp.quiz_class, sp.stu_key, q.quiz_key
             FROM `stupaper` sp
             INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ?
             ORDER BY sp.paper_id ASC',
            [$examId, $stuId]
        );

        $score = 0;
        Database::beginTransaction();
        try {
            foreach ($rows as $r) {
                // 标记已批阅
                Database::query(
                    'UPDATE `stupaper` SET quiz_status = 1
                     WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
                    [$examId, $stuId, (int) $r['paper_id']]
                );

                $type = (string) $r['quiz_class'];
                if ($type === 'longtext') {
                    continue; // 问答题不自动判分
                }
                $answer = (string) ($r['stu_key'] ?? '');
                $correct = (string) ($r['quiz_key'] ?? '');
                if ($answer === '' || $correct === '') {
                    continue;
                }
                if (Quiz::isCorrect($type, $correct, $answer)) {
                    $score += $valMap[$type] ?? 0;
                }
            }

            Database::query(
                'UPDATE `stuscore` SET stu_score = ?, stu_status = ? WHERE exam_id = ? AND stu_id = ?',
                [$score, 'over', $examId, $stuId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $score;
    }

    /**
     * 答卷列表 + 题目详情（含正确答案），供「查看答案」与教师批阅使用。
     * 注意：**此方法会返回 quiz_key**，仅可用于交卷后或管理端。
     */
    public static function paperWithAnswers(int $examId, string $stuId): array
    {
        $rows = Database::fetchAll(
            'SELECT sp.paper_id, sp.quiz_id, sp.quiz_class, sp.stu_key, sp.quiz_status,
                    q.quiz_title, q.quiz_option, q.quiz_key, q.quiz_pic_name, q.quiz_diff
             FROM `stupaper` sp
             INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ?
             ORDER BY sp.paper_id ASC',
            [$examId, $stuId]
        );

        foreach ($rows as &$r) {
            $type = (string) $r['quiz_class'];
            $r['quiz_type_label'] = Quiz::TYPE_LABELS[$type] ?? '未知';
            $r['quiz_diff_label'] = Quiz::DIFF_LABELS[$r['quiz_diff']] ?? '';
            $r['quiz_option_list'] = Quiz::parseOptions((string) ($r['quiz_option'] ?? ''));
            $r['is_correct'] = $type === 'longtext'
                ? null
                : Quiz::isCorrect($type, (string) ($r['quiz_key'] ?? ''), (string) ($r['stu_key'] ?? ''));
        }
        unset($r);

        return $rows;
    }

    /**
     * 组卷结构（答题卡导航）：按题型分组，含每题作答状态。
     * 不下发正确答案，可安全用于考试进行中。
     */
    public static function navigation(int $examId, string $stuId): array
    {
        $rows = Database::fetchAll(
            'SELECT paper_id, quiz_class, quiz_status
             FROM `stupaper`
             WHERE exam_id = ? AND stu_id = ?
             ORDER BY paper_id ASC',
            [$examId, $stuId]
        );

        $groups = [];
        $done = 0;
        foreach ($rows as $r) {
            $type = (string) $r['quiz_class'];
            $answered = (int) $r['quiz_status'] !== 0;
            if ($answered) {
                $done++;
            }
            $groups[$type][] = [
                'paper_id' => (int) $r['paper_id'],
                'answered' => $answered,
            ];
        }

        return [
            'total'  => count($rows),
            'done'   => $done,
            'groups' => array_map(
                static fn (string $type, array $items): array => [
                    'type'       => $type,
                    'type_label' => Quiz::TYPE_LABELS[$type] ?? '未知',
                    'items'      => $items,
                ],
                array_keys($groups),
                array_values($groups)
            ),
        ];
    }

    /** 保存单题作答 */
    public static function saveAnswer(int $examId, string $stuId, int $paperId, string $answer): bool
    {
        return Database::query(
            'UPDATE `stupaper` SET stu_key = ?, quiz_status = -1
             WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
            [$answer, $examId, $stuId, $paperId]
        )->rowCount() > 0;
    }

    /**
     * 归一化前端提交的答案：
     * 多选题前端可能传数组，需合并为有序字符串；判断题 Y/N 兼容为 A/B。
     */
    public static function normalizeSubmission(string $type, mixed $raw): string
    {
        if (is_array($raw)) {
            $raw = implode('', array_map('strval', $raw));
        }
        $answer = Quiz::normalizeAnswer($type, (string) $raw);
        // 判断题：前端可能提交 Y/N（对/错）
        if ($type === 'radio1') {
            $answer = str_replace(['Y', 'N'], ['A', 'B'], $answer);
        }
        return $answer;
    }
}
