<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 考试类别（exam_category） */
class ExamCategory extends Model
{
    protected string $table = 'exam_category';
    protected bool $timestamps = false;

    protected array $fillable = ['category_name', 'sort_order'];
}
