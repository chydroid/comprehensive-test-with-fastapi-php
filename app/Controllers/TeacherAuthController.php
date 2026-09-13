<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Teacher;
use App\Services\AuthSession;
use App\Services\Password;
use Core\HttpException;
use Core\Response;

/**
 * 监考教师 登录 / 登出 / 当前身份
 */
class TeacherAuthController extends BaseController
{
    /** POST /api/teacher/login */
    public function login(): Response
    {
        $in = $this->validate([
            'username' => 'required|maxlen:50',
            'password' => 'required|maxlen:64',
        ]);

        $teachers = new Teacher();
        $row = $teachers->findByName($in['username']);
        if ($row === null || !Password::verify($in['password'], (string) $row['tea_pwd'])) {
            throw new HttpException(401, '账号或密码不正确', 40101);
        }

        if (Password::needsRehash((string) $row['tea_pwd'])) {
            $teachers->update($row['id'], ['tea_pwd' => Password::hash($in['password'])]);
        }

        AuthSession::login(AuthSession::TEACHER, [
            'id'       => $row['id'],
            'tea_name' => $row['tea_name'],
            'avatar'   => $row['avatar'] ?? '',
        ]);

        return $this->ok([
            'teacher'    => Teacher::sanitize($row),
            'csrf_token' => AuthSession::csrfToken(),
        ], '登录成功');
    }

    /** POST /api/teacher/logout */
    public function logout(): Response
    {
        AuthSession::logout(AuthSession::TEACHER);
        return $this->ok(null, '已退出登录');
    }

    /** GET /api/teacher/me */
    public function me(): Response
    {
        $sess = AuthSession::get(AuthSession::TEACHER);
        if ($sess === null) {
            return $this->ok(['logged_in' => false, 'csrf_token' => AuthSession::csrfToken()]);
        }
        $row = (new Teacher())->find($sess['id']);
        return $this->ok([
            'logged_in'  => true,
            'teacher'    => Teacher::sanitize($row),
            'csrf_token' => AuthSession::csrfToken(),
        ]);
    }
}
