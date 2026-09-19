<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use App\Models\Quiz;
use Core\Database;

/**
 * 成绩与学情分析（A2）。
 *
 * 只读服务：从 `stuscore`（考生总分/状态）与 `stupaper`（逐题作答）出发，
 * 结合 `quizlib`（题型/难度/知识点）与 `stuinfo`（姓名/班级）汇出多维度分析：
 *
 *  - summary     ：参考人数、均分、最高/最低、中位数、及格率、优秀率、满分。
 *  - distribution：按卷面得分率的分数段分布（0-59 / 60-69 / 70-79 / 80-89 / 90-100）。
 *  - byType      ：按题型（判断/单选/多选/填空）的正确率。
 *  - byDiff      ：按难度（易/中/难）的正确率。
 *  - bySubject   ：按科目的正确率（跨科目合卷时才有区分度）。
 *  - byKp        ：按知识点（quiz_kp）的正确率，用于定位「哪个章节薄弱」。
 *  - weakItems   ：正确率最低的题目 TOP N（附题干），供讲评与错题重练选题。
 *  - classes     ：按班级分组的均分与及格率，用于班级横向对比。
 *
 * 设计原则：
 *  1. **只统计已交卷（stu_status 以 over 开头）的考生**，未交卷不计入分母，
 *     避免拉低均分造成误判。
 *  2. **问答题（longtext）不参与正确率统计**（无自动判分），仅统计作答数。
 *  3. 正确性一律以 `Quiz::isCorrect()` 复算（与判分同一出处），不信任历史列，
 *     多选/填空的去重与顺序兼容都走同一套归一化。
 *  4. 空数据不抛异常，返回结构完整的零值，前端可直接渲染。
 */
final class ScoreAnalysis
{
    /** 分数段（按得分率百分比） */
    private const BANDS = [
        ['key' => '0-59',  'label' => '不及格', 'min' => 0,  'max' => 59],
        ['key' => '60-69', 'label' => '及格',   'min' => 60, 'max' => 69],
        ['key' => '70-79', 'label' => '中等',   'min' => 70, 'max' => 79],
        ['key' => '80-89', 'label' => '良好',   'min' => 80, 'max' => 89],
        ['key' => '90-100', 'label' => '优秀',  'min' => 90, 'max' => 100],
    ];

    /**
     * @param int   $examId       考试 ID
     * @param int   $passLine     及格线（得分率百分比，默认 60）
     * @param int   $excellentLine 优秀线（得分率百分比，默认 85）
     * @param int   $weakLimit    薄弱题返回数量
     */
    public static function analyze(int $examId, int $passLine = 60, int $excellentLine = 85, int $weakLimit = 10): array
    {
        $passLine = max(0, min(100, $passLine));
        $excellentLine = max($passLine, min(100, $excellentLine));

        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            return self::emptyResult($passLine, $excellentLine, 0);
        }
        $fullScore = (int) ($exam['exam_score'] ?? 0);

        // ---- 1. 取已交卷考生成绩 ----
        $scoreRows = Database::fetchAll(
            'SELECT ss.stu_id, ss.stu_score, ss.stu_status,
                    si.stu_name, si.class_id, si.grade_id
             FROM `stuscore` ss
             LEFT JOIN `stuinfo` si ON si.id = ss.stu_id
             WHERE ss.exam_id = ?',
            [$examId]
        );

        $graded = [];
        foreach ($scoreRows as $r) {
            if (!str_starts_with((string) ($r['stu_status'] ?? ''), 'over')) {
                continue;
            }
            $graded[(string) $r['stu_id']] = $r;
        }

        $count = count($graded);
        $scores = array_map(static fn (array $r): int => (int) ($r['stu_score'] ?? 0), array_values($graded));

        // 满分兜底：exam_score 为 0 时，用实际最高分推断，避免得分率除零
        $denominator = $fullScore > 0 ? $fullScore : (($scores !== []) ? max($scores) : 0);

        // ---- 2. 逐题作答（仅已交卷考生）----
        $answerRows = $count === 0 ? [] : Database::fetchAll(
            'SELECT sp.stu_id, sp.quiz_id, sp.quiz_class, sp.stu_key,
                    q.quiz_key, q.quiz_diff, q.quiz_kp, q.subj_id, q.quiz_title
             FROM `stupaper` sp
             INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ?',
            [$examId]
        );

