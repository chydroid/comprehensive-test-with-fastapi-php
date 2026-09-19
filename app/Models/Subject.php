<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 科目（subject） */
class Subject extends Model
{
    protected string $table = 'subject';
    protected bool $timestamps = false;

    protected array $fillable = ['subj_name', 'subj_info'];

    /** 科目名是否被他人占用 */
    public function nameTaken(string $name, int $excludeId = 0): bool
    {
        $row = $this->firstWhere(['subj_name' => $name]);
        return $row !== null && (int) $row['id'] !== $excludeId;
    }

    /** 该科目下是否已有题目（用于删除前校验） */
    public function hasQuizzes(int $id): bool
    {
        $row = \Core\Database::fetch('SELECT COUNT(*) AS c FROM `quizlib` WHERE subj_id = ?', [$id]);
        return (int) ($row['c'] ?? 0) > 0;
    }

    /** 该科目下是否有考试（模拟考试不算：它是考生自主生成的临时记录，不该锁住基础数据维护） */
    public function hasExams(int $id): bool
    {
        $row = \Core\Database::fetch(
            'SELECT COUNT(*) AS c FROM `examinfo`
             WHERE subj_id = ? AND COALESCE(exam_class, \'\') <> ?',
            [$id, Exam::MOCK_CLASS]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }

    private static ?array $idNameMap = null;

    /** 科目 ID => 名称（请求内缓存，避免批量导入逐行查库） */
    public static function idNameMap(): array
    {
        if (self::$idNameMap === null) {
            self::$idNameMap = [];
            foreach (\Core\Database::fetchAll('SELECT id, subj_name FROM `subject`') as $r) {
                self::$idNameMap[(string) $r['id']] = (string) $r['subj_name'];
            }
        }
        return self::$idNameMap;
    }

    /**
     * 科目「名称或 ID」归一为科目 ID；认不出返回 0。
     *
     * 批量导入题库时，CSV 里第 1 列通常是科目名（人写的），而库内一律按 ID 关联。
     * 写入口必须过这一层，否则会往 quizlib.subj_id 塞名字，导致按科目筛选/组卷全部对不上号。
     */
    public static function resolveId(mixed $value): int
    {
        $token = trim((string) (is_array($value) ? implode(',', $value) : $value));
        if ($token === '') {
            return 0;
        }
        $map = self::idNameMap();
        if (ctype_digit($token)) {
            return isset($map[$token]) ? (int) $token : 0;
        }
        $id = array_search($token, $map, true);
        return $id === false ? 0 : (int) $id;
    }
}
