<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Admin;
use App\Models\Teacher;
use App\Services\AuthSession;
use App\Services\Password;
use Core\HttpException;
use Core\Response;

/**
 * 管理统一入口（管理员 + 教师）认证
 *
 * 设计：
 * - 门户首页不再展示任何管理/教师入口，统一走隐藏入口 /manage；
 * - 单一「账号 + 密码」表单：先按管理员校验、未命中再按教师校验，
 *   自动识别身份，无需用户选择角色；
 * - 命中后分别建立 ADMIN / TEACHER 会话（沿用 AuthSession::login 的
 *   会话轮换与 CSRF 下发），前端拿到 role 后跳转对应工作台；
 * - 工作台初始化所需的账号详情与权限点由 /api/admin/me、/api/teacher/me
 *   各自下发，本接口只返回 role 与 redirect，保持最小暴露面。
 *
 * 说明：若管理员与教师存在同名且同密的账号，管理员优先；
 * 教师仍可经 /teacher 的保留登录页直登兜底（深链不在门户展示）。
 */
class ManageAuthController extends BaseController
{
    /** POST /api/manage/login */
    public function login(): Response
    {
        $in = $this->validate([
            'username' => 'required|maxlen:50',
            'password' => 'required|maxlen:64',
        ]);

        // 1) 管理员优先
        $admins = new Admin();
        $row = $admins->findByUsername($in['username']);
        if ($row !== null && Password::verify($in['password'], (string) $row['password'])) {
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

            $this->audit('auth.login', 'admin:' . $row['id'], [
                'username' => $row['username'],
                'role'     => $role,
                'via'      => 'manage',
            ]);

            return $this->ok(['role' => 'admin', 'redirect' => '/admin']);
        }

        // 2) 教师
        $teachers = new Teacher();
        $trow = $teachers->findByName($in['username']);
        if ($trow !== null && Password::verify($in['password'], (string) $trow['tea_pwd'])) {
            if (Password::needsRehash((string) $trow['tea_pwd'])) {
                $teachers->update($trow['id'], ['tea_pwd' => Password::hash($in['password'])]);
            }

            AuthSession::login(AuthSession::TEACHER, [
                'id'       => $trow['id'],
                'tea_name' => $trow['tea_name'],
                'avatar'   => $trow['avatar'] ?? '',
            ]);

            return $this->ok(['role' => 'teacher', 'redirect' => '/teacher']);
        }

        // 与既有登录口令保持一致，避免泄露「账号存在与否」
        throw new HttpException(401, '账号或密码不正确', 40101);
    }
}