        $typeAgg  = [];  // quiz_class => ['total'=>, 'correct'=>]
        $typePending = []; // longtext 等无自动判分题型的作答数
        $diffAgg  = [];  // quiz_diff  => ['total'=>, 'correct'=>]
        $subjAgg  = [];  // subj_id    => ['total'=>, 'correct'=>]
        $kpAgg    = [];  // quiz_kp    => ['total'=>, 'correct'=>]
        $quizAgg  = [];  // quiz_id    => ['total'=>, 'correct'=>, 'title'=>, 'class'=>]

        foreach ($answerRows as $r) {
            $stuId = (string) $r['stu_id'];
            if (!isset($graded[$stuId])) {
                continue; // 未交卷考生的作答不计入
            }
            $type = (string) $r['quiz_class'];

            if ($type === 'longtext') {
                // 问答题：无自动判分，单独记作答数，不进正确率分母
                $typePending[$type] = ($typePending[$type] ?? 0) + 1;
                continue;
            }

            $userKey    = (string) ($r['stu_key'] ?? '');
            $correctKey = (string) ($r['quiz_key'] ?? '');
            // 未作答按答错计入分母（更能反映掌握度）；作答为空与答案为空都视为错
            $isRight = ($userKey !== '' && $correctKey !== '')
                && Quiz::isCorrect($type, $correctKey, $userKey);

            // 题型维度
            $typeAgg[$type]['total'] = ($typeAgg[$type]['total'] ?? 0) + 1;
            if ($isRight) {
                $typeAgg[$type]['correct'] = ($typeAgg[$type]['correct'] ?? 0) + 1;
            }

            // 难度维度（quiz_diff 为空则跳过，避免出现空 key 行）
            $diff = (string) ($r['quiz_diff'] ?? '');
            if ($diff !== '') {
                $diffAgg[$diff]['total'] = ($diffAgg[$diff]['total'] ?? 0) + 1;
                if ($isRight) {
                    $diffAgg[$diff]['correct'] = ($diffAgg[$diff]['correct'] ?? 0) + 1;
                }
            }

            // 科目维度
            $subj = (string) ($r['subj_id'] ?? '');
            if ($subj !== '' && $subj !== '0') {
                $subjAgg[$subj]['total'] = ($subjAgg[$subj]['total'] ?? 0) + 1;
                if ($isRight) {
                    $subjAgg[$subj]['correct'] = ($subjAgg[$subj]['correct'] ?? 0) + 1;
                }
            }

            // 知识点维度
            $kp = (string) ($r['quiz_kp'] ?? '');
            if ($kp !== '') {
                $kpAgg[$kp]['total'] = ($kpAgg[$kp]['total'] ?? 0) + 1;
                if ($isRight) {
                    $kpAgg[$kp]['correct'] = ($kpAgg[$kp]['correct'] ?? 0) + 1;
                }
            }

            $qid = (int) $r['quiz_id'];
            $quizAgg[$qid]['total']   = ($quizAgg[$qid]['total'] ?? 0) + 1;
            $quizAgg[$qid]['title']   = (string) ($r['quiz_title'] ?? '');
            $quizAgg[$qid]['class']   = $type;
            if ($isRight) {
                $quizAgg[$qid]['correct'] = ($quizAgg[$qid]['correct'] ?? 0) + 1;
            }
        }

        // ---- 3. 汇总 ----
        $summary = self::summarize($scores, $denominator, $passLine, $excellentLine);

        // ---- 4. 分数段分布 ----
        $distribution = self::distribute($scores, $denominator);

        // ---- 5. 各维度正确率 ----
        $byType    = self::rateList($typeAgg, static fn (string $k): string => Quiz::TYPE_LABELS[$k] ?? $k, 'type');
        $byDiff    = self::rateList($diffAgg, static fn (string $k): string => Quiz::DIFF_LABELS[$k] ?? $k, 'diff');
        $bySubject = self::rateList($subjAgg, static fn (string $k): string => self::subjectName((int) $k), 'subj_id');
        $byKp      = self::rateList($kpAgg, static fn (string $k): string => $k, 'kp');

