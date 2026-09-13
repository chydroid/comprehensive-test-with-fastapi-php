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
            "SELECT si.id AS stu_id, si.stu_name, si.grade_id, si.class_id, si.stu_sex,
                    ss.stu_score, ss.stu_status, ss.stu_pwd, ss.exam_id
             FROM `stuinfo` si
             INNER JOIN `stuscore` ss ON si.id = ss.stu_id
             WHERE ss.exam_id = ?
             ORDER BY {$column} {$direction}",
            [$examId]
        );
    }

    /** 更新单个考生状态 */
    public function updateStatus(int $examId, string $stuId, string $status): int
    {
        return Database::query(
            'UPDATE `stuscore` SET stu_status = ? WHERE exam_id = ? AND stu_id = ?',
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
