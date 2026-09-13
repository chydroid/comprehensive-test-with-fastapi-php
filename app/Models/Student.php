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

    /** 准考证号（主键）是否已存在 */
    public function existsId(string $id): bool
    {
        return $this->find($id) !== null;
    }

    /** 姓名是否被他人（$excludeId 除外）占用 */
    public function nameTaken(string $name, string $excludeId = ''): bool
    {
        $row = $this->findByName($name);
        if ($row === null) {
            return false;
        }
        return (string) $row['id'] !== $excludeId;
    }

    /** 按班级 id 列表取考生（用于排卷） */
    public function byClassIds(array $classIds): array
    {
        $classIds = array_values(array_filter(array_map('trim', $classIds), static fn ($v) => $v !== ''));
        if ($classIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($classIds), '?'));
        return \Core\Database::fetchAll(
            "SELECT id, stu_name, grade_id, class_id FROM `stuinfo` WHERE class_id IN ({$ph}) ORDER BY id ASC",
            $classIds
        );
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