        // 主观题（问答）另行列出，提示需人工批阅，不混入正确率
        $pendingTypes = [];
        foreach ($typePending as $t => $n) {
            $pendingTypes[] = [
                'type'        => (string) $t,
                'label'       => Quiz::TYPE_LABELS[$t] ?? (string) $t,
                'pending'     => (int) $n,
            ];
        }

        // ---- 6. 薄弱题 TOP N ----
        $weak = [];
        foreach ($quizAgg as $qid => $a) {
            $total = (int) ($a['total'] ?? 0);
            if ($total <= 0) {
                continue;
            }
            $correct = (int) ($a['correct'] ?? 0);
            $weak[] = [
                'quiz_id'      => (int) $qid,
                'quiz_title'   => (string) ($a['title'] ?? ''),
                'quiz_class'   => (string) ($a['class'] ?? ''),
                'type_label'   => Quiz::TYPE_LABELS[$a['class'] ?? ''] ?? '',
                'total'        => $total,
                'correct'      => $correct,
                'correct_rate' => (int) round($correct / $total * 100),
            ];
        }
        usort($weak, static fn (array $x, array $y): int => $x['correct_rate'] <=> $y['correct_rate'] ?: $y['total'] <=> $x['total']);
        $weakItems = array_slice($weak, 0, max(1, $weakLimit));

        // ---- 7. 班级横向对比 ----
        $classes = self::byClass(array_values($graded), $denominator, $passLine);

