<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 成绩备份（stuscorebak） */
class StuScoreBak extends Model
{
    protected string $table = 'stuscorebak';
    protected bool $timestamps = false;

    protected array $fillable = [
        'stu_id', 'stu_name', 'grade_id', 'stu_score', 'exam_id', 'backup_time',
    ];

    /** 备份记录列表（含考试名与科目名） */
    public function listWithExam(): array
    {
        return \Core\Database::fetchAll(
            'SELECT sb.*, e.exam_name, s.subj_name
             FROM `stuscorebak` sb
             INNER JOIN `examinfo` e ON sb.exam_id = e.id
             INNER JOIN `subject` s ON e.subj_id = s.id
             ORDER BY sb.backup_time DESC, sb.id DESC'
        );
    }

    /** 某考生历史备份成绩 */
    public function byStudent(string $stuId): array
    {
        return \Core\Database::fetchAll(
            'SELECT sb.*, e.exam_name, s.subj_name
             FROM `stuscorebak` sb
             INNER JOIN `examinfo` e ON sb.exam_id = e.id
             INNER JOIN `subject` s ON e.subj_id = s.id
             WHERE sb.stu_id = ?
             ORDER BY sb.backup_time DESC',
            [$stuId]
        );
    }
}
