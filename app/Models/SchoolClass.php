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

    /** 班级名是否被他人占用 */
    public function nameTaken(string $name, int $excludeId = 0): bool
    {
        $row = $this->firstWhere(['class_name' => $name]);
        return $row !== null && (int) $row['id'] !== $excludeId;
    }

    /** 是否有考生归属该班级 */
    public function inUse(int $id): bool
    {
        $row = \Core\Database::fetch('SELECT COUNT(*) AS c FROM `stuinfo` WHERE class_id = ?', [(string) $id]);
        return (int) ($row['c'] ?? 0) > 0;
    }
}
