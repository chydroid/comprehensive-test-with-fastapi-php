<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Teacher;
use App\Services\Password;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 监考教师管理 —— teainfo
 * 权限点：teacher.view / teacher.edit
 *
 * 说明：教师账号由管理员创建，不提供自助注册（旧系统同样如此）。
 * 新建/改密同样强制密码强度校验。
 */
class TeacherController extends BaseController
{
    private Teacher $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new Teacher();
    }

    /** GET /api/admin/teachers */
    public function index(): Response
    {
        $p = $this->page();
        $kw = $p['keyword'];

        if ($kw !== '') {
            $total = (int) (Database::fetch(
                'SELECT COUNT(*) AS c FROM `teainfo` WHERE tea_name LIKE ?',
                [self::like($kw)]
            )['c'] ?? 0);
            $rows = Database::fetchAll(
                'SELECT * FROM `teainfo` WHERE tea_name LIKE ? ORDER BY id ASC
                 LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'],
                [self::like($kw)]
            );
        } else {
            $result = $this->model->paginate($p['page'], $p['per_page'], []);
            $rows = $result['list'];
            $total = $result['total'];
        }

        $list = array_map(static fn (array $r): ?array => Teacher::sanitize($r), $rows);

        return $this->ok([
            'list'        => array_values($list),
            'total'       => $total,
            'page'        => $p['page'],
            'per_page'    => $p['per_page'],
            'total_pages' => (int) ceil($total / max(1, $p['per_page'])),
        ]);
    }

    /** GET /api/admin/teachers/{id} */
    public function show(): Response
    {
        $row = $this->model->find($this->idParam());
        if ($row === null) {
            throw new HttpException(404, '教师不存在', 40400);
        }
        return $this->ok(Teacher::sanitize($row));
    }

    /** POST /api/admin/teachers */
    public function save(): Response
    {
        $in = $this->validate([
            'tea_name' => 'required|maxlen:50',
            'password' => \App\Services\Password::rule(),
        ]);
        $name = trim((string) $in['tea_name']);

        if ($this->model->nameTaken($name)) {
            throw new HttpException(409, '该教师姓名已存在', 40900);
        }
        if (Password::isWeak((string) $in['password'], $name)) {
            throw new HttpException(400, '密码过于简单，请使用至少 6 位且非纯数字的组合', 40000);
        }

        $id = $this->model->create([
            'tea_name' => $name,
            'tea_pwd'  => Password::hash((string) $in['password']),
            'avatar'   => '',
        ]);

        $this->audit('user.teacher.create', 'teacher:' . $id, ['tea_name' => $name]);
        return $this->ok(Teacher::sanitize($this->model->find($id)), '添加成功');
    }

    /** PUT /api/admin/teachers/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '教师不存在', 40400);
        }

        $in = $this->validate([
            'tea_name' => 'required|maxlen:50',
            'password' => 'maxlen:64',
        ]);
        $name = trim((string) $in['tea_name']);

        if ($this->model->nameTaken($name, $id)) {
            throw new HttpException(409, '该教师姓名已存在', 40900);
        }

        $data = ['tea_name' => $name];
        $password = (string) ($in['password'] ?? '');
        if ($password !== '') {
            if (Password::isWeak($password, $name)) {
                throw new HttpException(400, '密码过于简单，请使用至少 6 位且非纯数字的组合', 40000);
            }
            $data['tea_pwd'] = Password::hash($password);
        }

        $this->model->update($id, $data);
        $this->audit('user.teacher.update', 'teacher:' . $id, ['tea_name' => $name]);
        return $this->ok(Teacher::sanitize($this->model->find($id)), '修改成功');
    }

    /** DELETE /api/admin/teachers/{id} */
    public function delete(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '教师不存在', 40400);
        }

        // 若该教师仍在监考未结束的考试，则禁止删除
        $row = $this->model->find($id);
        $examCount = (int) (Database::fetch(
            "SELECT COUNT(*) AS c FROM `examinfo`
             WHERE exam_tea = ? AND exam_status IN ('testing','exam','paper')",
            [(string) ($row['tea_name'] ?? '')]
        )['c'] ?? 0);
        if ($examCount > 0) {
            throw new HttpException(409, '该教师仍负责未结束的考试，无法删除', 40901);
        }

        $this->model->delete($id);
        $this->audit('user.teacher.delete', 'teacher:' . $id, ['tea_name' => $row['tea_name'] ?? '']);
        return $this->ok(null, '删除成功');
    }
}