        return [
            'exam' => [
                'id'         => $examId,
                'name'       => (string) ($exam['exam_name'] ?? ''),
                'subj_id'    => (int) ($exam['subj_id'] ?? 0),
                'full_score' => $fullScore,
                'exam_class' => (string) ($exam['exam_class'] ?? ''),
            ],
            'summary'      => $summary,
            'distribution' => $distribution,
            'by_type'      => $byType,
            'by_diff'      => $byDiff,
            'by_subject'   => $bySubject,
            'by_kp'        => $byKp,
            'pending_types' => $pendingTypes,
            'weak_items'   => $weakItems,
            'classes'      => $classes,
        ];
    }

    /* ------------------------------------------------------------------ */

    /** 均分/中位数/及格率等聚合 */
    private static function summarize(array $scores, int $denominator, int $passLine, int $excellentLine): array
    {
        $count = count($scores);
        if ($count === 0) {
            return [
                'count' => 0, 'avg' => 0.0, 'max' => 0, 'min' => 0, 'median' => 0.0,
                'pass_count' => 0, 'pass_rate' => 0,
                'excellent_count' => 0, 'excellent_rate' => 0,
                'pass_line' => $passLine, 'excellent_line' => $excellentLine,
                'pass_score' => self::lineScore($denominator, $passLine),
                'excellent_score' => self::lineScore($denominator, $excellentLine),
                'std_dev' => 0.0,
            ];
        }

        sort($scores);
        $sum = array_sum($scores);
        $avg = $sum / $count;
        $mid = intdiv($count, 2);
        $median = $count % 2 === 1
            ? (float) $scores[$mid]
            : (($scores[$mid - 1] + $scores[$mid]) / 2);

        // 标准差：反映分数离散度（越大说明两极分化越明显）
        $variance = 0.0;
        foreach ($scores as $s) {
            $variance += ($s - $avg) ** 2;
        }
        $stdDev = sqrt($variance / $count);

        $passScore = self::lineScore($denominator, $passLine);
        $excScore  = self::lineScore($denominator, $excellentLine);

        $passCount = 0;
        $excCount  = 0;
        foreach ($scores as $s) {
            if ($denominator > 0) {
                $pct = $s / $denominator * 100;
                if ($pct >= $passLine) {
                    $passCount++;
                }
                if ($pct >= $excellentLine) {
                    $excCount++;
                }
            } elseif ($s > 0) {
                $passCount++;
                $excCount++;
            }
        }

        return [
            'count'           => $count,
            'avg'             => round($avg, 1),
            'max'             => max($scores),
            'min'             => min($scores),
            'median'          => round($median, 1),
            'std_dev'         => round($stdDev, 1),
            'pass_count'      => $passCount,
            'pass_rate'       => (int) round($passCount / $count * 100),
            'excellent_count' => $excCount,
            'excellent_rate'  => (int) round($excCount / $count * 100),
            'pass_line'       => $passLine,
            'excellent_line'  => $excellentLine,
            'pass_score'      => $passScore,
            'excellent_score' => $excScore,
        ];
    }

    /** 按得分率分段的分数段分布 */
    private static function distribute(array $scores, int $denominator): array
    {
        $buckets = [];
        foreach (self::BANDS as $b) {
            $buckets[$b['key']] = ['key' => $b['key'], 'label' => $b['label'], 'count' => 0];
        }
        foreach ($scores as $s) {
            $pct = $denominator > 0 ? (int) floor($s / $denominator * 100) : ($s > 0 ? 100 : 0);
            $pct = max(0, min(100, $pct));
            foreach (self::BANDS as $b) {
                if ($pct >= $b['min'] && $pct <= $b['max']) {
                    $buckets[$b['key']]['count']++;
                    break;
                }
            }
        }
        $total = count($scores);
        $out = [];
        foreach ($buckets as $b) {
            $b['rate'] = $total > 0 ? (int) round($b['count'] / $total * 100) : 0;
            $out[] = $b;
        }
        return $out;
    }

    /** 把聚合数组转成带 label / correct_rate 的列表 */
    private static function rateList(array $agg, callable $labeler, string $keyName): array
    {
        $out = [];
        foreach ($agg as $key => $a) {
            $total   = (int) ($a['total'] ?? 0);
            $correct = (int) ($a['correct'] ?? 0);
            if ($total <= 0) {
                continue;
            }
            $out[] = [
                $keyName         => (string) $key,
                'label'          => (string) $labeler((string) $key),
                'total'          => $total,
                'correct'        => $correct,
                'correct_rate'   => (int) round($correct / $total * 100),
                'pending'        => (int) ($a['pending'] ?? 0),
            ];
        }
        return $out;
    }

    /** 按班级聚合均分与及格率 */
    private static function byClass(array $graded, int $denominator, int $passLine): array
    {
        $agg = [];
        foreach ($graded as $r) {
            $cid = (string) ($r['class_id'] ?? '');
            if ($cid === '') {
                $cid = '未分班';
            }
            $agg[$cid]['scores'][] = (int) ($r['stu_score'] ?? 0);
            $agg[$cid]['count'] = ($agg[$cid]['count'] ?? 0) + 1;
        }
        if ($agg === []) {
            return [];
        }

        $names = self::classNames(array_keys($agg));
        $out = [];
        foreach ($agg as $cid => $a) {
            $scores = $a['scores'];
            sort($scores);
            $pass = 0;
            foreach ($scores as $s) {
                if ($denominator > 0 && $s / $denominator * 100 >= $passLine) {
                    $pass++;
                }
            }
            $out[] = [
                'class_id'   => (string) $cid,
                'class_name' => $names[$cid] ?? (string) $cid,
                'count'      => (int) $a['count'],
                'avg'        => round(array_sum($scores) / count($scores), 1),
                'max'        => max($scores),
                'min'        => min($scores),
                'pass_rate'  => (int) round($pass / count($scores) * 100),
            ];
        }
        usort($out, static fn (array $x, array $y): int => $y['avg'] <=> $x['avg']);
        return $out;
    }

    private static function classNames(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (string $v): bool => $v !== '' && $v !== '未分班'));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll("SELECT id, class_name FROM `classinfo` WHERE id IN ({$ph})", $ids);
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['id']] = (string) $r['class_name'];
        }
        return $map;
    }

    private static function subjectName(int $id): string
    {
        if ($id <= 0) {
            return '未指定';
        }
        $row = Database::fetch('SELECT subj_name FROM `subject` WHERE id = ?', [$id]);
        return (string) ($row['subj_name'] ?? ('科目' . $id));
    }

    private static function lineScore(int $denominator, int $pct): int
    {
        return $denominator > 0 ? (int) ceil($denominator * $pct / 100) : 0;
    }

    private static function emptyResult(int $passLine, int $excellentLine, int $fullScore): array
    {
        return [
            'exam'         => ['id' => 0, 'name' => '', 'subj_id' => 0, 'full_score' => $fullScore, 'exam_class' => ''],
            'summary'      => self::summarize([], $fullScore, $passLine, $excellentLine),
            'distribution' => self::distribute([], $fullScore),
            'by_type'      => [], 'by_diff' => [], 'by_subject' => [], 'by_kp' => [],
            'pending_types' => [], 'weak_items' => [], 'classes' => [],
        ];
    }
}
