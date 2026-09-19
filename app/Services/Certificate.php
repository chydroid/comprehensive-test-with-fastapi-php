<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use Core\Database;

/**
 * 电子证书（C1）
 *
 * 设计要点
 * ---------
 * **惰性签发，不依赖定时任务。** 本项目没有常驻 cron，「考试结束 → 判分 → 发证」
 * 这条链上的前两步已由惰性流转完成（Exam::autoEndIfDue → ExamEngine::endExam）。
 * 发证同样挂在读路径上：考生打开「我的证书」时，把该场考试里所有**已结束且已达标、
 * 但尚未签发**的场次补签一遍，然后返回全部证书。
 *
 * **幂等靠唯一键，不靠「先查后插」的逻辑正确性。** certificate 上有
 * UNIQUE(exam_id, stu_id)，重复签发在数据库层被拒绝；捕获唯一键冲突后回查即可，
 * 因此并发双击页面不会产生两张证书，也不需要事务或行锁。
 *
 * **证书正文是快照。** 考试名称、科目、分数、达标线在签发时写入 certificate 行，
 * 不随源数据变动 —— 考试改名、题库清理、成绩备份都不该让一张已发出的证书变样。
 * 这也是「证书」与「成绩查询」的本质区别：前者是凭证，后者是视图。
 */
class Certificate
{
    /** 证书编号前缀 */
    public const PREFIX = 'CT';

    /**
     * 本场的达标分（0 = 本场不发放证书）。
     * 用绝对分而非得分率：发证是考务方按场次性质的自主决定，逐场填写最直观。
     */
    public static function thresholdOf(array $exam): int
    {
        return max(0, (int) ($exam['cert_threshold'] ?? 0));
    }

    /** 本场是否启用证书 */
    public static function isEnabled(array $exam): bool
    {
        return self::thresholdOf($exam) > 0;
    }

    /** 分数是否达标（未启用证书时恒 false） */
    public static function qualifies(array $exam, int $score): bool
    {
        $threshold = self::thresholdOf($exam);
        return $threshold > 0 && $score >= $threshold;
    }

    /* ------------------------------------------------------------------ */
    /* 签发                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 惰性幂等签发：已签发直接返回既有记录；不满足条件返回 null。
     *
     * 签发条件（缺一不可）：
     *   1. 考试已结束（exam_status 以 over 开头）—— 未结束的成绩还会变，不能发证；
     *   2. 该考生已交卷（stuscore.stu_status 以 over 开头）—— 未交卷者没有最终成绩；
     *   3. 本场启用了证书且分数达标。
     *
     * @return array|null certificate 行；不可签发时 null
     */
    public static function issue(int $examId, string $stuId): ?array
    {
        $stuId = trim($stuId);
        if ($examId <= 0 || $stuId === '') {
            return null;
        }

        $existing = self::findOne($examId, $stuId);
        if ($existing !== null) {
            return $existing;
        }

        $exam = (new Exam())->find($examId);
        if ($exam === null || !self::isEnabled($exam)) {
            return null;
        }
        if (!str_starts_with((string) ($exam['exam_status'] ?? ''), 'over')) {
            return null;
        }

        $score = Database::fetch(
            'SELECT stu_score, stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        if ($score === null) {
            return null;
        }
        if (!str_starts_with((string) ($score['stu_status'] ?? ''), 'over')) {
            return null;
        }
        $got = (int) ($score['stu_score'] ?? 0);
        if (!self::qualifies($exam, $got)) {
            return null;
        }

        $stu = Database::fetch('SELECT stu_name FROM `stuinfo` WHERE id = ?', [$stuId]);
        $subj = (int) ($exam['subj_id'] ?? 0) > 0
            ? Database::fetch('SELECT subj_name FROM `subject` WHERE id = ?', [(int) $exam['subj_id']])
            : null;

        $row = [
            'exam_id'     => $examId,
            'stu_id'      => $stuId,
            'stu_name'    => (string) ($stu['stu_name'] ?? $stuId),
            'exam_name'   => (string) ($exam['exam_name'] ?? ''),
            'subj_name'   => (string) ($subj['subj_name'] ?? ''),
            'score'       => $got,
            'total_score' => (int) ($exam['exam_score'] ?? 0),
            'threshold'   => self::thresholdOf($exam),
            'cert_no'     => self::newCertNo(),
            'issued_at'   => date('Y-m-d H:i:s'),
        ];

        try {
            (new \App\Models\Certificate())->create($row);
        } catch (\Throwable $e) {
            // 唯一键冲突 = 并发下已被另一次请求签发，回查直接返回既有记录。
            // 其余异常（如 cert_no 碰撞）重试一次编号后仍失败则放弃 —— 发证失败
            // 不该让「我的证书」整页报错，下一次访问会再试。
            error_log('[certificate] issue failed: ' . $e->getMessage());
            return self::findOne($examId, $stuId);
        }

        return self::findOne($examId, $stuId);
    }

