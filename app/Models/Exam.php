<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * 考试（examinfo）
 * 组卷参数：radio1/radio2/checkbox/text 四类题目各有 易/中/难 数量与分值。
 */
class Exam extends Model
{
    protected string $table = 'examinfo';
    protected bool $timestamps = false;

    protected array $fillable = [
        'exam_name', 'exam_class', 'exam_category_id', 'subj_id',
        'exam_start', 'exam_end', 'exam_tea', 'stu_class',
        'radio1_easy_sum', 'radio1_mid_sum', 'radio1_hard_sum', 'radio1_val',
        'radio2_easy_sum', 'radio2_mid_sum', 'radio2_hard_sum', 'radio2_val',
        'checkbox_easy_sum', 'checkbox_mid_sum', 'checkbox_hard_sum', 'checkbox_val',
        'text_easy_sum', 'text_mid_sum', 'text_hard_sum', 'text_val',
        'exam_status', 'exam_pwd', 'exam_score',
    ];

    /** 四种题型的组卷字段前缀 */
    public const TYPE_PREFIXES = ['radio1', 'radio2', 'checkbox', 'text'];

    /** 从考试记录中提取组卷配置：[['type'=>'radio1','easy'=>n,'mid'=>n,'hard'=>n,'val'=>n], ...] */
    public static function paperPlan(array $exam): array
    {
        $plan = [];
        foreach (self::TYPE_PREFIXES as $t) {
            $plan[] = [
                'type' => $t,
                'easy' => (int) ($exam["{$t}_easy_sum"] ?? 0),
                'mid'  => (int) ($exam["{$t}_mid_sum"] ?? 0),
                'hard' => (int) ($exam["{$t}_hard_sum"] ?? 0),
                'val'  => (int) ($exam["{$t}_val"] ?? 0),
            ];
        }
        return $plan;
    }

    /** 该场考试应出题总数 */
    public static function totalQuestions(array $exam): int
    {
        $n = 0;
        foreach (self::paperPlan($exam) as $p) {
            $n += $p['easy'] + $p['mid'] + $p['hard'];
        }
        return $n;
    }

    /** 按组卷参数推算的满分 */
    public static function computedTotalScore(array $exam): int
    {
        $n = 0;
        foreach (self::paperPlan($exam) as $p) {
            $n += ($p['easy'] + $p['mid'] + $p['hard']) * $p['val'];
        }
        return $n;
    }

    /** 题库中各题型/难度可用题量：返回 [type => [Y=>n, Z=>n, N=>n]] */
    public static function availableCounts(int $subjId): array
    {
        $rows = \Core\Database::fetchAll(
            'SELECT quiz_class, quiz_diff, COUNT(*) AS c FROM `quizlib`
             WHERE subj_id = ? GROUP BY quiz_class, quiz_diff',
            [$subjId]
        );
        $out = [];
        foreach (self::TYPE_PREFIXES as $t) {
            $out[$t] = ['Y' => 0, 'Z' => 0, 'N' => 0];
        }
        foreach ($rows as $r) {
            $type = (string) $r['quiz_class'];
            $diff = (string) $r['quiz_diff'];
            if (isset($out[$type][$diff])) {
                $out[$type][$diff] = (int) $r['c'];
            }
        }
        return $out;
    }

    /**
     * 校验组卷参数是否超出题库可用量
     * @return array{ok:bool, shortfall:array<int,array{type:string,diff:string,need:int,have:int}>}
     */
    public static function checkStock(array $exam): array
    {
        $avail = self::availableCounts((int) ($exam['subj_id'] ?? 0));
        $diffKey = ['easy' => 'Y', 'mid' => 'Z', 'hard' => 'N'];
        $shortfall = [];
        foreach (self::paperPlan($exam) as $p) {
            foreach ($diffKey as $field => $code) {
                $need = $p[$field];
                $have = $avail[$p['type']][$code] ?? 0;
                if ($need > $have) {
                    $shortfall[] = [
                        'type' => $p['type'],
                        'diff' => $code,
                        'need' => $need,
                        'have' => $have,
                    ];
                }
            }
        }
        return ['ok' => $shortfall === [], 'shortfall' => $shortfall];
    }
}
