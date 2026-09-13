<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * 题库（quizlib）
 *
 * ⚠️ quiz_class 的取值与「题型语义」并不直白，以旧系统 Admin\ExamController 的
 * $typeNames 映射为准（这是全站唯一权威约定）：
 *   radio1   → 判断题（选项为 对|错，答案 A/B）
 *   radio2   → 单选题
 *   checkbox → 多选题（答案为多个字母，如 ABCD）
 *   text     → 填空题
 *   longtext → 问答题
 *
 * quiz_diff ：Y 易 / Z 中 / N 难
 * quiz_key  ：正确答案（**绝不下发给考生端**）
 * quiz_option：选项原文，以 `|` 分隔（旧库实际用法），也兼容换行与 |||
 */
class Quiz extends Model
{
    protected string $table = 'quizlib';
    protected bool $timestamps = false;

    protected array $fillable = [
        'subj_id', 'quiz_title', 'quiz_class', 'quiz_option', 'quiz_key',
        'quiz_diff', 'quiz_writer', 'quiz_time', 'quiz_pic_name', 'quiz_hits', 'quiz_key_ok',
    ];

    public const TYPES = ['radio1', 'radio2', 'checkbox', 'text', 'longtext'];

    /** 权威题型名称映射（与旧系统一致，勿凭枚举名臆断） */
    public const TYPE_LABELS = [
        'radio1'   => '判断题',
        'radio2'   => '单选题',
        'checkbox' => '多选题',
        'text'     => '填空题',
        'longtext' => '问答题',
    ];

    /** 是否为客观题（可自动判分） */
    public const OBJECTIVE_TYPES = ['radio1', 'radio2', 'checkbox'];

    public const DIFFS = ['Y', 'Z', 'N'];
    public const DIFF_LABELS = ['Y' => '易', 'Z' => '中', 'N' => '难'];

    /**
     * 剥离正确答案，供考生端使用（P0-4 修复）。
     * 同时把选项解析为数组，便于前端渲染。
     */
    public static function withoutAnswer(array $row): array
    {
        unset($row['quiz_key']);
        $row['quiz_option_list'] = self::parseOptions($row['quiz_option'] ?? '');
        $row['quiz_type_label']  = self::TYPE_LABELS[$row['quiz_class'] ?? ''] ?? '未知';
        return $row;
    }

