<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 单位 / 年级（gradeinfo） */
class Grade extends Model
{
    protected string $table = 'gradeinfo';
    protected bool $timestamps = false;

    protected array $fillable = ['grade_name', 'grade_info'];
}
