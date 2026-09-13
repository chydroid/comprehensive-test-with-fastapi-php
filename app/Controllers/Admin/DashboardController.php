<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Exam;
use App\Models\Quiz;
use App\Models\StuScore;
use App\Models\Student;
use Core\Database;
use Core\Response;

/**
 * 管理后台概览（仪表盘）
 * 权限点：dashboard.view
 */
class DashboardController extends BaseController
{
    /** GET /api/admin/dashboard */
    public function index(): Response
    {
        $quizStats = (new Quiz())->stats();

        $counts = [
            'students'   => $this->count('stuinfo'),
            'teachers'   => $this->count('teainfo'),
            'subjects'   => $this->count('subject'),
            'grades'     => $this->count('gradeinfo'),
            'classes'    => $this->count('classinfo'),
            'categories' => $this->count('exam_category'),
            'admins'     => $this->count('admininfo'),
            'news'       => $this->count('examnews'),
        ];

        $examCounts = Database::fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN exam_status = 'testing' THEN 1 ELSE 0 END) AS testing,
                    SUM(CASE WHEN exam_status IN ('exam','paper') THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN LEFT(exam_status, 4) = 'over' THEN 1 ELSE 0 END) AS finished
             FROM `examinfo`
             WHERE COALESCE(exam_class, '') != '模拟考试'"
        ) ?? [];

        $mockCount = (int) (Database::fetch(
            "SELECT COUNT(*) AS c FROM `examinfo` WHERE exam_class = '模拟考试'"
        )['c'] ?? 0);

        return $this->ok([
            'quiz'    => $quizStats,
            'counts'  => $counts,
            'exams'   => [
                'total'    => (int) ($examCounts['total'] ?? 0),
                'testing'  => (int) ($examCounts['testing'] ?? 0),
                'pending'  => (int) ($examCounts['pending'] ?? 0),
                'finished' => (int) ($examCounts['finished'] ?? 0),
                'mock'     => $mockCount,
            ],
            'current_exams' => (new Exam())->activeWithSubject(),
            'recent_scores' => $this->recentScores(),
            'type_labels'   => Quiz::TYPE_LABELS,
            'diff_labels'   => Quiz::DIFF_LABELS,
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function count(string $table): int
    {
        return (int) (Database::fetch("SELECT COUNT(*) AS c FROM `{$table}`")['c'] ?? 0);
    }

    /** 最近 10 场已结束考试的成绩统计 */
    private function recentScores(): array
    {
        return (new StuScore())->examStats(10);
    }
}
