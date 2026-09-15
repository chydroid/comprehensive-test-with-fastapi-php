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

    private static ?array $idNameMap = null;

    /** 班级 ID => 名称（请求内缓存，避免逐行查库） */
    public static function idNameMap(): array
    {
        if (self::$idNameMap === null) {
            self::$idNameMap = [];
            foreach (\Core\Database::fetchAll('SELECT id, class_name FROM `classinfo`') as $r) {
                self::$idNameMap[(string) $r['id']] = (string) $r['class_name'];
            }
        }
        return self::$idNameMap;
    }

    /**
     * 班级「名称或 ID」归一为班级 ID。
     *
     * 班级归属的判定链路全部按 ID 走：
     *   - 排卷/名单：Student::byClassIds() → `class_id IN (...)`
     *   - 待考列表：Exam::pendingForStudent() → `FIND_IN_SET(class_id, exam.stu_class)`
     *   - 考场准入：Exam::isStudentEligible()
     * 但 stuinfo.class_id / examinfo.stu_class 都是 varchar，旧系统写入的是班级名称，
     * 两套写法并存时新考试永远匹配不到旧考生（考生看不到考试、排卷排不到人）。
     * 这里把名称折算成 ID，已是有效 ID 则原样返回，认不出则保留原值。
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

    /** 逗号分隔（或数组）的班级集合 → 去重后的 ID 列表字符串 */
    public static function resolveIds(mixed $value): string
    {
        $tokens = is_array($value) ? $value : explode(',', (string) $value);
        $out = [];
        foreach ($tokens as $t) {
            $id = self::resolveId($t);
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return implode(',', $out);
    }
}
