<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SchoolClass;
use Core\HttpException;
use Core\Response;

/**
 * 班级管理 —— classinfo
 * 权限点：class.view / class.edit
 */
class ClassController extends BaseController
{
    private SchoolClass $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new SchoolClass();
    }

    /** GET /api/admin/classes */
    public function index(): Response
    {
        $p = $this->page();
        $kw = $p['keyword'];

        if ($kw !== '') {
            $total = (int) (\Core\Database::fetch(
                'SELECT COUNT(*) AS c FROM `classinfo` WHERE class_name LIKE ?',
                ['%' . $kw . '%']
            )['c'] ?? 0);
            $list = \Core\Database::fetchAll(
                'SELECT * FROM `classinfo` WHERE class_name LIKE ? ORDER BY id ASC LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'],
                ['%' . $kw . '%']
            );
        } else {
            $result = $this->model->paginate($p['page'], $p['per_page'], []);
            $list = $result['list'];
            $total = $result['total'];
        }

        foreach ($list as &$row) {
            $row['stu_count'] = (int) (\Core\Database::fetch(
                'SELECT COUNT(*) AS c FROM `stuinfo` WHERE class_id = ?',
                [(string) $row['id']]
            )['c'] ?? 0);
        }
        unset($row);

        return $this->ok([
            'list'        => $list,
            'total'       => $total,
            'page'        => $p['page'],
            'per_page'    => $p['per_page'],
            'total_pages' => (int) ceil($total / max(1, $p['per_page'])),
        ]);
    }

    /** POST /api/admin/classes */
    public function save(): Response
    {
        $in = $this->validate([
            'class_name' => 'required|maxlen:50',
            'class_info' => 'maxlen:255',
        ]);
        $name = trim((string) $in['class_name']);
        if ($this->model->nameTaken($name)) {
            throw new HttpException(409, '该班级名称已存在', 40900);
        }

        $id = $this->model->create([
            'class_name' => $name,
            'class_info' => trim((string) ($in['class_info'] ?? '')) ?: $name,
        ]);

        return $this->ok($this->model->find($id), '添加成功');
    }

    /** PUT /api/admin/classes/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '班级不存在', 40400);
        }

        $in = $this->validate([
            'class_name' => 'required|maxlen:50',
            'class_info' => 'maxlen:255',
        ]);
        $name = trim((string) $in['class_name']);
        if ($this->model->nameTaken($name, $id)) {
            throw new HttpException(409, '该班级名称已存在', 40900);
        }

        $this->model->update($id, [
            'class_name' => $name,
            'class_info' => trim((string) ($in['class_info'] ?? '')) ?: $name,
        ]);

        return $this->ok($this->model->find($id), '修改成功');
    }

    /** DELETE /api/admin/classes/{id} */
    public function delete(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '班级不存在', 40400);
        }
        if ($this->model->inUse($id)) {
            throw new HttpException(409, '该班级下仍有考生，无法删除', 40901);
        }

        $this->model->delete($id);
        return $this->ok(null, '删除成功');
    }
}
