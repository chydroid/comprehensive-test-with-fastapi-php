<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * C5 考后问卷。
 *
 * 定位：考后收集「这场考试难不难、时间够不够、有没有问题」的反馈，
 * 不做通用问卷系统。因此刻意限制：
 *
 *  - **题目归属考试**（exam_survey.exam_id），不是全局题库；一场考试一套问卷。
 *  - **只有三种题型**：评分（1–5 星）、单选、文本。够用且统计口径清晰 ——
 *    评分给均值、单选给分布、文本给列表，教师一眼能看明白。
 *  - **一人一题一答**（UNIQUE(qid, stu_id)），重复提交是更新而不是追加，
 *    否则同一个考生反复提交就能把统计刷高。
 *  - **不强制作答**：只有标记 required 的题才校验，其余留空允许提交。
 *    考后反馈是自愿的，拦着不让交会让回收率直接归零。
 */
final class Survey
{
    public const TYPE_RATING = 'rating';
    public const TYPE_CHOICE = 'choice';
    public const TYPE_TEXT   = 'text';

    public const TYPES = [self::TYPE_RATING, self::TYPE_CHOICE, self::TYPE_TEXT];

    public const TYPE_LABELS = [
        self::TYPE_RATING => '评分题',
        self::TYPE_CHOICE => '单选题',
        self::TYPE_TEXT   => '文本题',
    ];

    /** 评分题取值范围 */
    public const RATING_MIN = 1;
    public const RATING_MAX = 5;

    /** 单场考试题目上限（防止表单被塞爆） */
    public const MAX_QUESTIONS = 20;

    /* ==================================================================
     * 配置（教师 / 管理端）
     * ================================================================== */

    /** @return array<int,array<string,mixed>> */
    public static function questions(int $examId): array
    {
        $rows = Database::fetchAll(
            'SELECT id, exam_id, sort_no, title, quiz_type, options, required
             FROM `exam_survey` WHERE exam_id = ? ORDER BY sort_no ASC, id ASC',
            [$examId]
        );
        return array_map(static function (array $r): array {
            return [
                'id'       => (int) $r['id'],
                'sort_no'  => (int) $r['sort_no'],
                'title'    => (string) $r['title'],
                'type'     => (string) $r['quiz_type'],
                'options'  => self::parseOptions((string) $r['options']),
                'required' => (int) $r['required'] === 1,
            ];
        }, $rows);
    }

    /**
     * 整体替换某场考试的问卷题目。
     *
     * 用「全删重建」而不是逐条 diff：题目数量上限只有 20，diff 带来的复杂度
     * （判断哪些要删、哪些要改序）远大于它的收益。整替换也让 sort_no 永远连续。
     *
     * @param array<int,array{title?:string,type?:string,options?:array|string,required?:bool}> $items
     * @return array{questions:array,count:int}
     */
    public static function save(int $examId, array $items): array
    {
        if (count($items) > self::MAX_QUESTIONS) {
            throw new \Core\HttpException(400, '单场问卷最多 ' . self::MAX_QUESTIONS . ' 道题', 40000);
        }

        $clean = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $title = trim((string) ($it['title'] ?? ''));
            if ($title === '') {
                continue;                     // 空题干直接丢弃，不占题号
            }
            $type = (string) ($it['type'] ?? self::TYPE_RATING);
            if (!in_array($type, self::TYPES, true)) {
                throw new \Core\HttpException(400, '问卷题型无效：' . $type, 40000);
            }
            $options = $it['options'] ?? [];
            if (is_string($options)) {
                $options = self::parseOptions($options);
            }
            $options = is_array($options) ? array_values(array_filter(array_map(
                static fn ($o): string => trim((string) $o),
                $options
            ), static fn (string $o): bool => $o !== '')) : [];

            // 单选题没有选项就没有意义：宁可退回评分题，也不生成一个空下拉框
            if ($type === self::TYPE_CHOICE && $options === []) {
                $type = self::TYPE_RATING;
            }

            $clean[] = [
                'title'    => mb_substr($title, 0, 200),
                'type'     => $type,
                'options'  => array_slice($options, 0, 20),
                'required' => !empty($it['required']),
            ];
        }

