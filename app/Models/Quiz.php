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
}
