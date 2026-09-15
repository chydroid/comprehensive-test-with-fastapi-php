<?php
declare(strict_types=1);

namespace App\Models;

use App\Services\Setting;
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

    /**
     * 模拟考试在 examinfo 中的 exam_class 标记（与正式考试的唯一区分依据）。
     * 与 ExerciseExamController 共用，避免两处各写一份字面量而分叉。
     */
    public const MOCK_CLASS = '模拟考试';

    /**
     * 是否存在「进行中的正式考试」。
     *
     * 注意：模拟考试同样以 exam_status = 'testing' 落库
     * （见 ExamEngine::createPracticeExam），因此判断「正式考试是否在进行」
     * 必须排除 exam_class = '模拟考试'——否则学生自己开一场模拟考试，
     * 就会被练习 / 模拟入口误判成「考试进行中」（练习被错误暂停）。
     */
    public static function hasOngoingFormalExam(): bool
    {
        $row = \Core\Database::fetch(
            "SELECT COUNT(*) AS c FROM `examinfo`
             WHERE exam_status = ? AND COALESCE(exam_class, '') <> ?",
            [self::STATUS_TESTING, self::MOCK_CLASS]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }

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

    /**
     * 管理端考试列表（支持按科目/状态/关键字过滤 + 分页）。
     * @param array{subj_id?:int,status?:string,keyword?:string} $filters
     * @return array{data:array,total:int}
     */
    public function adminList(array $filters, int $offset, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['subj_id'])) {
            $where[] = 'e.subj_id = ?';
            $params[] = (int) $filters['subj_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'e.exam_status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(e.exam_name LIKE ? OR e.exam_class LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        $sqlWhere = implode(' AND ', $where);

        $total = (int) (\Core\Database::fetch(
            "SELECT COUNT(*) AS c FROM `examinfo` e WHERE {$sqlWhere}",
            $params
        )['c'] ?? 0);

        $rows = \Core\Database::fetchAll(
            "SELECT e.*, s.subj_name, c.category_name
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             LEFT JOIN `exam_category` c ON c.id = e.exam_category_id
             WHERE {$sqlWhere}
             ORDER BY e.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['data' => $rows, 'total' => $total];
    }

    /** 单场考试详情（含科目/类别名） */
    public function detail(int $id): ?array
    {
        return \Core\Database::fetch(
            "SELECT e.*, s.subj_name, c.category_name
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             LEFT JOIN `exam_category` c ON c.id = e.exam_category_id
             WHERE e.id = ?",
            [$id]
        );
    }

    /** 已结束的正式考试（排除模拟考试），供成绩模块选择 */
    public function finishedList(): array
    {
        return \Core\Database::fetchAll(
            "SELECT e.id, e.exam_name, e.exam_status, e.exam_score, s.subj_name
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             WHERE LEFT(e.exam_status, 4) = 'over'
               AND COALESCE(e.exam_class, '') != '模拟考试'
             ORDER BY e.id DESC"
        );
    }

    /**
     * 启动考试（开考）：状态置为进行中(testing)。
     * 若已由「开放入场」生成过考场口令，则**沿用**该口令（避免开考时口令突变，
     * 让已凭口令入场的考生与仍在入场窗口外等待的考生失去一致性）；
     * 否则临时生成一个。
     * @return string 考场口令
     */
    public function start(int $id): string
    {
        $exam = $this->find($id);
        $pwd = (string) ($exam['exam_pwd'] ?? '');
        if ($pwd === '' || $pwd === '0') {
            $pwd = self::generatePwd();
        }
        \Core\Database::query(
            "UPDATE `examinfo` SET exam_status = 'testing', exam_pwd = ? WHERE id = ?",
            [$pwd, $id]
        );
        \Core\Database::query(
            "UPDATE `stuscore` SET stu_pwd = ? WHERE exam_id = ?",
            [$pwd, $id]
        );
        return $pwd;
    }

    /* ==================================================================
     * 入场窗口 / 开放入场 / 惰性自动开考
     * 流程：开放入场(生成口令,保持未开考) → 考生凭口令在入场窗口内入场
     *       → 监考出题(排卷) → 到点惰性自动开考 或 手动开考 → 考生作答
     *
     * 入场窗口由后台「系统设置 → 考试规则」控制，不再写死：
     *   exam_entry_lead_minutes  开考前多久开放入场（默认 15 分钟）
     *   exam_entry_late_minutes  开考后迟到入场的宽限（默认 0 = 开考后不得入场）
     * ================================================================== */

    /** 入场提前量（秒）：读取后台设置，随配置实时生效（默认 15 分钟见 Setting schema） */
    public static function entryLeadSeconds(): int
    {
        return max(0, Setting::int('exam_entry_lead_minutes', 15) * 60);
    }

    /** 开考后允许迟到入场的宽限（秒）：0 表示开考后不得入场 */
    public static function entryLateSeconds(): int
    {
        return max(0, Setting::int('exam_entry_late_minutes', 0) * 60);
    }

    /** 生成一个符合后台配置位数的考场口令（纯数字） */
    public static function generatePwd(): string
    {
        $len = Setting::int('exam_pwd_length', 6);
        $len = max(4, min(10, $len));
        $min = (int) (10 ** ($len - 1));
        $max = (int) (10 ** $len) - 1;
        return (string) random_int($min, $max);
    }

    /** 入场窗口开启时间戳（开考前 N 分钟） */
    public static function entryOpensAt(array $exam): ?int
    {
        $start = strtotime((string) ($exam['exam_start'] ?? ''));
        return $start === false ? null : $start - self::entryLeadSeconds();
    }

    /** 入场窗口关闭时间戳（开考时间 + 迟到宽限） */
    public static function entryClosesAt(array $exam): ?int
    {
        $start = strtotime((string) ($exam['exam_start'] ?? ''));
        return $start === false ? null : $start + self::entryLateSeconds();
    }

    /** 是否已「开放入场」（存在有效考场口令） */
    public static function isOpenForEntry(array $exam): bool
    {
        $pwd = (string) ($exam['exam_pwd'] ?? '');
        return $pwd !== '' && $pwd !== '0';
    }

    /**
     * 惰性自动开考：已出题(paper) 且到达开考时间 → 置为进行中(testing)。
     * 本系统无常驻定时任务，故在考生/监考访问时顺带触发（幂等）。
     * @return bool 本次是否发生了状态推进
     */
    public static function autoStartIfDue(int $examId): bool
    {
        $exam = (new self())->find($examId);
        if ($exam === null || (string) $exam['exam_status'] !== self::STATUS_PAPER) {
            return false;
        }
        $start = strtotime((string) ($exam['exam_start'] ?? ''));
        if ($start === false || time() < $start) {
            return false;
        }
        \Core\Database::query(
            "UPDATE `examinfo` SET exam_status = 'testing' WHERE id = ? AND exam_status = 'paper'",
            [$examId]
        );
        return true;
    }

    /**
     * 开放入场：生成符合后台配置位数的考场口令，状态保持「未开考」。
     * 开放入场后考生才能凭口令在入场窗口内进场。
     * @return string 新的考场口令
     */
    public function openForEntry(int $id): string
    {
        $pwd = self::generatePwd();
        \Core\Database::query(
            "UPDATE `examinfo` SET exam_pwd = ? WHERE id = ?",
            [$pwd, $id]
        );
        \Core\Database::query(
            "UPDATE `stuscore` SET stu_pwd = ? WHERE exam_id = ?",
            [$pwd, $id]
        );
        return $pwd;
    }

    /**
     * 计算某考生对某场考试的入场状态（供考生端展示与前端按钮控制）。
     *
     * state 取值：
     *   submitted  已交卷
     *   answering  已开考且考生在考场内（可继续作答）
     *   in_room    未开考但考生已进入考场（等待室）
     *   open       可入场（窗口内 + 已开放入场）
     *   upcoming   未到入场时间（开考前 exam_entry_lead_minutes 分钟才开放）
     *   closed     不可入场（未开放入场 / 已开考未入场 / 已结束）
     *
     * @param array      $exam  examinfo 行
     * @param array|null $score stuscore 行（可能不存在）
     */
    public static function entryState(array $exam, ?array $score): array
    {
        $status    = (string) ($exam['exam_status'] ?? '');
        $stuStatus = (string) ($score['stu_status'] ?? '');
        $opens     = self::entryOpensAt($exam);
        $closes    = self::entryClosesAt($exam);
        $pwdReady  = self::isOpenForEntry($exam);
        $now       = time();
        $lead      = Setting::int('exam_entry_lead_minutes', 15);
        $late      = Setting::int('exam_entry_late_minutes', 0);

        $base = [
            'needs_pwd'          => true,
            'in_room'            => false,
            'entry_opens_at'     => $opens !== null ? date('Y-m-d H:i:s', $opens) : null,
            'entry_closes_at'    => $closes !== null ? date('Y-m-d H:i:s', $closes) : null,
            'entry_lead_minutes' => $lead,
            'entry_late_minutes' => $late,
            'entry_poll_seconds' => Setting::int('waiting_poll_seconds', 4),
            'pwd_ready'          => $pwdReady,
            'state'              => 'closed',
            'can_enter'          => false,
            'hint'               => '',
        ];
        // 统一在返回前补上服务端生成的状态说明，避免各前端各写一份文案
        $finish = static function (array $row) use ($lead, $late, $status): array {
            $row['hint'] = self::hintFor((string) $row['state'], $lead, $late, $status);
            return $row;
        };

        // 已交卷
        if ($stuStatus !== '' && str_starts_with($stuStatus, 'over')) {
            return $finish(array_merge($base, ['state' => 'submitted']));
        }
        // 整场已结束
        if (str_starts_with($status, 'over')) {
            return $finish(array_merge($base, ['state' => 'closed']));
        }

        $inRoom = in_array($stuStatus, ['online', 'locked'], true);
        $base['in_room'] = $inRoom;

        // 已开考：在场内可继续，场外不可再进（迟到宽限由 closes 决定）
        if ($status === self::STATUS_TESTING) {
            if ($inRoom) {
                return $finish(array_merge($base, ['state' => 'answering', 'can_enter' => true]));
            }
            if ($late > 0 && $closes !== null && $now < $closes && $pwdReady) {
                return $finish(array_merge($base, ['state' => 'open', 'can_enter' => true]));
            }
            return $finish(array_merge($base, ['state' => 'closed']));
        }

        // 未开考（exam / paper）
        if ($inRoom) {
            return $finish(array_merge($base, ['state' => 'in_room', 'can_enter' => true]));
        }
        if ($closes !== null && $now >= $closes) {
            return $finish(array_merge($base, ['state' => 'closed']));
        }
        if ($opens !== null && $now < $opens) {
            return $finish(array_merge($base, ['state' => 'upcoming']));
        }
        if (!$pwdReady) {
            return $finish(array_merge($base, ['state' => 'closed']));
        }
        return $finish(array_merge($base, ['state' => 'open', 'can_enter' => true]));
    }

    /**
     * 入场状态的服务端说明文案（单一来源，供各前端直接展示）。
     * @param string $status examinfo.exam_status（用于区分「未开放入场」与「已结束」）
     */
    private static function hintFor(string $state, int $lead, int $late, string $status): string
    {
        $lateNote = $late > 0 ? "，开考后 {$late} 分钟内仍可入场" : '，开考后不可入场';

        return match ($state) {
            'submitted' => '本场考试你已交卷。',
            'answering' => '考试进行中，可继续作答。',
            'in_room'   => '你已在考场内等待开考，开考后将自动进入答题界面。',
            'open'      => $status === self::STATUS_TESTING
                ? "考试已开始，仍可在开考后 {$late} 分钟内凭考场口令入场。"
                : ($lead > 0 ? "开考前 {$lead} 分钟内可凭考场口令入场" . $lateNote . '。'
                             : '已开放入场，可凭考场口令入场' . $lateNote . '。'),
            'upcoming'  => $lead > 0 ? "尚未到入场时间：开考前 {$lead} 分钟开放入场。" : '尚未开放入场。',
            default     => '当前不可入场，请留意监考教师通知。',
        };
    }

    /** 考生状态汇总：用于监控页与仪表盘 */
    public function statusSummary(int $examId): array
    {
        $row = \Core\Database::fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN stu_status = 'online' THEN 1 ELSE 0 END) AS online,
                    SUM(CASE WHEN stu_status = 'locked' THEN 1 ELSE 0 END) AS locked,
                    SUM(CASE WHEN stu_status = 'waiting' THEN 1 ELSE 0 END) AS waiting,
                    SUM(CASE WHEN LEFT(stu_status, 4) = 'over' THEN 1 ELSE 0 END) AS over_cnt
             FROM `stuscore` WHERE exam_id = ?",
            [$examId]
        );
        return [
            'total'   => (int) ($row['total'] ?? 0),
            'online'  => (int) ($row['online'] ?? 0),
            'locked'  => (int) ($row['locked'] ?? 0),
            'waiting' => (int) ($row['waiting'] ?? 0),
            'over'    => (int) ($row['over_cnt'] ?? 0),
        ];
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
