<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * 考生（stuinfo）
 * grade_id / class_id 在旧库中为 varchar 但存单个 id（历史遗留），按字符串处理。
 */
class Student extends Model
{
    protected string $table = 'stuinfo';
    protected bool $timestamps = false;

    protected array $fillable = [
        'stu_name', 'stu_pwd', 'stu_sex', 'grade_id', 'class_id',
    ];

    public function findByName(string $name): ?array
    {
        return $this->firstWhere(['stu_name' => $name]);
    }

    public static function sanitize(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        unset($row['stu_pwd']);
        return $row;
    }
}
