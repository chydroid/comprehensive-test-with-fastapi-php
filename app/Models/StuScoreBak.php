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
}
