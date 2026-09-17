<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 科目（subject） */
class Subject extends Model
{
    protected string $table = 'subject';
    protected bool $timestamps = false;

    protected array $fillable = ['subj_name', 'subj_info'];

    /** 科目名是否被他人占用 */
    public function nameTaken(string $name, int $excludeId = 0): bool
    {
        $row = $this->firstWhere(['subj_name' => $name]);
        return $row !== null && (int) $row['id'] !== $excludeId;
    }

    /** 该科目下是否已有题目（用于删除前校验） */
    public function hasQuizzes(int $id): bool
    {
        $row = \Core\Database::fetch('SELECT COUNT(*) AS c FROM `quizlib` WHERE subj_id = ?', [$id]);
        return (int) ($row['c'] ?? 0) > 0;
    }

    /** 该科目下是否有考试（模拟考试不算：它是考生自主生成的临时记录，不该锁住基础数据维护） */
    public function hasExams(int $id): bool
    {
        $row = \Core\Database::fetch(
            'SELECT COUNT(*) AS c FROM `examinfo`
             WHERE subj_id = ? AND COALESCE(exam_class, \'\') <> ?',
            [$id, Exam::MOCK_CLASS]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }
}
