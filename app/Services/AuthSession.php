<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 登录态服务：统一三套身份（admin/student/teacher）的建立与销毁。
 *
 * 安全要点：
 * - 登录成功即 regenerate 会话 ID，防会话固定攻击（P0-1 修复配套）
 * - 登录成功即生成并下发 csrf_token，供前端放入 X-CSRF-Token 请求头
 * - 登出销毁对应登录态并清理令牌
 */
final class AuthSession
{
    public const ADMIN = 'admin';
    public const STUDENT = 'student';
    public const TEACHER = 'teacher';
    /** 考场会话（由 ExamController 建立，与会话键名一致） */
    public const EXAM = 'exam_session';

    /**
     * 建立登录态
     * @param array $identity ['id'=>..,'name'=>.., + 角色等附加字段]
     */
    public static function login(string $type, array $identity): void
    {
        // 防会话固定：换发会话 ID，丢弃旧会话数据之外的身份残留
        self::purge();
        sess_regenerate();

        sess_set($type, $identity);
        sess_set('csrf_token', bin2hex(random_bytes(32)));
        sess_set('login_at', time());
    }

    /** 当前登录的身份类型（优先级 admin > student > teacher），未登录返回 null */
    public static function current(): ?string
    {
        foreach ([self::ADMIN, self::STUDENT, self::TEACHER] as $type) {            $s = sess_get($type);
            if (is_array($s) && isset($s['id'])) {
                return $type;
            }
        }
        return null;
    }

    public static function get(string $type): ?array
    {
        $s = sess_get($type);
        return is_array($s) && isset($s['id']) ? $s : null;
    }

    /** 清除指定身份；不传则清除全部三种身份 */
    public static function logout(?string $type = null): void
    {
        if ($type === null) {
            self::purge();
            sess_destroy();
            return;
        }
        sess_forget($type);
        // 清空所有登录态后一并清理 CSRF 令牌
        if (self::current() === null) {
            sess_forget('csrf_token');
            sess_forget('login_at');
        }
    }

    /** 取当前 CSRF 令牌（不存在则生成），供前端读取 */
    public static function csrfToken(): string
    {
        $token = (string) sess_get('csrf_token', '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            sess_set('csrf_token', $token);
        }
        return $token;
    }

    private static function purge(): void
    {
        sess_forget(self::ADMIN);
        sess_forget(self::STUDENT);
        sess_forget(self::TEACHER);
        sess_forget(self::EXAM);
    }
}
