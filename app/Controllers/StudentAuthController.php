<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AuthSession;
use App\Services\Password;
use Core\HttpException;
use Core\Response;

/**
 * 考生注册 / 登录 / 登出 / 当前身份
 *
 * 与旧系统的差异：
 * - 注册时校验准考证号唯一 + 密码强度（P0-5），不再明文回显密码
 * - 登录成功后轮换会话 ID 并下发 CSRF 令牌（P0-2 修复配套）
 * - 全部输入走声明式校验，输出统一 JSON
 */
class StudentAuthController extends BaseController
{
    /** POST /api/student/register */
    public function register(): Response
    {
        $in = $this->validate([
            'stu_id'   => 'required|regex:/^\d{1,20}$/',
            'stu_name' => 'required|maxlen:50',
            'password' => \App\Services\Password::rule(),
            'stu_sex'  => 'maxlen:10',
            'grade_id' => 'maxlen:100',
            'class_id' => 'maxlen:100',
        ]);

        if (Password::isWeak($in['password'], $in['stu_name'])) {
            throw new HttpException(400, '密码过于简单，请使用至少 6 位且非纯数字的组合', 40000);
        }

        $stuId = $this->normalizeStuId($in['stu_id']);
        $students = new Student();
        // 准考证号即 stuinfo.id
        if ($students->find($stuId) !== null) {
            throw new HttpException(409, '准考证号已被使用，请检查', 40900);
        }
        if ($students->findByName($in['stu_name']) !== null) {
            throw new HttpException(409, '该姓名已注册，请检查', 40900);
        }

        // 单条 INSERT 直接写入业务主键。此前「先 INSERT 自增 id，再 UPDATE 改主键」
        // 两步走：第二步失败（越界 / 被 kill / 超时）会留下一条 id 为自增值、
        // 无法登录的孤儿考生行，且响应是 500。
        // grade_id / class_id 归一为 ID：注册表单可能提交名称，而排卷、待考列表
        // 一律按 ID 匹配，存名称会让该考生从此看不到任何考试。
        \Core\Database::query(
            'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $stuId,
                (string) $in['stu_name'],
                Password::hash($in['password']),
                (string) ($in['stu_sex'] ?? ''),
                Grade::resolveId($in['grade_id'] ?? ''),
                SchoolClass::resolveId($in['class_id'] ?? ''),
            ]
        );

        return $this->ok(['stu_id' => $stuId], '注册成功');
    }

    /** GET /api/student/register/options —— 注册页所需的下拉数据 */
    public function registerOptions(): Response
    {
        return $this->ok([
            'grades'  => (new Grade())->all('id ASC'),
            'classes' => (new SchoolClass())->all('id ASC'),
        ]);
    }

    /** POST /api/student/login */
    public function login(): Response
    {
        $in = $this->validate([
            'username' => 'required|maxlen:50',
            'password' => 'required|maxlen:64',
        ]);

        $students = new Student();
        // 账号可能是准考证号（id）或姓名
        $row = ctype_digit($in['username'])
            ? $students->find($in['username'])
            : $students->findByName($in['username']);

        if ($row === null || !Password::verify($in['password'], (string) $row['stu_pwd'])) {
            // 统一错误文案，避免账号枚举
            throw new HttpException(401, '账号或密码不正确', 40101);
        }

        // 旧 md5 哈希验证成功后自动升级为 bcrypt
        if (Password::needsRehash((string) $row['stu_pwd'])) {
            $students->update($row['id'], ['stu_pwd' => Password::hash($in['password'])]);
        }

        AuthSession::login(AuthSession::STUDENT, [
            'id'       => $row['id'],
            'stu_name' => $row['stu_name'],
            'grade_id' => $row['grade_id'] ?? '',
            'class_id' => $row['class_id'] ?? '',
        ]);

        return $this->ok([
            'student'    => Student::sanitize($row),
            'csrf_token' => AuthSession::csrfToken(),
        ], '登录成功');
    }

    /** POST /api/student/logout */
    public function logout(): Response
    {
        AuthSession::logout(AuthSession::STUDENT);
        return $this->ok(null, '已退出登录');
    }

    /** GET /api/student/me */
    public function me(): Response
    {
        $sess = AuthSession::get(AuthSession::STUDENT);
        if ($sess === null) {
            return $this->ok(['logged_in' => false, 'csrf_token' => AuthSession::csrfToken()]);
        }
        $row = (new Student())->find($sess['id']);
        return $this->ok([
            'logged_in'  => true,
            'student'    => Student::sanitize($row),
            'csrf_token' => AuthSession::csrfToken(),
        ]);
    }
}