        Database::beginTransaction();
        try {
            // 先清答题再清题目：外键虽未建，但顺序反了会留下指向已删题目的孤儿作答
            Database::query('DELETE FROM `exam_survey_answer` WHERE exam_id = ?', [$examId]);
            Database::query('DELETE FROM `exam_survey` WHERE exam_id = ?', [$examId]);

            foreach ($clean as $i => $q) {
                Database::query(
                    'INSERT INTO `exam_survey` (exam_id, sort_no, title, quiz_type, options, required)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $examId,
                        $i + 1,
                        $q['title'],
                        $q['type'],
                        implode('|', $q['options']),
                        $q['required'] ? 1 : 0,
                    ]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return ['count' => count($clean), 'questions' => self::questions($examId)];
    }

    /* ==================================================================
     * 考生端
     * ================================================================== */

    /**
     * 考生看到的问卷：题目 + 本人已作答内容。
     * @return array{has_survey:bool,filled:bool,questions:array,answers:array<string,string>}
     */
    public static function forStudent(int $examId, string $stuId): array
    {
        $questions = self::questions($examId);
        if ($questions === []) {
            return ['has_survey' => false, 'filled' => false, 'questions' => [], 'answers' => []];
        }

        $rows = Database::fetchAll(
            'SELECT qid, answer FROM `exam_survey_answer` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        $answers = [];
        foreach ($rows as $r) {
            $answers[(string) (int) $r['qid']] = (string) ($r['answer'] ?? '');
        }

        return [
            'has_survey' => true,
            'filled'     => $answers !== [],
            'questions'  => $questions,
            'answers'    => $answers,
        ];
    }

    /**
     * 提交问卷。
     *
     * @param array<string|int,string> $answers 键为题号 id，值为作答
     * @return array{saved:int,filled:bool}
     */
    public static function submit(int $examId, string $stuId, array $answers): array
    {
        $questions = self::questions($examId);
        if ($questions === []) {
            throw new \Core\HttpException(404, '本场考试没有配置问卷', 40400);
        }

        $valid = [];
        foreach ($questions as $q) {
            $valid[(int) $q['id']] = $q;
        }

        // 遍历**题目**而不是遍历提交上来的答案：必答题被漏掉时它根本不出现在
        // $answers 里，只检查已提交的项会让 required 形同虚设。
        $pairs = [];
        foreach ($valid as $id => $q) {
            $value = trim((string) ($answers[$id] ?? ''));

            if ($value === '') {
                if ($q['required']) {
                    throw new \Core\HttpException(400, '「' . $q['title'] . '」为必答题', 40000);
                }
                continue;                     // 非必答留空 = 不记录
            }

            // 归一化后为空说明这个值不被接受（例如单选填了未配置的选项），
            // 此时保持原值不动，而不是写一条空答案把已有作答冲掉。
            $normalized = self::normalizeAnswer($q, $value);
            if ($normalized === '') {
                continue;
            }
            $pairs[$id] = $normalized;
        }

        Database::beginTransaction();
        try {
            $saved = 0;
            foreach ($pairs as $id => $value) {
                Database::query(
                    'INSERT INTO `exam_survey_answer` (qid, exam_id, stu_id, answer)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE answer = VALUES(answer), created_at = NOW()',
                    [$id, $examId, $stuId, $value]
                );
                $saved++;
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return ['saved' => $saved, 'filled' => true];
    }

    /* ==================================================================
     * 统计（教师 / 管理端）
     * ================================================================== */

    /**
     * @return array{questions:array,total_students:int,answered_students:int}
     */
    public static function stats(int $examId): array
    {
        $questions = self::questions($examId);

        // 分母取「本场已交卷考生」而不是「全班」：没参加考试的人不该被算进回收率
        $total = (int) (Database::fetch(
            "SELECT COUNT(*) AS c FROM `stuscore` WHERE exam_id = ? AND LEFT(stu_status, 4) = 'over'",
            [$examId]
        )['c'] ?? 0);

        $answered = (int) (Database::fetch(
            'SELECT COUNT(DISTINCT stu_id) AS c FROM `exam_survey_answer` WHERE exam_id = ?',
            [$examId]
        )['c'] ?? 0);

        $rows = Database::fetchAll(
            'SELECT qid, answer FROM `exam_survey_answer` WHERE exam_id = ? ORDER BY qid ASC, id ASC',
            [$examId]
        );
        $byQ = [];
        foreach ($rows as $r) {
            $byQ[(int) $r['qid']][] = (string) ($r['answer'] ?? '');
        }

        foreach ($questions as &$q) {
            $list = $byQ[(int) $q['id']] ?? [];
            $q['answered'] = count($list);

            if ($q['type'] === self::TYPE_RATING) {
                $sum = 0;
                $n = 0;
                $dist = [];
                foreach ($list as $v) {
                    $n1 = (int) $v;
                    if ($n1 < self::RATING_MIN || $n1 > self::RATING_MAX) {
                        continue;
                    }
                    $sum += $n1;
                    $n++;
                    $dist[$n1] = ($dist[$n1] ?? 0) + 1;
                }
                $q['avg'] = $n > 0 ? round($sum / $n, 2) : null;
                $q['distribution'] = $dist;
                $q['texts'] = [];
            } elseif ($q['type'] === self::TYPE_CHOICE) {
                $dist = [];
                foreach ($list as $v) {
                    $dist[$v] = ($dist[$v] ?? 0) + 1;
                }
                $q['distribution'] = $dist;
                $q['avg'] = null;
                $q['texts'] = [];
            } else {
                // 文本：逐条列出（不聚合）。文本反馈的价值正在于「具体说了什么」
                $q['texts'] = array_slice($list, 0, 200);
                $q['distribution'] = [];
                $q['avg'] = null;
            }
        }
        unset($q);

        return [
            'questions'         => $questions,
            'total_students'    => $total,
            'answered_students' => $answered,
        ];
    }

    /* ==================================================================
     * 内部
     * ================================================================== */

    /** 按题型归一化作答：评分夹到 1–5，单选必须命中选项，文本截断 */
    private static function normalizeAnswer(array $q, string $value): string
    {
        if ($q['type'] === self::TYPE_RATING) {
            $n = (int) $value;
            if ($n < self::RATING_MIN) {
                $n = self::RATING_MIN;
            }
            if ($n > self::RATING_MAX) {
                $n = self::RATING_MAX;
            }
            return (string) $n;
        }
        if ($q['type'] === self::TYPE_CHOICE) {
            // 只接受配置过的选项之一，杜绝前端塞入任意字符串污染分布统计
            return in_array($value, $q['options'], true) ? $value : '';
        }
        return mb_substr($value, 0, 1000);
    }

    /** @return string[] */
    private static function parseOptions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode('|', $raw)), static fn (string $s): bool => $s !== ''));
    }
}
