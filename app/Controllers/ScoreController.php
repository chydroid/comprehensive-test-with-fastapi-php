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

        $rows = Database::fetchAll(
            "SELECT sc.stu_score, sc.exam_id, st.stu_name, e.exam_name, s.subj_name
             FROM `stuscore` sc
             INNER JOIN `stuinfo` st ON st.id = sc.stu_id
             LEFT JOIN `examinfo` e ON e.id = sc.exam_id
             LEFT JOIN `subject` s ON s.id = e.subj_id
             WHERE sc.stu_status = '1' AND sc.stu_score > 0
             ORDER BY sc.stu_score DESC, sc.id ASC
             LIMIT $limit"
        );

        foreach ($rows as &$r) {
            $r['stu_name'] = self::maskName((string) ($r['stu_name'] ?? ''));
        }
        unset($r);

        return $this->ok(['list' => $rows]);
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
