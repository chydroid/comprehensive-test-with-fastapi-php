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

    /** 旧系统实际状态取值（与库中存量数据一致，勿改） */
    public const STATUS_TESTING  = 'testing';   // 进行中
    public const STATUS_EXAM     = 'exam';      // 已开考
    public const STATUS_PAPER    = 'paper';     // 已排卷
    public const STATUS_OVER_BAK = 'overBak';   // 已结束（历史）

    /** 「未结束」状态集合，用于考生端展示待考考试 */
    public const ACTIVE_STATUSES = ['exam', 'paper', 'testing'];

    /** 进行中的考试（含科目名/类别名），供前台门户展示 */
    public static function activeWithSubject(): array
    {
        return \Core\Database::fetchAll(
            "SELECT e.id, e.exam_name, e.exam_class, e.exam_start, e.exam_end, e.exam_status,
                    e.exam_score, e.subj_id, s.subj_name, c.category_name
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             LEFT JOIN `exam_category` c ON c.id = e.exam_category_id
             WHERE e.exam_status = 'testing'
             ORDER BY e.id DESC"
        );
    }

    /** 已登录考生的待考考试（按班级匹配 + 交卷状态） */
    public static function pendingForStudent(string $stuId, string $classId): array
    {
        $stuId = trim($stuId);
        $classId = trim($classId);
        if ($stuId === '' || $classId === '') {
            return [];
        }
        return \Core\Database::fetchAll(
            "SELECT e.id, e.exam_name, e.exam_class, e.exam_start, e.exam_end, e.exam_status,
                    e.exam_score, s.subj_name, c.category_name, sc.stu_status, sc.stu_score
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             LEFT JOIN `exam_category` c ON c.id = e.exam_category_id
             LEFT JOIN `stuscore` sc ON sc.exam_id = e.id AND sc.stu_id = ?
             WHERE e.exam_status IN ('exam', 'paper', 'testing')
               AND FIND_IN_SET(?, e.stu_class) > 0
             ORDER BY e.exam_start ASC, e.id DESC",
            [$stuId, $classId]
        );
    }

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
