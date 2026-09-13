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

    /** 单位名是否被他人占用 */
    public function nameTaken(string $name, int $excludeId = 0): bool
    {
        $row = $this->firstWhere(['grade_name' => $name]);
        return $row !== null && (int) $row['id'] !== $excludeId;
    }

    /** 是否有考生归属该单位 */
    public function inUse(int $id): bool
    {
        $row = \Core\Database::fetch('SELECT COUNT(*) AS c FROM `stuinfo` WHERE grade_id = ?', [(string) $id]);
        return (int) ($row['c'] ?? 0) > 0;
    }
}
