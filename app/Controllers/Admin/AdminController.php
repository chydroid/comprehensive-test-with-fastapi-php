<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Admin;
use App\Services\Password;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 管理员管理 —— admininfo
 * 权限点：admin.manage（仅 systemAdmin）
 *
 * 自我保护规则（沿用旧系统并加强）：
 * - 不能删除当前登录账号
 * - 不能修改自己的角色（防止误把自己降级后失去权限）
 * - 新建/改密强制密码强度校验（P0-5 修复）
 */
class AdminController extends BaseController
{
    private Admin $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new Admin();
    }

    /** GET /api/admin/admins */
    public function index(): Response
    {
        $p = $this->page();
        $kw = $p['keyword'];

        if ($kw !== '') {
            $total = (int) (Database::fetch(
                'SELECT COUNT(*) AS c FROM `admininfo` WHERE username LIKE ?',
                [self::like($kw)]
            )['c'] ?? 0);
            $rows = Database::fetchAll(
                'SELECT * FROM `admininfo` WHERE username LIKE ? ORDER BY id ASC
                 LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'],
                [self::like($kw)]
            );
        } else {
            $result = $this->model->paginate($p['page'], $p['per_page'], []);
            $rows = $result['list'];
            $total = $result['total'];
        }

        $list = array_map(static fn (array $r): ?array => Admin::sanitize($r), $rows);
        $me = $this->authAdmin();

        return $this->ok([
            'list'      => $list,
            'roles'     => array_map(
                static fn (string $role): array => [
                    'value' => $role,
                    'label' => Admin::ROLE_LABELS[$role] ?? $role,
                ],
                Admin::ROLES
            ),
            'total'     => $total,
            'page'      => $p['page'],
            'per_page'  => $p['per_page'],
            'total_pages' => (int) ceil($total / max(1, $p['per_page'])),
        ]);
    }

    /** GET /api/admin/admins/{id} */
    public function show(): Response
    {
        $row = $this->model->find($this->idParam());
        if ($row === null) {
            throw new HttpException(404, '管理员不存在', 40400);
        }
        return $this->ok(Admin::sanitize($row));
    }

    /** POST /api/admin/admins */
    public function save(): Response
    {
        $in = $this->validate([
            'username'    => 'required|maxlen:50',
            'password'    => \App\Services\Password::rule(),
            'admin_power' => 'required|in:' . implode(',', Admin::ROLES),
        ]);
        $username = trim((string) $in['username']);

        if ($this->model->findByUsername($username) !== null) {
            throw new HttpException(409, '该用户名已存在', 40900);
        }
        if (Password::isWeak((string) $in['password'], $username)) {
            throw new HttpException(400, '密码过于简单，请使用至少 6 位且非纯数字的组合', 40000);
        }

        $id = $this->model->create([
            'username'    => $username,
            'password'    => Password::hash((string) $in['password']),
            'admin_power' => (string) $in['admin_power'],
            'avatar'      => '',
        ]);

        $this->audit('user.admin.create', 'admin:' . $id, ['username' => $username, 'admin_power' => $in['admin_power']]);
        return $this->ok(Admin::sanitize($this->model->find($id)), '添加成功');
    }

    /** PUT /api/admin/admins/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        $row = $this->model->find($id);
        if ($row === null) {
            throw new HttpException(404, '管理员不存在', 40400);
        }

        $in = $this->validate([
            'username'    => 'required|maxlen:50',
            'password'    => 'maxlen:64',
            'admin_power' => 'required|in:' . implode(',', Admin::ROLES),
        ]);
        $username = trim((string) $in['username']);

        $dup = $this->model->findByUsername($username);
        if ($dup !== null && (int) $dup['id'] !== $id) {
            throw new HttpException(409, '该用户名已存在', 40900);
        }

        $me = $this->authAdmin();
        $power = (string) $in['admin_power'];
        // 本人角色强制保留原值，避免自我降权后失去全部权限
        if ((int) $me['id'] === $id) {
            $power = (string) ($row['admin_power'] ?? '');
        }

        $data = ['username' => $username, 'admin_power' => $power];

        $password = (string) ($in['password'] ?? '');
        if ($password !== '') {
            if (Password::isWeak($password, $username)) {
                throw new HttpException(400, '密码过于简单，请使用至少 6 位且非纯数字的组合', 40000);
            }
            $data['password'] = Password::hash($password);
        }

        $this->model->update($id, $data);

        // 改的是自己 → 同步刷新会话中的用户名
        if ((int) $me['id'] === $id) {
            $me['username'] = $username;
            sess_set(\App\Services\AuthSession::ADMIN, $me);
        }

        $this->audit('user.admin.update', 'admin:' . $id, ['username' => $username, 'admin_power' => $power]);
        return $this->ok(Admin::sanitize($this->model->find($id)), '修改成功');
    }

    /** DELETE /api/admin/admins/{id} */
    public function delete(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '管理员不存在', 40400);
        }

        $me = $this->authAdmin();
        if ((int) $me['id'] === $id) {
            throw new HttpException(409, '不能删除当前登录的管理员', 40901);
        }

        // 至少保留一个 systemAdmin，避免系统失去管理入口
        $row = $this->model->find($id);
        if (($row['admin_power'] ?? '') === 'systemAdmin') {
            $count = (int) (Database::fetch(
                "SELECT COUNT(*) AS c FROM `admininfo` WHERE admin_power = 'systemAdmin'"
            )['c'] ?? 0);
            if ($count <= 1) {
                throw new HttpException(409, '系统至少需要保留一名系统管理员', 40902);
            }
        }

        $this->model->delete($id);
        $this->audit('user.admin.delete', 'admin:' . $id, ['username' => $row['username'] ?? '']);
        return $this->ok(null, '删除成功');
    }
}