    /**
     * 我的证书：先把新达标的场次补签，再返回全部证书（按签发时间倒序）。
     */
    public static function forStudent(string $stuId): array
    {
        $stuId = trim($stuId);
        if ($stuId === '') {
            return [];
        }
        // 候选：该考生已交卷、且所在考试已结束并启用了证书 —— 一次查完，
        // 不在循环里逐场读考试表（避免 N+1）。
        $candidates = Database::fetchAll(
            "SELECT e.id AS exam_id, sc.stu_score
             FROM `stuscore` sc
             INNER JOIN `examinfo` e ON e.id = sc.exam_id
             WHERE sc.stu_id = ?
               AND sc.stu_status LIKE 'over%'
               AND LEFT(e.exam_status, 4) = 'over'
               AND e.cert_threshold > 0
               AND e.cert_threshold <= sc.stu_score
             ORDER BY e.id DESC",
            [$stuId]
        );
        foreach ($candidates as $c) {
            self::issue((int) $c['exam_id'], $stuId);
        }

        return Database::fetchAll(
            'SELECT * FROM `certificate` WHERE stu_id = ? ORDER BY issued_at DESC, id DESC',
            [$stuId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* 查询与核验                                                          */
    /* ------------------------------------------------------------------ */

    public static function findOne(int $examId, string $stuId): ?array
    {
        return Database::fetch(
            'SELECT * FROM `certificate` WHERE exam_id = ? AND stu_id = ?',
            [$examId, trim($stuId)]
        );
    }

    public static function findByNo(string $certNo): ?array
    {
        $certNo = strtoupper(trim($certNo));
        if ($certNo === '') {
            return null;
        }
        return Database::fetch('SELECT * FROM `certificate` WHERE cert_no = ?', [$certNo]);
    }

    /**
     * 公开核验：只需要证书编号。
     *
     * 编号带 32 位随机段（见 newCertNo），不可枚举；因此「知道编号」本身就等价于
     * 「持有证书」，无需额外的验证码。返回的姓名按 ScoreController 同一套规则脱敏
     * （首尾保留、中间打星），既能核实「这张证是不是我的」，又不至于把持证人
     * 全名公开在互联网上 —— 证书编号可能出现在公示、合影、二手教材等场合。
     *
     * @return array{valid:bool, certificate:array|null}
     */
    public static function verify(string $certNo): array
    {
        $row = self::findByNo($certNo);
        if ($row === null) {
            return ['valid' => false, 'certificate' => null];
        }
        return [
            'valid' => true,
            'certificate' => [
                'cert_no'     => (string) $row['cert_no'],
                'stu_name'    => self::maskName((string) $row['stu_name']),
                'exam_name'   => (string) $row['exam_name'],
                'subj_name'   => (string) $row['subj_name'],
                'score'       => (int) $row['score'],
                'total_score' => (int) $row['total_score'],
                'threshold'   => (int) $row['threshold'],
                'issued_at'   => (string) $row['issued_at'],
            ],
        ];
    }

    /** 与 ScoreController::maskName 同一套规则（首尾保留、中间打星） */
    public static function maskName(string $name): string
    {
        $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($len <= 1) {
            return $name;
        }
        $first = mb_substr($name, 0, 1);
        $last = mb_substr($name, -1, 1);
        if ($len === 2) {
            return $first . '*';
        }
        return $first . str_repeat('*', $len - 2) . $last;
    }

    /**
     * 证书编号：CT + 8 位日期 + 32 位随机十六进制。
     *
     * 刻意**不做**「考试号+准考证号」这类可推导编号：那样核验接口等于把
     * 「写个脚本遍历准考证号即可确认某人是否通过某场考试」变成可能。
     * 随机段保证编号不可枚举，核验接口的暴露面就只剩「编号持有者主动出示」。
     */
    public static function newCertNo(): string
    {
        return self::PREFIX . date('Ymd') . strtoupper(bin2hex(random_bytes(16)));
    }
}
