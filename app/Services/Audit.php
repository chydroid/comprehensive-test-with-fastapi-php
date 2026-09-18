<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * 系统操作审计
 *
 * 设计约束（务必遵守）：
 * - 审计是「旁路」，不是「业务」——任何写日志的失败都不得影响主流程，
 *   因此本类所有公开方法都用 try/catch 吞掉异常，最多 error_log。
 * - 不开启事务、不读写业务表，只写 `admin_log`。
 * - detail 统一以关联数组传入，内部 JSON 化；调用方不要自己 json_encode。
 *
 * 动作命名约定（命名空间式，便于按前缀过滤）：
 *   auth.login / auth.logout
 *   exam.create / exam.update / exam.delete / exam.open / exam.start / exam.generate
 *   monitor.submit / monitor.submit-one / monitor.lock / monitor.lock-all / monitor.over-all
 *   score.backup / score.delete
 *   settings.update
 *   quiz.create / quiz.update / quiz.delete / quiz.batch-delete / quiz.clean
 *   user.student.* / user.teacher.* / user.admin.*
 *   system.initialize / system.clear-exams
 */
class Audit
{
    public const TYPE_ADMIN   = 'admin';
    public const TYPE_TEACHER = 'teacher';
    public const TYPE_STUDENT = 'student';
    public const TYPE_SYSTEM  = 'system';

    /**
     * 记录一条审计日志。
     * @param string $action  动作类型，如 'exam.create'
     * @param string $target  操作对象，如 'exam:123'
     * @param array  $detail  结构化详情（会被 JSON 化）
     * @param array|null $actor 显式指定操作者；为空则取当前登录态
     */
    public static function log(string $action, string $target = '', array $detail = [], ?array $actor = null): void
    {
        try {
            $actor = $actor ?? self::currentActor();
            $detailJson = $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            Database::query(
                "INSERT INTO `admin_log`
                 (actor_type, actor_id, actor_name, action, target, detail, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $actor['type']  ?? self::TYPE_SYSTEM,
                    (string) ($actor['id']   ?? ''),
                    (string) ($actor['name'] ?? ''),
                    $action,
                    $target,
                    $detailJson,
                    self::clientIp(),
                    date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            // 审计失败绝不打断业务；仅留痕便于排查。
            error_log('[Audit] 写审计日志失败: ' . $e->getMessage());
        }
    }

    /**
     * 取最近若干条审计记录（支持过滤）。
     * @param int   $limit
     * @param array $filters ['action'=>prefix, 'actor_type'=>, 'actor_id'=>, 'keyword'=>, 'since'=>datetime, 'until'=>datetime]
     * @return array
     */
    public static function recent(int $limit = 50, array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['action'])) {
            $where[] = 'a.action LIKE ?';
            $params[] = (string) $filters['action'] . '%';
        }
        if (!empty($filters['actor_type'])) {
            $where[] = 'a.actor_type = ?';
            $params[] = (string) $filters['actor_type'];
        }
        if (!empty($filters['actor_id'])) {
            $where[] = 'a.actor_id = ?';
            $params[] = (string) $filters['actor_id'];
        }
        if (!empty($filters['keyword'])) {
            $kw = '%' . (string) $filters['keyword'] . '%';
            $where[] = '(a.target LIKE ? OR a.actor_name LIKE ? OR a.detail LIKE ?)';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        if (!empty($filters['since'])) {
            $where[] = 'a.created_at >= ?';
            $params[] = (string) $filters['since'];
        }
        if (!empty($filters['until'])) {
            $where[] = 'a.created_at <= ?';
            $params[] = (string) $filters['until'];
        }

        $sql = "SELECT id, actor_type, actor_id, actor_name, action, target, detail, ip, created_at
                FROM `admin_log` a";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ?';
        $params[] = $limit;

        return Database::fetchAll($sql, $params);
    }

    /** 取当前登录态作为操作者；无任何登录态时记为 system */
    private static function currentActor(): array
    {
        foreach ([AuthSession::ADMIN, AuthSession::TEACHER, AuthSession::STUDENT] as $type) {
            $u = AuthSession::get($type);
            if (is_array($u)) {
                return [
                    'type' => $type,
                    'id'   => (string) ($u['id'] ?? ''),
                    'name' => self::pickName($u),
                ];
            }
        }
        return ['type' => self::TYPE_SYSTEM, 'id' => '', 'name' => ''];
    }

    private static function pickName(array $u): string
    {
        return (string) ($u['username'] ?? $u['tea_name'] ?? $u['stu_name'] ?? $u['name'] ?? '');
    }

    private static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
