<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * 管理员（admininfo）
 * admin_power 四种角色：systemAdmin / testAdmin / quizOperator / quizAdder
 * 无 created_at/updated_at 列，故关闭时间戳自动维护。
 */
class Admin extends Model
{
    protected string $table = 'admininfo';
    protected bool $timestamps = false;

    protected array $fillable = [
        'username', 'password', 'avatar', 'admin_power',
    ];

    public const ROLES = ['systemAdmin', 'testAdmin', 'quizOperator', 'quizAdder'];

    public const ROLE_LABELS = [
        'systemAdmin'  => '系统管理员',
        'testAdmin'    => '考试管理员',
        'quizOperator' => '题库管理员',
        'quizAdder'    => '题库录入员',
    ];

    public function findByUsername(string $username): ?array
    {
        return $this->firstWhere(['username' => $username]);
    }

    /** 对外输出时剥离密码字段并补充角色中文名 */
    public static function sanitize(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        unset($row['password']);
        $row['admin_power_label'] = self::ROLE_LABELS[$row['admin_power'] ?? ''] ?? '未知';
        // 头像规范化：数据库可能只存裸文件名（旧数据位于 /uploads/avatars/），
        // 前端需要完整同源 URL；空值交由前端回退到首字母占位。
        $row['avatar'] = self::normalizeAvatar($row['avatar'] ?? '');
        return $row;
    }

    /**
     * 把头像字段规范化为可直出的同源 URL
     * - 空字符串 -> 空（前端显示首字母）
     * - 已是 /、http、data: 开头 -> 原样返回
     * - 裸文件名 -> 补 /uploads/avatars/ 前缀（与旧数据落盘位置一致）
     */
    public static function normalizeAvatar(string $avatar): string
    {
        $avatar = trim($avatar);
        if ($avatar === '') {
            return '';
        }
        if (preg_match('#^(/|https?://|data:)#i', $avatar)) {
            return $avatar;
        }
        return '/uploads/avatars/' . ltrim($avatar, '/');
    }
}
