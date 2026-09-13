<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 成绩（stuscore）；stu_status：0 未交卷 / 1 已交卷（沿用旧库语义） */
class StuScore extends Model
{
    protected string $table = 'stuscore';
    protected bool $timestamps = false;

    protected array $fillable = [
        'exam_id', 'stu_id', 'stu_score', 'stu_status', 'stu_pwd',
    ];

    public const STATUS_UNSUBMITTED = '0';
    public const STATUS_SUBMITTED   = '1';

    public function findOne(int $examId, string $stuId): ?array
    {
        return $this->firstWhere(['exam_id' => $examId, 'stu_id' => $stuId]);
    }
}
