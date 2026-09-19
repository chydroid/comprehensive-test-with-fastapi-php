<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use Core\Database;

/**
 * 补考 / 重考（C2）
 *
 * 语义
 * ----
 * 「补考」**不是**给原考试加一个状态，而是**另开一场正式考试**，用 retake_of 指向源场次，
 * 用 exam_retake_stu 限定参考名单。这样做的收益：
 *
 *   - 原场次的成绩、名单、监考记录一概不动，原始考核记录可追溯、可审计；
 *   - 补考直接复用「开放入场 → 出题 → 开考 → 判分 → 结束 → 发证」整条既有生命周期，
 *     不需要为它另写一套状态机；
 *   - 补考成绩天然出现在成绩单、成绩分析、监考模块里，无需各处特判。
 *
 * 代价是多了一张名单表与两处名单分支（Exam::studentIdsForExam /
 * Exam::isStudentEligible）。集中在这两个函数里，而不是散落在各控制器。
 *
 * 名单口径
 * --------
 * 「未通过」= 已交卷但得分低于及格分；「缺考」= 无成绩行或未交卷。
 * 及格线取**得分率百分比**（Exam::passPercent()，后台可配、请求可覆盖），
 * 因为各场满分差异很大（50 分卷与 200 分卷的「60 分」含义完全不同）。
 */
class ExamRetake
{
    /** 候选状态 */
    public const STATE_FAILED = 'failed';   // 已交卷但未达及格线
    public const STATE_ABSENT = 'absent';   // 缺考 / 未交卷
    public const STATE_PASSED = 'passed';   // 已通过

    /**
     * 补考候选名单（源场次的全部参考考生 + 各自状态）。
     *
     * @return array{exam:array,pass_percent:int,pass_score:int,counts:array,list:array}
     */
    public static function candidates(int $examId, ?int $passPercent = null): array
    {
        $exam = Database::fetch(
            'SELECT e.*, s.subj_name FROM `examinfo` e
             LEFT JOIN `subject` s ON s.id = e.subj_id
             WHERE e.id = ?',
            [$examId]
        );
        if ($exam === null) {
            return [
                'exam' => [], 'pass_percent' => 0, 'pass_score' => 0,
                'counts' => ['failed' => 0, 'absent' => 0, 'passed' => 0, 'total' => 0],
                'list' => [],
            ];
        }

        $percent = self::clampPercent($passPercent ?? Exam::passPercent());
        $passScore = Exam::passScoreOf($exam, $percent);

        $stuIds = Exam::studentIdsForExam($exam);
        $scores = [];
        if ($stuIds !== []) {
            $rows = Database::fetchAll(
                'SELECT stu_id, stu_score, stu_status FROM `stuscore` WHERE exam_id = ?',
                [$examId]
            );
            foreach ($rows as $r) {
                $scores[(string) $r['stu_id']] = $r;
            }
        }
        $names = self::namesOf($stuIds);

        $list = [];
        $counts = ['failed' => 0, 'absent' => 0, 'passed' => 0, 'total' => 0];
        foreach ($stuIds as $stuId) {
            $sc = $scores[$stuId] ?? null;
            $status = (string) ($sc['stu_status'] ?? '');
            $score = (int) ($sc['stu_score'] ?? 0);
            if (!str_starts_with($status, 'over')) {
                $state = self::STATE_ABSENT;
            } elseif (Exam::isPassed($exam, $score, $percent)) {
                $state = self::STATE_PASSED;
            } else {
                $state = self::STATE_FAILED;
            }
            $counts[$state]++;
            $counts['total']++;
            $list[] = [
                'stu_id'    => $stuId,
                'stu_name'  => $names[$stuId]['stu_name'] ?? $stuId,
                'class_id'  => $names[$stuId]['class_id'] ?? '',
                'stu_status' => $status,
                'stu_score' => $score,
                'state'     => $state,
            ];
        }
        // 未通过 / 缺考排前面，已通过排最后 —— 弹窗默认勾选的就是前面这些
        usort($list, static function (array $a, array $b): int {
            $w = [self::STATE_FAILED => 0, self::STATE_ABSENT => 1, self::STATE_PASSED => 2];
            return [$w[$a['state']] ?? 9, (int) $a['stu_id']] <=> [$w[$b['state']] ?? 9, (int) $b['stu_id']];
        });

        return [
            'exam' => [
                'id'         => (int) $exam['id'],
                'exam_name'  => (string) ($exam['exam_name'] ?? ''),
                'subj_name'  => (string) ($exam['subj_name'] ?? ''),
                'exam_score' => (int) ($exam['exam_score'] ?? 0),
                'exam_status' => (string) ($exam['exam_status'] ?? ''),
                'stu_class'  => (string) ($exam['stu_class'] ?? ''),
                'retake_of'  => $exam['retake_of'] !== null ? (int) $exam['retake_of'] : null,
            ],
            'pass_percent' => $percent,
            'pass_score'   => $passScore,
            'counts'       => $counts,
            'list'         => $list,
        ];
    }

