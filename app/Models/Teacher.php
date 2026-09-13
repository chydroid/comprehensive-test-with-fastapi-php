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

    /** 姓名是否被他人占用 */
    public function nameTaken(string $name, int $excludeId = 0): bool
    {
        $row = $this->findByName($name);
        return $row !== null && (int) $row['id'] !== $excludeId;
    }

    /** 剥离密码字段 */
    public static function sanitize(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        unset($row['tea_pwd']);
        return $row;
    }
}
