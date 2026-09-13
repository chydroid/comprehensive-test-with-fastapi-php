<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * 答题卡 / 试卷明细（stupaper）
 * 一行 = 某考生某题；quiz_status：0 未答 / 1 已答（沿用旧库语义）
 */
class StuPaper extends Model
{
    protected string $table = 'stupaper';
    protected bool $timestamps = false;

    protected array $fillable = [
        'exam_id', 'stu_id', 'paper_id', 'quiz_id', 'quiz_class', 'stu_key', 'quiz_status',
    ];

    /** 某考生在某场考试的答题卡（已带出题目内容） */
    public function paperWithQuiz(int $examId, string $stuId): array
    {
        return \Core\Database::fetchAll(
            'SELECT p.*, q.quiz_title, q.quiz_option, q.quiz_class AS q_class, q.quiz_diff,
                    q.quiz_pic_name, q.quiz_key, q.quiz_key_ok
             FROM `stupaper` p
             LEFT JOIN `quizlib` q ON q.id = p.quiz_id
             WHERE p.exam_id = ? AND p.stu_id = ?
             ORDER BY p.paper_id ASC',
            [$examId, $stuId]
        );
    }

    /** 某考生在某场考试是否已有答题卡 */
    public function exists(int $examId, string $stuId): bool
    {
        $row = \Core\Database::fetch(
            'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }
}
