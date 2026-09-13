<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 监考教师（teainfo） */
class Teacher extends Model
{
    protected string $table = 'teainfo';
    protected bool $timestamps = false;

    protected array $fillable = ['tea_name', 'tea_pwd', 'avatar'];

    public function findByName(string $name): ?array
    {
        return $this->firstWhere(['tea_name' => $name]);
    }

    public static function sanitize(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        unset($row['tea_pwd']);
        return $row;
    }
}
