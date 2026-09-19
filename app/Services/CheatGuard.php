<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * 防作弊 —— 异常行为旁路记录
 *
 * 设计约束（与 Audit 一致）：旁路（bypass），任何写失败都不得影响主流程。
 * 记录切屏 / 窗口失焦 / 多端互踢 等异常，并累计考生异常次数（cheat_count）。
 *
 * 异常类型约定：
 *   tab_hidden    切屏 / 切换到其他标签页（visibilitychange → hidden）
 *   blur          窗口失焦（window.blur）
 *   other_device  被新登录踢出（多端互踢）
 */
final class CheatGuard
{
    public const TYPE_TAB_HIDDEN  = 'tab_hidden';
    public const TYPE_BLUR        = 'blur';
    public const TYPE_OTHER_DEVICE = 'other_device';

    /** 记录一次异常并累计次数（旁路） */
    public static function report(int $examId, string $stuId, string $type, string $detail = ''): void
    {
        try {
            Database::query(
                'INSERT INTO `cheat_event` (exam_id, stu_id, event_type, detail, created_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$examId, $stuId, $type, mb_substr($detail, 0, 500), date('Y-m-d H:i:s')]
            );
            Database::query(
                'UPDATE `stuscore` SET cheat_count = cheat_count + 1
                 WHERE exam_id = ? AND stu_id = ?',
                [$examId, $stuId]
            );
        } catch (\Throwable $e) {
            error_log('[CheatGuard] 记录异常失败: ' . $e->getMessage());
        }
    }

    /** 某场考试异常事件（可按考生过滤，时间倒序） */
    public static function events(int $examId, ?string $stuId = null, int $limit = 500): array
    {
        $where = ['exam_id = ?'];
        $params = [$examId];
        if ($stuId !== null && $stuId !== '') {
            $where[] = 'stu_id = ?';
            $params[] = $stuId;
        }
        $params[] = $limit;
        return Database::fetchAll(
            'SELECT id, exam_id, stu_id, event_type, detail, created_at
             FROM `cheat_event` WHERE ' . implode(' AND ', $where) . '
             ORDER BY id DESC LIMIT ?',
            $params
        );
    }

    /** 某场考试各考生异常次数（stu_id => count） */
    public static function countByExam(int $examId): array
    {
        $rows = Database::fetchAll(
            'SELECT stu_id, COUNT(*) AS c FROM `cheat_event` WHERE exam_id = ? GROUP BY stu_id',
            [$examId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['stu_id']] = (int) $r['c'];
        }
        return $out;
    }
}
