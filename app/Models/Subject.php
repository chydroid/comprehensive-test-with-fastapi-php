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
}
