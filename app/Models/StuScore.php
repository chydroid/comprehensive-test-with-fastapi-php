<?php
declare(strict_types=1);

namespace App\Models;

use Core\Database;
use Core\Model;

/**
 * 成绩（stuscore）
 *
 * stu_status 取值（沿用旧库字符串语义）：
 *   waiting  等待（已排卷，未进考场）
 *   online   在线答题中
 *   locked   已锁定（监考锁定，禁止继续作答）
 *   over     已交卷（可为 over / over:时间戳 等带后缀形式）
 */
class StuScore extends Model
{
    protected string $table = 'stuscore';
    protected bool $timestamps = false;

    protected array $fillable = [
        'exam_id', 'stu_id', 'stu_score', 'stu_status', 'stu_pwd',
        'cheat_count', 'exam_token',
    ];

    public const STATUS_UNSUBMITTED = '0';
    public const STATUS_SUBMITTED   = '1';

    /** 排序白名单：前端字段名 → 真实列（防 SQL 注入） */
    private const ORDER_COLUMNS = [
        'stuid'     => 'si.id',
        'stuname'   => 'si.stu_name',
        'gradename' => 'si.grade_id',
        'classname' => 'si.class_id',
        'stuscore'  => 'ss.stu_score',
        'status'    => 'ss.stu_status',
    ];

    public function findOne(int $examId, string $stuId): ?array
    {
        return $this->firstWhere(['exam_id' => $examId, 'stu_id' => $stuId]);
    }

    /** 写入本次考场登录的设备令牌（多端互踢） */
    public function setToken(int $examId, string $stuId, string $token): int
    {
        return Database::query(
            'UPDATE `stuscore` SET exam_token = ? WHERE exam_id = ? AND stu_id = ?',
            [$token, $examId, $stuId]
        )->rowCount();
    }

    /** 读取设备令牌；无记录返回 null */
    public function tokenOf(int $examId, string $stuId): ?string
    {
        $row = Database::fetch(
            'SELECT exam_token FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        return $row === null ? null : (string) $row['exam_token'];
    }

    /** 清空设备令牌（登出考场时调用） */
    public function clearToken(int $examId, string $stuId): int
    {
        return Database::query(
            'UPDATE `stuscore` SET exam_token = \'\' WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        )->rowCount();
    }

    /**
     * 某场考试的考生成绩名单（含考生资料）。
     * @param string $orderBy 前端排序字段（白名单外的值回退到准考证号）
     * @param string $order   asc / desc
     */
    public function byExam(int $examId, string $orderBy = 'stuid', string $order = 'asc'): array
    {
        $column = self::ORDER_COLUMNS[$orderBy] ?? 'si.id';
        $direction = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

        return Database::fetchAll(
            // 不再 SELECT ss.stu_pwd：考场口令是入场凭证，成绩查看权限（score.view）
            // 不应顺带拿到它。需要口令的 CSV 导出由 InvigilationService::csv($withPwd)
            // 单独从 examinfo.exam_pwd 取。
            "SELECT si.id AS stu_id, si.stu_name, si.grade_id, si.class_id, si.stu_sex,
                    ss.stu_score, ss.stu_status, ss.exam_id, ss.cheat_count
             FROM `stuinfo` si
             INNER JOIN `stuscore` ss ON si.id = ss.stu_id
             WHERE ss.exam_id = ?
             ORDER BY {$column} {$direction}",
            [$examId]
        );
    }

    /**
     * 更新单个考生状态。
     *
     * 默认拒绝从「已交卷」（over / overBak）迁出：此前单条版没有任何守卫，
     * 而批量版 lockAll/unlockAll 有，导致监考对已交卷考生点「解锁」就能把
     * 状态从 over 改回 online —— 连锁击穿登录拦截、答题拦截，再交卷时
     * autoGrade 会覆盖已封存的成绩。
     *
     * @param bool $allowFromSubmitted 是否允许修改已交卷记录（仅管理员显式回收场景使用）
     */
    public function updateStatus(int $examId, string $stuId, string $status, bool $allowFromSubmitted = false): int
    {
        $guard = $allowFromSubmitted ? '' : " AND LEFT(stu_status, 4) != 'over'";
        return Database::query(
            'UPDATE `stuscore` SET stu_status = ? WHERE exam_id = ? AND stu_id = ?' . $guard,
            [$status, $examId, $stuId]
        )->rowCount();
    }

    /** 批量锁定（跳过已交卷的） */
    public function lockAll(int $examId): int
    {
        return Database::query(
            "UPDATE `stuscore` SET stu_status = 'locked'
             WHERE exam_id = ? AND LEFT(stu_status, 4) != 'over'",
            [$examId]
        )->rowCount();
    }

    /** 批量解锁（仅锁定态可解锁） */
    public function unlockAll(int $examId): int
    {
        return Database::query(
            "UPDATE `stuscore` SET stu_status = 'online'
             WHERE exam_id = ? AND stu_status = 'locked'",
            [$examId]
        )->rowCount();
    }

    /** 该场考试是否已备份（考试状态为 overBak） */
    public function isBackedUp(int $examId): bool
    {
        $row = Database::fetch('SELECT exam_status FROM `examinfo` WHERE id = ?', [$examId]);
        return $row !== null && (string) $row['exam_status'] === 'overBak';
    }

    /**
     * 备份成绩到 stuscorebak，并把考试状态置为 overBak。
     * @return int 备份行数
     */
    public function backup(int $examId): int
    {
        $inserted = Database::query(
            'INSERT INTO `stuscorebak` (stu_id, stu_name, grade_id, stu_score, exam_id, backup_time)
             SELECT si.id, si.stu_name, si.grade_id, ss.stu_score, ss.exam_id, NOW()
             FROM `stuinfo` si
             INNER JOIN `stuscore` ss ON si.id = ss.stu_id
             WHERE ss.exam_id = ?',
            [$examId]
        )->rowCount();

        Database::query("UPDATE `examinfo` SET exam_status = 'overBak' WHERE id = ?", [$examId]);
        return $inserted;
    }

    /** 各场已结束考试的统计（仪表盘用） */
    public function examStats(int $limit = 20): array
    {
        return Database::fetchAll(
            "SELECT e.id, e.exam_name, e.exam_status, s.subj_name,
                    COUNT(ss.stu_id) AS stu_count,
                    ROUND(AVG(ss.stu_score), 1) AS avg_score,
                    MAX(ss.stu_score) AS max_score,
                    MIN(ss.stu_score) AS min_score
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             LEFT JOIN `stuscore` ss ON ss.exam_id = e.id
             WHERE LEFT(e.exam_status, 4) = 'over'
             GROUP BY e.id, e.exam_name, e.exam_status, s.subj_name
             ORDER BY e.id DESC
             LIMIT " . max(1, $limit)
        );
    }
}
