<?php

declare(strict_types=1);

namespace Core;

/**
 * 极简 JWT（HS256）签发与校验，零依赖
 * - issue(): 签发带 exp/iat/nbf/iss/jti 的签名令牌
 * - verify(): 校验签名(恒定时间比较)、有效期、签发者；通过返回 payload，否则 null
 */
final class Jwt
{
    /** 签发令牌：$claims 为业务声明（如 sub/typ/scope） */
    public static function issue(array $claims, int $ttl, string $secret, string $issuer = 'fastapi-php'): string
    {
        $now = time();
        $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = $claims + [
            'iss' => $issuer,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
        ];
        $signing = self::b64(json_encode($header, JSON_UNESCAPED_SLASHES))
                 . '.'
                 . self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = self::b64(hash_hmac('sha256', $signing, $secret, true));
        return $signing . '.' . $signature;
    }

    /** 校验令牌：签名 / 算法 / 有效期 / 签发者任一项不符均返回 null */
    public static function verify(string $token, string $secret, string $issuer = 'fastapi-php', int $leeway = 30): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headB64, $payloadB64, $sigB64] = $parts;
        if ($headB64 === '' || $payloadB64 === '' || $sigB64 === '') {
            return null;
        }
        // 签名用恒定时间比较，防时序侧信道
        $expected = self::b64(hash_hmac('sha256', $headB64 . '.' . $payloadB64, $secret, true));
        if (!hash_equals($expected, $sigB64)) {
            return null;
        }
        $header = json_decode(self::unb64($headB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null; // 拒绝非 HS256（如 alg=none 或其它算法），防算法混淆
        }
        $payload = json_decode(self::unb64($payloadB64), true);
        if (!is_array($payload)) {
            return null;
        }
        $now = time();
        if (isset($payload['exp']) && $payload['exp'] < $now - $leeway) {
            return null; // 已过期
        }
        if (isset($payload['nbf']) && $payload['nbf'] > $now + $leeway) {
            return null; // 尚未生效
        }
        if (isset($payload['iss']) && $payload['iss'] !== $issuer) {
            return null; // 签发者不符
        }
        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad !== 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        return $decoded === false ? '' : $decoded;
    }
}
