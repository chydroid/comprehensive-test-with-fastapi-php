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

    private static ?array $idNameMap = null;

    /** 单位 ID => 名称（请求内缓存，避免逐行查库） */
    public static function idNameMap(): array
    {
        if (self::$idNameMap === null) {
            self::$idNameMap = [];
            foreach (\Core\Database::fetchAll('SELECT id, grade_name FROM `gradeinfo`') as $r) {
                self::$idNameMap[(string) $r['id']] = (string) $r['grade_name'];
            }
        }
        return self::$idNameMap;
    }

    /**
     * 单位「名称或 ID」归一为单位 ID。
     *
     * stuinfo.grade_id 是 varchar，历史上（旧系统表单提交的就是名称）混入了单位名称，
     * 而新逻辑统一按 ID 关联。两条写入路径混用会让按单位筛选/统计对不上号，
     * 故所有写入口都过这一层：已是有效 ID 原样返回，名称折算为 ID，
     * 两者都认不出时原样保留，避免丢数据。
     */
    public static function resolveId(mixed $value): string
    {
        $token = trim((string) (is_array($value) ? implode(',', $value) : $value));
        if ($token === '') {
            return '';
        }
        $map = self::idNameMap();
        if (isset($map[$token])) {
            return $token;
        }
        $id = array_search($token, $map, true);
        return $id === false ? $token : (string) $id;
    }
}
