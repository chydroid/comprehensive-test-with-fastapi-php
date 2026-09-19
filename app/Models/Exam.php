<?php
declare(strict_types=1);

namespace App\Models;

use App\Services\ExamEngine;
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
        'exam_start', 'exam_end', 'exam_tea', 'stu_class', 'paper_mode',
        'radio1_easy_sum', 'radio1_mid_sum', 'radio1_hard_sum', 'radio1_val',
        'radio2_easy_sum', 'radio2_mid_sum', 'radio2_hard_sum', 'radio2_val',
        'checkbox_easy_sum', 'checkbox_mid_sum', 'checkbox_hard_sum', 'checkbox_val',
        'text_easy_sum', 'text_mid_sum', 'text_hard_sum', 'text_val',
        'exam_status', 'exam_pwd', 'exam_score',
    ];

    /** 组卷模式（A3 组卷多样化） */
    public const PAPER_MODES = ['random', 'manual', 'by_kp'];
    public const PAPER_MODE_LABELS = [
        'random' => '按难度随机',
        'manual' => '手动选题',
        'by_kp'  => '按知识点比例',
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
     * 「考场尚未到期」SQL 谓词（字段一律相对别名 e，调用方的表必须取别名 e）。
     *
     * exam_end 是本场考试计划的结束时间，一旦过去，这场考试在业务上就已经结束。
     *
     * 没有这个谓词时，任何一场**忘记人工点「结束」**的考场都会永远停在
     * exam_status = 'testing'；而「是否进行中」的判定此前只看状态、不看时间，
     * 于是一场早已结束的考场会：
     *   ① 让全站练习 / 模拟考试被永久冻结（hasOngoingFormalExam 恒真）；
     *   ② 让门户「进行中的考试」常驻一条幽灵考试（activeWithSubject）；
     *   ③ 把它名单里的考生永久钉死在「考生在考」硬约束上（isStudentInExam）。
     *
     * 「未配置」的 exam_end（NULL / 空串 / 零值日期）一律视为不过期，
     * 避免历史脏数据把考场整体判死。
     */
    public const SQL_NOT_EXPIRED = "(e.exam_end IS NULL OR e.exam_end = ''"
        . " OR e.exam_end = '0000-00-00 00:00:00' OR e.exam_end > NOW())";

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
            "SELECT COUNT(*) AS c FROM `examinfo` e
             WHERE e.exam_status = ? AND COALESCE(e.exam_class, '') <> ?
               AND " . self::SQL_NOT_EXPIRED,
            [self::STATUS_TESTING, self::MOCK_CLASS]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }

    /* ==================================================================
     * 模拟考试 / 在线练习 ↔ 正式考试 的隔离策略
     *
     * 分两层，缺一不可：
     *   ① **全局层**：存在进行中的正式考试时，默认暂停练习与模拟（后台
     *      「系统设置 → 模拟考试与练习」的开关可放开，用于允许非考生
     *      继续练习的场景）。默认值必须与 Setting::SCHEMA 一致。
     *   ② **个人层（硬约束）**：考生本人正在参加正式考试时（已入场且考试
     *      进行中），无论全局开关如何设置都不得练习/模拟——这一层不可配置，
     *      因为「同一人一边答题一边用练习反查答案」是纪律红线，不是取舍。
     * ================================================================== */

    /** 暂停原因：全局开考期间暂停 */
    public const PAUSE_EXAM_ONGOING = 'exam_ongoing';

    /** 暂停原因：考生本人正在考场内（个人硬约束，开关不可放开） */
    public const PAUSE_SELF_IN_EXAM = 'self_in_exam';

    /** 正式考试进行中是否允许在线练习（默认**不允许**） */
    public static function allowExerciseDuringExam(): bool
    {
        return Setting::bool('exercise_allow_during_exam', false);
    }

    /** 正式考试进行中是否允许模拟考试（默认**不允许**） */
    public static function allowMockDuringExam(): bool
    {
        return Setting::bool('mock_allow_during_exam', false);
    }

    /**
     * 指定考生本人是否「正在参加正式考试」。
     *
     * 判定条件（三者同时满足）：
     *   1. 该考试不是模拟考试（模拟同样以 exam_status='testing' 落库）；
     *   2. 考试处于进行中（exam_status = 'testing'）——与
     *      hasOngoingFormalExam() 同口径，避免引入第二套「进行中」定义；
     *   3. 该考生在此场的 stuscore.stu_status ∈ {online, locked}
     *      ——online 由入场（ExamController::login）与答题心跳写入，
     *      locked 为监考锁定，二者都表示「人在考场内」。
     *
     * 为什么必须要求「已入场」而不是「已排卷」：出题（generateForClass）
     * 会在入场前很久就为全班写入 stuscore（stu_status='waiting'）。若把
     * waiting 也算作在考，考生在考试开始前的整个备考期都会被禁止练习，
     * 而那时试卷尚未对考生可见、根本不存在泄露面。
     *
     * 已交卷（over 前缀）者不算在考：他已完成本场考试，可以自由练习。
     * 考场已过 exam_end 也不算：那是「考试时间到」，不是「考生在作答」；
     * 否则一场忘了结束的考场会让在场考生永久无法练习（见 SQL_NOT_EXPIRED）。
     */
    public static function isStudentInExam(string $stuId): bool
    {
        $stuId = trim($stuId);
        if ($stuId === '') {
            return false;
        }
        $row = \Core\Database::fetch(
            "SELECT COUNT(*) AS c
             FROM `examinfo` e
             INNER JOIN `stuscore` sc ON sc.exam_id = e.id
             WHERE COALESCE(e.exam_class, '') <> ?
               AND e.exam_status = ?
               AND sc.stu_id = ?
               AND sc.stu_status IN ('online', 'locked')
               AND " . self::SQL_NOT_EXPIRED,
            [self::MOCK_CLASS, self::STATUS_TESTING, $stuId]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }

    /**
     * 在线练习对指定考生的暂停结论。
     * @return array{paused:bool,reason:string}
     */
    public static function exercisePause(string $stuId): array
    {
        return self::pauseState($stuId, self::allowExerciseDuringExam());
    }

    /**
     * 模拟考试对指定考生的暂停结论。
     * @return array{paused:bool,reason:string}
     */
    public static function mockPause(string $stuId): array
    {
        return self::pauseState($stuId, self::allowMockDuringExam());
    }

    /**
     * 暂停决策的唯一出处：个人层优先于全局层。
     *
     * 顺序不可颠倒 —— 反过来的话，管理员一开启开关，正在答题的考生就会
     * 拿到 reason='exam_ongoing' 之外的空结论而被放行（个人红线失效）。
     *
     * @return array{paused:bool,reason:string}
     */
    private static function pauseState(string $stuId, bool $globalAllowed): array
    {
        if (self::isStudentInExam($stuId)) {
            return ['paused' => true, 'reason' => self::PAUSE_SELF_IN_EXAM];
        }
        if (!$globalAllowed && self::hasOngoingFormalExam()) {
            return ['paused' => true, 'reason' => self::PAUSE_EXAM_ONGOING];
        }
        return ['paused' => false, 'reason' => ''];
    }

    /** 每人每日模拟考试场次上限（后台可配，默认 5） */
    public static function mockDailyLimit(): int
    {
        return max(1, Setting::int('mock_daily_limit', 5));
    }

    /** 单场模拟考试题目总数上限（后台可配，默认 100） */
    public static function mockMaxQuestions(): int
    {
        return max(1, Setting::int('mock_max_questions', 100));
    }

    /**
     * 某考生「今天」已发起的模拟考试场次。
     *
     * 以 examinfo.exam_start 为发起时刻（ExamEngine::createPracticeExam 落 NOW()），
     * 统计**含未交卷**的场次——只统计已交卷会留下「无限组卷不交卷即可批量取题」
     * 的口子，与设置项「每人每日模拟考试场次上限」的初衷相悖。
     *
     * 时间条件写成左闭右开区间而非 DATE(exam_start) = CURDATE()，
     * 以便将来在 exam_start 上建索引时不因函数包裹而失效。
     */
    public static function mockUsedToday(string $stuId): int
    {
        $stuId = trim($stuId);
        if ($stuId === '') {
            return 0;
        }
        $row = \Core\Database::fetch(
            "SELECT COUNT(*) AS c
             FROM `examinfo` e
             INNER JOIN `stuscore` sc ON sc.exam_id = e.id
             WHERE e.exam_class = ? AND sc.stu_id = ?
               AND e.exam_start >= ? AND e.exam_start < ?",
            [
                self::MOCK_CLASS,
                $stuId,
                date('Y-m-d 00:00:00'),
                date('Y-m-d 00:00:00', strtotime('+1 day')),
            ]
        );
        return (int) ($row['c'] ?? 0);
    }

    /** 进行中的考试（含科目名/类别名），供前台门户展示 */
    public static function activeWithSubject(): array
    {
        // 必须排除模拟考试：它同样以 exam_status='testing' 落库，
        // 否则考生一开模拟，门户/仪表盘的「进行中的考试」就会多出一条假考试。
        // 同时必须排除已过 exam_end 的考场，否则一场忘了结束的考试会让门户
        // 永远挂着一条「进行中」（BUG-251）。
        return \Core\Database::fetchAll(
            "SELECT e.id, e.exam_name, e.exam_class, e.exam_start, e.exam_end, e.exam_status,
                    e.exam_score, e.subj_id, s.subj_name, c.category_name
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             LEFT JOIN `exam_category` c ON c.id = e.exam_category_id
             WHERE e.exam_status = 'testing'
               AND COALESCE(e.exam_class, '') <> ?
               AND " . self::SQL_NOT_EXPIRED . "
             ORDER BY e.id DESC",
            [self::MOCK_CLASS]
        );
    }

    /**
     * 管理端考试列表（支持按科目/状态/关键字过滤 + 分页）。
     * @param array{subj_id?:int,status?:string,keyword?:string} $filters
     * @return array{data:array,total:int}
     */
    public function adminList(array $filters, int $offset, int $perPage): array
    {
        // 模拟考试不属于「考试管理」范畴（考生自主生成、无监考意义），
        // 必须整体排除，否则管理端列表、监考选择页都会被考生的模拟记录刷屏。
        $where = ["COALESCE(e.exam_class, '') <> ?"];
        $params = [self::MOCK_CLASS];
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
            $kw = '%' . addcslashes((string) $filters['keyword'], '%_\\') . '%';
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

    /**
     * 已结束的正式考试（排除模拟考试），供成绩模块选择。
     *
     * @param string|null $teaName 传入监考教师姓名时，只返回该教师名下的考试。
     *   教师端成绩查询此前用无参版本，导致下拉里出现**其他教师**的考试，
     *   选中后 assertOwnExam 直接 404（且前端静默失败成空白页），
     *   同时也把他人考试的名称泄露给了无权限的教师。
     */
    public function finishedList(?string $teaName = null): array
    {
        $sql = "SELECT e.id, e.exam_name, e.exam_status, e.exam_score, s.subj_name
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             WHERE LEFT(e.exam_status, 4) = 'over'
               AND COALESCE(e.exam_class, '') <> ?";
        $params = [self::MOCK_CLASS];
        if ($teaName !== null && $teaName !== '') {
            $sql .= ' AND e.exam_tea = ?';
            $params[] = $teaName;
        }
        $sql .= ' ORDER BY e.id DESC';
        return \Core\Database::fetchAll($sql, $params);
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
     * 惰性自动结束：进行中(testing) 且已过 exam_end → 整场结束(over)。
     *
     * 与 autoStartIfDue() 完全对称 —— 本系统无常驻定时任务，故在考生轮询 /
     * 监考访问时顺带触发（幂等）。
     *
     * 为什么必须有它：exam_end 到点后，系统此前只做了「当前考生超时强制交卷」，
     * 而**从不推进整场状态**。于是考场永远停在 testing，带来三个后果：
     *   ① 全站练习 / 模拟考试被 hasOngoingFormalExam() 永久冻结；
     *   ② 门户「进行中的考试」常驻一条幽灵考试；
     *   ③ 该场考生被 isStudentInExam() 永久钉死在「考生在考」硬约束上。
     * 读取侧已用 SQL_NOT_EXPIRED 兜住①②③不再发生，这里负责让状态**最终收敛**，
     * 使管理端 / 教师端列表与成绩模块都能看到真实的「已结束」。
     *
     * endExam() 自带事务且幂等（已交卷者跳过判分），重复调用安全。
     *
     * @return bool 本次是否发生了状态推进
     */
    public static function autoEndIfDue(int $examId): bool
    {
        if ($examId <= 0) {
            return false;
        }
        // 先用一条廉价的 COUNT 判断，避免在每次答题轮询上都做一次完整 find。
        $row = \Core\Database::fetch(
            "SELECT COUNT(*) AS c FROM `examinfo` e
             WHERE e.id = ? AND e.exam_status = ?
               AND e.exam_end IS NOT NULL AND e.exam_end <> ''
               AND e.exam_end <> '0000-00-00 00:00:00'
               AND e.exam_end <= NOW()",
            [$examId, self::STATUS_TESTING]
        );
        if ((int) ($row['c'] ?? 0) === 0) {
            return false;
        }
        ExamEngine::endExam($examId);
        return true;    }

    /**
     * 开放入场：生成符合后台配置位数的考场口令，状态保持「未开考」。
     * 开放入场后考生才能凭口令在入场窗口内进场。
     * @return string 新的考场口令
     */
    public function openForEntry(int $id): string
    {
        $pwd = self::generatePwd();
        // 同 start()：exam_pwd 与 stuscore.stu_pwd 快照必须一起生效，否则考生
        // 拿新口令入场、而续考校验用的旧快照会判定口令错误。
        $ownTx = !\Core\Database::inTransaction();
        if ($ownTx) {
            \Core\Database::beginTransaction();
        }
        try {
            \Core\Database::query(
                "UPDATE `examinfo` SET exam_pwd = ? WHERE id = ?",
                [$pwd, $id]
            );
            \Core\Database::query(
                "UPDATE `stuscore` SET stu_pwd = ? WHERE exam_id = ?",
                [$pwd, $id]
            );
            if ($ownTx) {
                \Core\Database::commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && \Core\Database::inTransaction()) {
                \Core\Database::rollBack();
            }
            throw $e;
        }
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

    /**
     * 考生班级是否属于本场考试的参考班级。
     *
     * 与 pendingForStudent() 的 `FIND_IN_SET(class_id, exam.stu_class)` 同口径。
     * 考场入口此前**完全不校验**班级归属，任意已注册考生（注册接口是公开的）
     * 只要猜中 4–10 位纯数字口令，就能进入他人班级的考试：createScore() 会为其
     * 写入名单、污染监考名单与成绩单，同时回传该场考试信息。
     *
     * 若考试未配置参考班级、或考生本人未填写班级，则无从判定，返回 true 不做限制，
     * 以免历史数据把考生整体挡在考场之外。
     */
    public static function isStudentEligible(array $exam, string $classId): bool
    {
        $scope = trim((string) ($exam['stu_class'] ?? ''));
        $classId = trim($classId);
        if ($scope === '' || $classId === '') {
            return true;
        }
        $set = array_filter(array_map('trim', explode(',', $scope)), static fn ($v) => $v !== '');
        return in_array($classId, $set, true);
    }

    /**
     * 已登录考生的待考考试（按班级匹配 + 交卷状态）。
     *
     * 已过 exam_end 的考场不再计入「待考」：考试窗口已关闭，考生进去也只会
     * 看到「不可入场」，留着它只是让列表里躺着一条永远点不动的死条目。
     * 管理端 / 教师端的考试列表**不受**此过滤影响，教师仍需看到并清理它。
     */
    public static function pendingForStudent(string $stuId, string $classId): array
    {        $stuId = trim($stuId);
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
               AND COALESCE(e.exam_class, '') <> ?
               AND FIND_IN_SET(?, e.stu_class) > 0
               AND " . self::SQL_NOT_EXPIRED . "
             ORDER BY e.exam_start ASC, e.id DESC",
            [self::MOCK_CLASS, $stuId, $classId]
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

    /**
     * 组卷模式相关信息（A3 组卷多样化）。
     * 返回 mode +（manual 的 manual_ids 列表 / by_kp 的 kp_plan 列表）。
     * 优先读传入的 _manual_ids / _kp_plan（创建/编辑时前端提交），
     * 否则从持久化表读取（详情展示时）。
     */
    public static function paperMode(array $exam): array
    {
        $mode = (string) ($exam['paper_mode'] ?? 'random');
        $out = ['mode' => $mode];
        if ($mode === 'manual') {
            if (isset($exam['_manual_ids'])) {
                $out['manual_ids'] = array_values(array_filter(
                    array_map('intval', (array) $exam['_manual_ids']),
                    static fn (int $i): bool => $i > 0
                ));
            } elseif (!empty($exam['id'])) {
                $rows = \Core\Database::fetchAll(
                    'SELECT quiz_id FROM `exam_manual_quiz` WHERE exam_id = ? ORDER BY sort, quiz_id',
                    [(int) $exam['id']]
                );
                $out['manual_ids'] = array_map(static fn (array $r): int => (int) $r['quiz_id'], $rows);
            } else {
                $out['manual_ids'] = [];
            }
        } elseif ($mode === 'by_kp') {
            if (isset($exam['_kp_plan'])) {
                $out['kp_plan'] = array_values(array_map(static function (array $p): array {
                    return [
                        'kp'   => (string) ($p['kp'] ?? ''),
                        'diff' => (string) ($p['diff'] ?? ''),
                        'cnt'  => (int) ($p['cnt'] ?? 0),
                    ];
                }, (array) $exam['_kp_plan']));
            } elseif (!empty($exam['id'])) {
                $rows = \Core\Database::fetchAll(
                    'SELECT kp, diff, cnt, sort FROM `exam_kp_plan` WHERE exam_id = ? ORDER BY sort, kp, diff',
                    [(int) $exam['id']]
                );
                $out['kp_plan'] = array_map(static fn (array $r): array => [
                    'kp'   => (string) $r['kp'],
                    'diff' => (string) $r['diff'],
                    'cnt'  => (int) $r['cnt'],
                ], $rows);
            } else {
                $out['kp_plan'] = [];
            }
        }
        return $out;
    }

    /** 该场考试应出题总数（按当前组卷模式计算） */
    public static function totalQuestions(array $exam): int
    {
        $mode = (string) ($exam['paper_mode'] ?? 'random');
        if ($mode === 'manual') {
            if (isset($exam['_manual_ids'])) {
                return count(array_filter(array_map('intval', (array) $exam['_manual_ids']), static fn (int $i): bool => $i > 0));
            }
            if (!empty($exam['id'])) {
                $r = \Core\Database::fetch('SELECT COUNT(*) AS c FROM `exam_manual_quiz` WHERE exam_id = ?', [(int) $exam['id']]);
                return (int) ($r['c'] ?? 0);
            }
            return 0;
        }
        if ($mode === 'by_kp') {
            if (isset($exam['_kp_plan'])) {
                $n = 0;
                foreach ((array) $exam['_kp_plan'] as $p) {
                    $n += (int) ($p['cnt'] ?? 0);
                }
                return $n;
            }
            if (!empty($exam['id'])) {
                $r = \Core\Database::fetch('SELECT COALESCE(SUM(cnt),0) AS c FROM `exam_kp_plan` WHERE exam_id = ?', [(int) $exam['id']]);
                return (int) ($r['c'] ?? 0);
            }
            return 0;
        }
        // random：题型×难度计数列求和
        $n = 0;
        foreach (self::paperPlan($exam) as $p) {
            $n += $p['easy'] + $p['mid'] + $p['hard'];
        }
        return $n;
    }

    /**
     * 按组卷参数推算的满分。
     * - random：题型×难度计数列 × 每题分值（确定值）。
     * - manual：按所选题目实际题型 × 每题分值求和（从 _manual_ids 或持久化表取题型）。
     * - by_kp：实际分值取决于随机抽到的题目题型，组卷时才能确定；若已落库 exam_score 则直接用它，
     *          否则返回 0，由 ExamEngine::generatePaper 在首次出题时回填 exam_score。
     */
    public static function computedTotalScore(array $exam): int
    {
        $mode = (string) ($exam['paper_mode'] ?? 'random');
        $valMap = [];
        foreach (self::TYPE_PREFIXES as $t) {
            $valMap[$t] = (int) ($exam["{$t}_val"] ?? 0);
        }
        if ($mode === 'manual') {
            $types = [];
            if (isset($exam['_manual_ids'])) {
                $ids = array_filter(array_map('intval', (array) $exam['_manual_ids']), static fn (int $i): bool => $i > 0);
                if ($ids !== []) {
                    $ph = implode(',', $ids);
                    $rows = \Core\Database::fetchAll("SELECT quiz_class FROM `quizlib` WHERE id IN ({$ph})");
                    foreach ($rows as $r) {
                        $types[] = (string) $r['quiz_class'];
                    }
                }
            } elseif (!empty($exam['id'])) {
                $rows = \Core\Database::fetchAll(
                    'SELECT q.quiz_class FROM `exam_manual_quiz` m INNER JOIN `quizlib` q ON q.id = m.quiz_id WHERE m.exam_id = ?',
                    [(int) $exam['id']]
                );
                foreach ($rows as $r) {
                    $types[] = (string) $r['quiz_class'];
                }
            }
            $s = 0;
            foreach ($types as $t) {
                $s += $valMap[$t] ?? 0;
            }
            return $s;
        }
        if ($mode === 'by_kp') {
            if (!empty($exam['exam_score'])) {
                return (int) $exam['exam_score'];
            }
            return 0;
        }
        // random
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
