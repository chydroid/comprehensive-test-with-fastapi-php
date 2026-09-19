<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * 电子证书（certificate）—— 签发记录，正文为签发时快照。
 */
class Certificate extends Model
{
    protected string $table = 'certificate';
    protected bool $timestamps = false;

    protected array $fillable = [
        'exam_id', 'stu_id', 'stu_name', 'exam_name', 'subj_name',
        'score', 'total_score', 'threshold', 'cert_no', 'issued_at',
    ];
}
