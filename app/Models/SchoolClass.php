<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 班级（classinfo） */
class SchoolClass extends Model
{
    protected string $table = 'classinfo';
    protected bool $timestamps = false;

    protected array $fillable = ['class_name', 'class_info'];
}
