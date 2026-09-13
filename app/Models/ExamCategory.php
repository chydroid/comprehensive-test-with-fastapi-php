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

    /** 按 sort_order 排序的全部类别 */
    public function ordered(): array
    {
        return $this->all('sort_order ASC, id ASC');
    }

    /** 类别名是否被他人占用 */
    public function nameTaken(string $name, int $excludeId = 0): bool
    {
        $row = $this->firstWhere(['category_name' => $name]);
        return $row !== null && (int) $row['id'] !== $excludeId;
    }

    /** 是否有考试引用该类别 */
    public function inUse(int $id): bool
    {
        $row = \Core\Database::fetch('SELECT COUNT(*) AS c FROM `examinfo` WHERE exam_category_id = ?', [$id]);
        return (int) ($row['c'] ?? 0) > 0;
    }
}