    /**
     * 解析选项文本为 [['key'=>'A','text'=>'...'], ...]
     *
     * 旧库实际以 `|` 分隔（如 "军舰|仅指民用商船|仅指机动船"），
     * 判断题则为 "对|错"。同时兼容换行与 ||| 分隔；若某项已带
     * "A. xxx" / "A、xxx" 前缀则沿用，否则按序号自动生成 A/B/C…
     */
    public static function parseOptions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        // 先按 ||| 切，再按 | 与换行切；| 是旧库主用法
        $parts = preg_split('/\|\|\||\r\n|\r|\n|\|/', $raw) ?: [];
        $out = [];
        $i = 0;
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (preg_match('/^([A-Z])[.、:：\s]\s*(.*)$/u', $p, $m)) {
                $out[] = ['key' => $m[1], 'text' => $m[2]];
            } else {
                $out[] = ['key' => chr(65 + $i), 'text' => $p];
            }
            $i++;
        }
        return $out;
    }

    /**
     * 归一化答案，用于判分比对。
     *
     * 旧系统 bug：直接拿原始字符串比较，导致多选题 "ABCD" 与 "DCBA"、
     * 或带空格的答案被判错。此处统一：去空白 → 大写 → 多选题按字母升序排序。
     */
    public static function normalizeAnswer(string $type, string $answer): string
    {
        $answer = strtoupper(preg_replace('/\s+/u', '', $answer) ?? '');
        if ($type === 'checkbox') {
            $chars = str_split($answer);
            sort($chars);
            return implode('', $chars);
        }
        return $answer;
    }

    /** 判断考生答案是否正确 */
    public static function isCorrect(string $type, string $correctKey, string $userKey): bool
    {
        return self::normalizeAnswer($type, $correctKey) === self::normalizeAnswer($type, $userKey);
    }

    /* ------------------------------------------------------------------ */
    /* 管理端查询 / 清理                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * 题库分页查询（管理端）
     * @param array{subj_id?:int,quiz_class?:string,quiz_diff?:string,keyword?:string} $filters
     * @return array{data:array,total:int}
     */
    public function adminList(array $filters, int $offset, int $perPage, string $order = 'DESC'): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['subj_id'])) {
            $where[] = 'q.subj_id = ?';
            $params[] = (int) $filters['subj_id'];
        }
        if (!empty($filters['quiz_class'])) {
            $where[] = 'q.quiz_class = ?';
            $params[] = (string) $filters['quiz_class'];
        }
        if (!empty($filters['quiz_diff'])) {
            $where[] = 'q.quiz_diff = ?';
            $params[] = (string) $filters['quiz_diff'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(q.quiz_title LIKE ? OR q.quiz_key LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        $sqlWhere = implode(' AND ', $where);
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $total = (int) (\Core\Database::fetch(
            "SELECT COUNT(*) AS c FROM `quizlib` q WHERE {$sqlWhere}",
            $params
        )['c'] ?? 0);

        $rows = \Core\Database::fetchAll(
            "SELECT q.*, s.subj_name
             FROM `quizlib` q
             INNER JOIN `subject` s ON s.id = q.subj_id
             WHERE {$sqlWhere}
             ORDER BY q.id {$order}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['data' => $rows, 'total' => $total];
    }

    /** 单题详情（含科目名） */
    public function detail(int $id): ?array
    {
        return \Core\Database::fetch(
            'SELECT q.*, s.subj_name FROM `quizlib` q
             INNER JOIN `subject` s ON s.id = q.subj_id WHERE q.id = ?',
            [$id]
        );
    }

    /** 按科目统计各题型/难度题量：返回 [type => [Y=>n,Z=>n,N=>n]]（与 Exam::availableCounts 同构） */
    public static function countsBySubject(int $subjId): array
    {
        $rows = \Core\Database::fetchAll(
            'SELECT quiz_class, quiz_diff, COUNT(*) AS c FROM `quizlib`
             WHERE subj_id = ? GROUP BY quiz_class, quiz_diff',
            [$subjId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['quiz_class']][(string) $r['quiz_diff']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * 找出重复题（同科目 + 同题干 + 同选项），保留最小 id。
     * @return array<int,array{keepId:int,ids:string,quiz_title:string,quiz_option:string,cnt:int}>
     */
    public function duplicates(?int $subjId = null): array
    {
        $sql = 'SELECT MIN(id) AS keepId, GROUP_CONCAT(id ORDER BY id) AS ids,
                       subj_id, quiz_title, quiz_option, COUNT(*) AS cnt
                FROM `quizlib`';
        $params = [];
        if ($subjId !== null && $subjId > 0) {
            $sql .= ' WHERE subj_id = ?';
            $params[] = $subjId;
        }
        $sql .= ' GROUP BY subj_id, quiz_title, quiz_option HAVING COUNT(*) > 1';

        return \Core\Database::fetchAll($sql, $params);
    }

    /**
     * 删除重复题（保留 each group 的最小 id）。
     * @return array{groups:int, deleted:int}
     */
    public function deleteDuplicates(?int $subjId = null): array
    {
        $groups = $this->duplicates($subjId);
        $deleted = 0;
        foreach ($groups as $g) {
            $ids = array_map('intval', explode(',', (string) $g['ids']));
            $keep = (int) $g['keepId'];
            $remove = array_values(array_filter($ids, static fn (int $i): bool => $i !== $keep));
            if ($remove === []) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($remove), '?'));
            $deleted += \Core\Database::query(
                "DELETE FROM `quizlib` WHERE id IN ({$ph})",
                $remove
            )->rowCount();
        }
        return ['groups' => count($groups), 'deleted' => $deleted];
    }

    /** 批量删除，返回实际删除行数 */
    public function deleteMany(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return \Core\Database::query("DELETE FROM `quizlib` WHERE id IN ({$ph})", $ids)->rowCount();
    }

    /**
     * 修正单选实为多选的题目（quiz_class=radio2 但答案长度 > 1）→ 改为 checkbox。
     * @return int 修正条数
     */
    public function fixSingleAsMulti(): int
    {
        return \Core\Database::query(
            "UPDATE `quizlib` SET quiz_class = 'checkbox'
             WHERE quiz_class = 'radio2' AND CHAR_LENGTH(quiz_key) > 1"
        )->rowCount();
    }

    /** 答案转大写（返回受影响行数） */
    public function upperCaseKeys(): int
    {
        return \Core\Database::query(
            "UPDATE `quizlib` SET quiz_key = UPPER(quiz_key)
             WHERE quiz_key <> '' AND BINARY quiz_key <> BINARY UPPER(quiz_key)"
        )->rowCount();
    }

    /** 多选答案按字母升序归一化，返回修正条数 */
    public function sortMultiKeys(): int
    {
        $rows = \Core\Database::fetchAll(
            "SELECT id, quiz_key FROM `quizlib` WHERE quiz_class = 'checkbox' AND quiz_key <> ''"
        );
        $fixed = 0;
        foreach ($rows as $r) {
            $old = (string) $r['quiz_key'];
            $new = self::normalizeAnswer('checkbox', $old);
            if ($new !== $old) {
                \Core\Database::query('UPDATE `quizlib` SET quiz_key = ? WHERE id = ?', [$new, (int) $r['id']]);
                $fixed++;
            }
        }
        return $fixed;
    }

    /** 题库总览统计：按题型与难度聚合 */
    public function stats(): array
    {
        $rows = \Core\Database::fetchAll(
            'SELECT quiz_class, quiz_diff, COUNT(*) AS c FROM `quizlib`
             GROUP BY quiz_class, quiz_diff'
        );
        $byType = [];
        $byDiff = ['Y' => 0, 'Z' => 0, 'N' => 0];
        $total = 0;
        foreach (self::TYPES as $t) {
            $byType[$t] = 0;
        }
        foreach ($rows as $r) {
            $type = (string) $r['quiz_class'];
            $diff = (string) $r['quiz_diff'];
            $c = (int) $r['c'];
            $total += $c;
            $byType[$type] = ($byType[$type] ?? 0) + $c;
            if (isset($byDiff[$diff])) {
                $byDiff[$diff] += $c;
            }
        }
        return ['total' => $total, 'by_type' => $byType, 'by_diff' => $byDiff];
    }
}
