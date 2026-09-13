<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Admin;
use App\Services\AuthSession;
use App\Services\Password;
use Core\HttpException;
use Core\Response;

/**
 * 管理后台 认证：登录 / 登出 / 当前身份 / 头像 / 改密
 *
 * 安全要点（P0-1 / P0-2 / P0-5）：
 * - 登录成功后轮换会话 ID，下发 CSRF 令牌
 * - 返回当前角色的权限点列表，供前端按权限渲染菜单（后端仍逐请求强校验）
 * - 改密强制强度校验，且必须校验原密码
 */
class AuthController extends BaseController
{
    /** POST /api/admin/login */
    public function login(): Response
    {
        $in = $this->validate([
            'username' => 'required|maxlen:50',
            'password' => 'required|maxlen:64',
        ]);

        $admins = new Admin();
        $row = $admins->findByUsername($in['username']);
        if ($row === null || !Password::verify($in['password'], (string) $row['password'])) {
            throw new HttpException(401, '账号或密码不正确', 40101);
        }

        $role = (string) ($row['admin_power'] ?? '');
        if (!in_array($role, Admin::ROLES, true)) {
            throw new HttpException(403, '账号角色无效，请联系系统管理员', 40301);
        }

        if (Password::needsRehash((string) $row['password'])) {
            $admins->update($row['id'], ['password' => Password::hash($in['password'])]);
        }

        AuthSession::login(AuthSession::ADMIN, [
            'id'          => $row['id'],
            'username'    => $row['username'],
            'admin_power' => $role,
        ]);

        return $this->ok([
            'admin'      => Admin::sanitize($row),
            'permissions'=> $this->permissions($role),
            'csrf_token' => AuthSession::csrfToken(),
        ], '登录成功');
    }

    /** POST /api/admin/logout */
    public function logout(): Response
    {
        AuthSession::logout(AuthSession::ADMIN);
        return $this->ok(null, '已退出登录');
    }

    /** GET /api/admin/me */
    public function me(): Response
    {
        $sess = AuthSession::get(AuthSession::ADMIN);
        if ($sess === null) {
            return $this->ok(['logged_in' => false, 'csrf_token' => AuthSession::csrfToken()]);
        }
        $row = (new Admin())->find($sess['id']);
        if ($row === null) {
            AuthSession::logout(AuthSession::ADMIN);
            return $this->ok(['logged_in' => false, 'csrf_token' => AuthSession::csrfToken()]);
        }
        $role = (string) ($row['admin_power'] ?? '');
        return $this->ok([
            'logged_in'   => true,
            'admin'       => Admin::sanitize($row),
            'permissions' => $this->permissions($role),
            'csrf_token'  => AuthSession::csrfToken(),
        ]);
    }

    /** PUT /api/admin/profile/password —— 管理员自助改密 */
    public function changePassword(): Response
    {
        $sess = $this->authAdmin();
        $in = $this->validate([
            'old_password' => 'required|maxlen:64',
            'new_password' => 'required|minlen:6|maxlen:64',
        ]);

        $admins = new Admin();
        $row = $admins->find($sess['id']);
        if ($row === null || !Password::verify($in['old_password'], (string) $row['password'])) {
            throw new HttpException(400, '原密码不正确', 40000);
        }
        if (Password::isWeak($in['new_password'], (string) $row['username'])) {
            throw new HttpException(400, '新密码过于简单，请使用至少 6 位且非纯数字的组合', 40000);
        }

        $admins->update($row['id'], ['password' => Password::hash($in['new_password'])]);
        // 改密后轮换会话与令牌，使旧令牌失效
        AuthSession::login(AuthSession::ADMIN, [
            'id'          => $row['id'],
            'username'    => $row['username'],
            'admin_power' => $row['admin_power'],
        ]);

        return $this->ok(['csrf_token' => AuthSession::csrfToken()], '密码修改成功');
    }

    /** POST /api/admin/profile/avatar —— 由 UploadController 上传后回填 */
    public function saveAvatar(): Response
    {
        $sess = $this->authAdmin();
        $in = $this->validate(['avatar' => 'required|maxlen:255']);
        (new Admin())->update($sess['id'], ['avatar' => $in['avatar']]);
        sess_set(AuthSession::ADMIN, array_merge($sess, ['avatar' => $in['avatar']]));
        return $this->ok(['avatar' => $in['avatar']], '头像已更新');
    }

    /** 当前角色的权限点列表（'*' 展开为通配标记） */
    private function permissions(string $role): array
    {
        return (array) (config('rbac.' . $role) ?? []);
    }
}
