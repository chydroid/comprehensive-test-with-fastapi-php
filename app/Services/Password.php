<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 密码服务：bcrypt 为默认算法，向后兼容旧系统的 md5 哈希并在验证成功时自动升级。
 *
 * 旧库现状：admininfo/stuinfo/teainfo 三张表均已为 bcrypt（$2y$10$…，60 位），
 * 但仍保留 md5 兼容分支，以应对历史数据或导入数据中的旧哈希。
 */
final class Password
{
    /** 密码最小长度的兜底默认值（实际取值见后台「系统设置 → 安全策略」） */
    public const DEFAULT_MIN_LENGTH = 6;

    /** 最小长度：读取后台设置，随配置实时生效 */
    public static function minLength(): int
    {
        return max(1, Setting::int('password_min_length', self::DEFAULT_MIN_LENGTH));
    }

    /** 校验规则片段：'required|minlen:N|maxlen:64'，供各控制器复用 */
    public static function rule(bool $required = true): string
    {
        return ($required ? 'required|' : '') . 'minlen:' . self::minLength() . '|maxlen:64';
    }

    /** 生成哈希（bcrypt） */
    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    /**
     * 验证密码；命中旧 md5 格式且验证成功时返回 true，并回调新哈希供调用方落库升级。
     */
    public static function verify(string $plain, string $stored): bool
    {
        if ($stored === '') {
            return false;
        }
        if (password_verify($plain, $stored)) {
            return true;
        }
        // 旧 md5（32 位十六进制）兼容
        if (strlen($stored) === 32 && ctype_xdigit($stored)) {
            return hash_equals($stored, md5($plain));
        }
        return false;
    }

    /** 该哈希是否需要升级为当前算法 */
    public static function needsRehash(string $stored): bool
    {
        if ($stored === '') {
            return false;
        }
        if (strlen($stored) === 32 && ctype_xdigit($stored)) {
            return true; // 旧 md5 一律升级
        }
        return password_needs_rehash($stored, PASSWORD_DEFAULT);
    }

    /** 是否弱密码（P0-5：拒绝 123456 / 纯数字短密码 / 与账号同名） */
    public static function isWeak(string $plain, string $account = ''): bool
    {
        $len = function_exists('mb_strlen') ? mb_strlen($plain) : strlen($plain);
        if ($len < self::minLength()) {
            return true;
        }
        $weak = ['123456', '1234567', '12345678', '123456789', '000000', '111111',
                 '888888', '666666', 'password', 'admin', 'abc123', 'qwerty'];
        if (in_array(strtolower($plain), $weak, true)) {
            return true;
        }
        // 纯数字且长度 < 8
        if (ctype_digit($plain) && $len < 8) {
            return true;
        }
        // 与账号相同
        if ($account !== '' && strcasecmp($plain, $account) === 0) {
            return true;
        }
        return false;
    }
}