    /**
     * 生成补考场次。
     *
     * @param array $stuIds 允许参加补考的准考证号（前端勾选结果）
     * @param array $opts   exam_start / exam_end（必填）、exam_tea、pass_percent、
     *                      allow_passed（是否允许把已通过者一并纳入，默认 false）
     * @return array{exam_id:int,exam_name:string,roster:int,pass_score:int}
     */
    public static function create(int $srcExamId, array $stuIds, array $opts): array
    {
        $src = (new Exam())->find($srcExamId);
        if ($src === null) {
            throw new \Core\HttpException(404, '源考试不存在', 40400);
        }
        // 只有已结束的场次才能开补考：进行中的考试还没判分，「谁没通过」无从谈起
        if (!str_starts_with((string) ($src['exam_status'] ?? ''), 'over')) {
            throw new \Core\HttpException(400, '只有已结束的考试才能生成补考', 40000);
        }

        $examStart = trim((string) ($opts['exam_start'] ?? ''));
        $examEnd = trim((string) ($opts['exam_end'] ?? ''));
        if ($examStart === '' || $examEnd === '') {
            throw new \Core\HttpException(400, '请填写补考的开始时间与结束时间', 40000);
        }
        $startTs = strtotime($examStart);
        $endTs = strtotime($examEnd);
        if ($startTs === false || $endTs === false) {
            throw new \Core\HttpException(400, '补考时间格式不正确', 40000);
        }
        if ($endTs <= $startTs) {
            throw new \Core\HttpException(400, '补考结束时间必须晚于开始时间', 40000);
        }

        $percent = self::clampPercent(isset($opts['pass_percent']) ? (int) $opts['pass_percent'] : null);
        $allowPassed = !empty($opts['allow_passed']);

        // 名单必须落在源场次的参考范围内，防止把无关考生塞进补考
        $roster = Exam::studentIdsForExam($src);
        if ($roster === []) {
            throw new \Core\HttpException(400, '源考试没有可参考的考生名单（未配置参考班级或名单为空）', 40000);
        }
        $rosterSet = array_flip($roster);
        $picked = [];
        foreach ($stuIds as $id) {
            $id = trim((string) $id);
            if ($id === '' || isset($picked[$id])) {
                continue;
            }
            if (!isset($rosterSet[$id])) {
                throw new \Core\HttpException(400, "考生 {$id} 不属于该场考试，无法加入补考", 40000);
            }
            $picked[$id] = true;
        }
        if ($picked === []) {
            throw new \Core\HttpException(400, '请至少选择一名参加补考的考生', 40000);
        }

        // 已通过者默认不得进入补考（补考是给未通过/缺考者的第二次机会）
        $passScore = Exam::passScoreOf($src, $percent);
        $scoreMap = [];
        $rows = Database::fetchAll(
            'SELECT stu_id, stu_score, stu_status FROM `stuscore` WHERE exam_id = ?',
            [$srcExamId]
        );
        foreach ($rows as $r) {
            $scoreMap[(string) $r['stu_id']] = $r;
        }
        $passed = [];
        foreach (array_keys($picked) as $id) {
            $sc = $scoreMap[$id] ?? null;
            if ($sc !== null && str_starts_with((string) $sc['stu_status'], 'over')
                && Exam::isPassed($src, (int) $sc['stu_score'], $percent)) {
                $passed[] = $id;
            }
        }
        if ($passed !== [] && !$allowPassed) {
            throw new \Core\HttpException(
                400,
                '所选名单中有 ' . count($passed) . ' 名已通过的考生；如需一并纳入补考，请勾选「包含已通过考生」',
                40000
            );
        }

        $srcName = (string) ($src['exam_name'] ?? ('考试 #' . $srcExamId));
        $newName = $srcName . '（补考）';

        $insert = [
            'exam_name'        => $newName,
            'exam_class'       => (string) ($src['exam_class'] ?? ''),
            'exam_category_id' => (int) ($src['exam_category_id'] ?? 0),
            'subj_id'          => (int) ($src['subj_id'] ?? 0),
            'exam_start'       => date('Y-m-d H:i:s', $startTs),
            'exam_end'         => date('Y-m-d H:i:s', $endTs),
            'exam_tea'         => trim((string) ($opts['exam_tea'] ?? '')) !== ''
                ? trim((string) $opts['exam_tea'])
                : (string) ($src['exam_tea'] ?? ''),
            'stu_class'        => (string) ($src['stu_class'] ?? ''),
            'paper_mode'       => (string) ($src['paper_mode'] ?? 'random'),
            'exam_status'      => Exam::STATUS_EXAM,
            'exam_pwd'         => 0,
            'exam_score'       => (int) ($src['exam_score'] ?? 0),
            'score_visibility' => Exam::visibilityOf($src),
            'cert_threshold'   => (int) ($src['cert_threshold'] ?? 0),
            'retake_of'        => $srcExamId,
        ];
        foreach (Exam::TYPE_PREFIXES as $t) {
            foreach (['easy_sum', 'mid_sum', 'hard_sum'] as $seg) {
                $insert["{$t}_{$seg}"] = (int) ($src["{$t}_{$seg}"] ?? 0);
            }
            $insert["{$t}_val"] = (int) ($src["{$t}_val"] ?? 0);
        }

        $ownTx = !Database::inTransaction();
        if ($ownTx) {
            Database::beginTransaction();
        }
        try {
            $newId = (new Exam())->create($insert);

            // 组卷明细随模式复制（random 无明细）
            $mode = (string) ($insert['paper_mode'] ?? 'random');
            if ($mode === 'manual') {
                $manual = Database::fetchAll(
                    'SELECT quiz_id, sort FROM `exam_manual_quiz` WHERE exam_id = ? ORDER BY sort, quiz_id',
                    [$srcExamId]
                );
                foreach ($manual as $m) {
                    Database::query(
                        'INSERT INTO `exam_manual_quiz` (exam_id, quiz_id, sort) VALUES (?, ?, ?)',
                        [$newId, (int) $m['quiz_id'], (int) $m['sort']]
                    );
                }
            } elseif ($mode === 'by_kp') {
                $plan = Database::fetchAll(
                    'SELECT kp, diff, cnt, sort FROM `exam_kp_plan` WHERE exam_id = ? ORDER BY sort, kp, diff',
                    [$srcExamId]
                );
                foreach ($plan as $p) {
                    Database::query(
                        'INSERT INTO `exam_kp_plan` (exam_id, kp, diff, cnt, sort) VALUES (?, ?, ?, ?, ?)',
                        [$newId, (string) $p['kp'], (string) $p['diff'], (int) $p['cnt'], (int) $p['sort']]
                    );
                }
            }

            foreach (array_keys($picked) as $id) {
                Database::query(
                    'INSERT INTO `exam_retake_stu` (exam_id, stu_id) VALUES (?, ?)',
                    [$newId, $id]
                );
            }

            if ($ownTx) {
                Database::commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'exam_id'    => $newId,
            'exam_name'  => $newName,
            'roster'     => count($picked),
            'pass_score' => $passScore,
        ];
    }

    /** 本场考试「由哪一场补考而来」（非补考返回 null） */
    public static function sourceOf(int $examId): ?int
    {
        $row = Database::fetch('SELECT retake_of FROM `examinfo` WHERE id = ?', [$examId]);
        $of = $row['retake_of'] ?? null;
        return $of !== null && (int) $of > 0 ? (int) $of : null;
    }

    /** 及格线百分比归一到 1–100（0 会让「谁都及格」，等于关掉补考筛选） */
    private static function clampPercent(?int $percent): int
    {
        if ($percent === null) {
            return Exam::passPercent();
        }
        return max(1, min(100, $percent));
    }

    /** 批量取名（一次查完，避免逐个考生查库） */
    private static function namesOf(array $stuIds): array
    {
        if ($stuIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($stuIds), '?'));
        $rows = Database::fetchAll(
            "SELECT id, stu_name, class_id FROM `stuinfo` WHERE id IN ({$ph})",
            $stuIds
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['id']] = [
                'stu_name' => (string) ($r['stu_name'] ?? ''),
                'class_id' => (string) ($r['class_id'] ?? ''),
            ];
        }
        return $out;
    }
}
