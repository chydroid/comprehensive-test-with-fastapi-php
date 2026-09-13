<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;

/**
 * 前台成绩相关（公开）与英雄榜
 */
class ScoreController extends BaseController
{
    /**
     * GET /api/public/hero —— 成绩英雄榜 TOP N
     *
     * 敏感信息最小化：只回姓名（脱敏为「张*三」）、成绩、考试名，
     * 不回传准考证号，避免公开页面泄露考生身份（旧系统 hero 页直出 id）。
     */
    public function hero(): Response
    {
        $limit = (int) $this->request->query('limit', 10);
        $limit = min(50, max(1, $limit));
        $examId = (int) $this->request->query('exam_id', 0);

        // 默认取最近一场已结束考试（与旧系统英雄榜口径一致）；没有已结束考试时退化为全站 TOP N
        if ($examId <= 0) {
            $row = Database::fetch(
                "SELECT id FROM `examinfo` WHERE LEFT(exam_status, 4) = 'over' ORDER BY id DESC LIMIT 1"
            );
            $examId = (int) ($row['id'] ?? 0);
        }

        $fetch = static function (int $examId, int $limit): array {
            $where = $examId > 0 ? 'AND sc.exam_id = ' . $examId : '';
            // stu_status 取值：waiting / online / over / overBak / locked，只有已交卷的才上榜
            return Database::fetchAll(
                "SELECT sc.stu_score, sc.exam_id, st.stu_name, st.grade_id, e.exam_name, s.subj_name
                 FROM `stuscore` sc
                 INNER JOIN `stuinfo` st ON st.id = sc.stu_id
                 LEFT JOIN `examinfo` e ON e.id = sc.exam_id
                 LEFT JOIN `subject` s ON s.id = e.subj_id
                 WHERE sc.stu_status LIKE 'over%' $where
                 ORDER BY sc.stu_score DESC, sc.id ASC
                 LIMIT $limit"
            );
        };

        $rows = $fetch($examId, $limit);
        // 指定/最近那场考试还没有有效成绩时，退化为全站 TOP N，避免榜单空白
        if ($rows === [] && $examId > 0) {
            $examId = 0;
            $rows = $fetch(0, $limit);
        }

        foreach ($rows as $i => &$r) {
            $r['rank']      = $i + 1;
            $r['stu_name']  = self::maskName((string) ($r['stu_name'] ?? ''));
            $r['grade_id']  = (string) ($r['grade_id'] ?? '');
            $r['stu_score'] = (int) $r['stu_score'];
        }
        unset($r);

        $exam = null;
        if ($examId > 0 && $rows !== [] && (int) $rows[0]['exam_id'] > 0) {
            $exam = [
                'id'        => (int) $rows[0]['exam_id'],
                'exam_name' => (string) ($rows[0]['exam_name'] ?? ''),
                'subj_name' => (string) ($rows[0]['subj_name'] ?? ''),
            ];
        }

        return $this->ok(['list' => $rows, 'exam' => $exam]);
    }

    /** 姓名脱敏：保留首尾字，中间以 * 代替（两字名只掩末字） */
    private static function maskName(string $name): string
    {
        $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($len <= 1) {
            return $name;
        }
        $first = mb_substr($name, 0, 1);
        $last = mb_substr($name, -1, 1);
        if ($len === 2) {
            return $first . '*';
        }
        return $first . str_repeat('*', $len - 2) . $last;
    }
}
