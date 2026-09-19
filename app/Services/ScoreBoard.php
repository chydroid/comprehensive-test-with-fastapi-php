<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use Core\Database;

/**
 * 成绩榜（文档 B4 成绩公示与隐私分级）
 *
 * 三条硬规则
 * ----------
 * 1. **只统计已交卷考生**（stuscore.stu_status 以 over 开头）。未交卷者没有最终
 *    成绩，进榜会把「还在答题的人」显示成 0 分——与 ScoreAnalysis 同口径。
 *
 * 2. **调用方必须是本场考试的考生**（有 stuscore 行）。公示解决的是「同场考生
 *    之间能不能互相看到」，不是「任何人都能查任意考试的分数」。若只按
 *    public 放行登录考生，任何考生枚举 exam_id 就能刷出全校各场考试的分数 ——
 *    那不是公示，是数据泄露。管理端 / 教师端另有完整的成绩模块，需要张榜时用它。
 *
 * 3. **粒度只在一处判定**（本服务的 scope 分支），前端不得自行过滤。前端只负责
 *    渲染 rows / 或提示「本场仅本人可见」。
 *
 * 排名采用竞赛排名（并列同名次，后续跳号：1、2、2、4），与常见榜单一致。
 */
class ScoreBoard
{
    /**
     * 某考生查看某场考试的成绩榜。
     *
     * @param string $viewerStuId   查看者准考证号
     * @param string $viewerClassId 查看者班级 ID（class 粒度用）
     * @return array{
     *   allowed:bool, reason:string,
     *   exam:array, visibility:string, scope_label:string, can_view_others:bool,
     *   pass_score:int, total_score:int,
     *   me:array|null, rows:array
     * }
     */
    public static function forExam(int $examId, string $viewerStuId, string $viewerClassId): array
    {
        $viewerStuId = trim($viewerStuId);
        $viewerClassId = trim($viewerClassId);

        $empty = static fn (string $reason, array $exam = []): array => [
            'allowed'         => false,
            'reason'          => $reason,
            'exam'            => [
                'id'         => (int) ($exam['id'] ?? 0),
                'exam_name'  => (string) ($exam['exam_name'] ?? ''),
                'subj_name'  => (string) ($exam['subj_name'] ?? ''),
            ],
            'visibility'      => Exam::VIS_PRIVATE,
            'scope_label'     => Exam::SCORE_VISIBILITY_LABELS[Exam::VIS_PRIVATE],
            'can_view_others' => false,
            'pass_score'      => 0,
            'total_score'     => (int) ($exam['exam_score'] ?? 0),
            'me'              => null,
            'rows'            => [],
        ];

        if ($examId <= 0 || $viewerStuId === '') {
            return $empty('缺少考试编号或未登录');
        }

        $exam = Database::fetch(
            "SELECT e.id, e.exam_name, e.exam_score, e.score_visibility, e.cert_threshold,
                    e.exam_status, e.subj_id, s.subj_name
             FROM `examinfo` e
             LEFT JOIN `subject` s ON s.id = e.subj_id
             WHERE e.id = ?",
            [$examId]
        );
        if ($exam === null) {
            return $empty('考试不存在');
        }

        // 只有「已结束」的场次才有稳定的最终榜单；进行中的分数还在变
        if (!str_starts_with((string) ($exam['exam_status'] ?? ''), 'over')) {
            return $empty('本场考试尚未结束，暂不公示成绩', $exam);
        }

        $participants = self::participants($examId);

        // 参与校验：不在本场名单中的考生（没有成绩行）无权查看
        $mine = null;
        foreach ($participants as $p) {
            if ((string) $p['stu_id'] === $viewerStuId) {
                $mine = $p;
                break;
            }
        }
        if ($mine === null) {
            return $empty('你未参加本场考试', $exam);
        }

        $visibility = Exam::visibilityOf($exam);
        $scopeLabel = Exam::SCORE_VISIBILITY_LABELS[$visibility];

        $rows = [];
        if ($visibility !== Exam::VIS_PRIVATE) {
            foreach ($participants as $p) {
                if ($visibility === Exam::VIS_CLASS && $viewerClassId !== ''
                    && trim((string) ($p['class_id'] ?? '')) !== $viewerClassId) {
                    continue;
                }
                $rows[] = self::rowOf($p, $exam, $viewerStuId);
            }
        }
        // rows 含自己（榜单里没有「我」会很怪），故「能否看到别人」要看有没有非我的行
        $others = array_filter($rows, static fn (array $r): bool => !$r['is_me']);

        // 自己那一行永远可见（即使 private 粒度）
        return [
            'allowed'         => true,
            'reason'          => '',
            'exam'            => [
                'id'        => (int) $exam['id'],
                'exam_name' => (string) ($exam['exam_name'] ?? ''),
                'subj_name' => (string) ($exam['subj_name'] ?? ''),
            ],
            'visibility'      => $visibility,
            'scope_label'     => $scopeLabel,
            'can_view_others' => $others !== [],
            'pass_score'      => Exam::passScoreOf($exam),
            'total_score'     => (int) ($exam['exam_score'] ?? 0),
            'me'              => self::rowOf($mine, $exam, $viewerStuId),
            'rows'            => $rows,
        ];
    }

    /**
     * 本场考试的**已交卷**考生（含名次）。名次按全体已交卷考生计算，
     * 班级粒度只是在其上做展示过滤 —— 名次始终是全场名次，否则同一个分数
     * 在本班榜与全场榜会显示成两个名次，反而误导。
     *
     * @return array<int, array> 已按分数倒序并附 rank
     */
    private static function participants(int $examId): array
    {
        $rows = Database::fetchAll(
            "SELECT sc.stu_id, sc.stu_score, sc.stu_status,
                    si.stu_name, si.class_id
             FROM `stuscore` sc
             LEFT JOIN `stuinfo` si ON si.id = sc.stu_id
             WHERE sc.exam_id = ? AND sc.stu_status LIKE 'over%'
             ORDER BY sc.stu_score DESC, sc.id ASC",
            [$examId]
        );

        $rank = 0;
        $prev = null;
        foreach ($rows as $i => $r) {
            $score = (int) $r['stu_score'];
            if ($prev === null || $score !== $prev) {
                $rank = $i + 1;
                $prev = $score;
            }
            $rows[$i]['rank'] = $rank;
        }
        return $rows;
    }

    /** 榜单行（对外字段，不含任何可直接定位到人的敏感列） */
    private static function rowOf(array $p, array $exam, string $viewerStuId): array
    {
        $total = (int) ($exam['exam_score'] ?? 0);
        $score = (int) ($p['stu_score'] ?? 0);
        $stuId = (string) $p['stu_id'];
        return [
            'stu_id'   => $stuId,
            'name'     => (string) ($p['stu_name'] ?? $stuId),
            'score'    => $score,
            'rank'     => (int) ($p['rank'] ?? 0),
            'percent'  => $total > 0 ? round($score / $total * 100, 1) : 0.0,
            'passed'   => Exam::isPassed($exam, $score),
            'is_me'    => $stuId === $viewerStuId,
        ];
    }
}
