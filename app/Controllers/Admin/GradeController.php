<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Grade;
use Core\HttpException;
use Core\Response;

/**
 * 单位（年级）管理 —— gradeinfo
 * 权限点：grade.view / grade.edit
 */
class GradeController extends BaseController
{
    private Grade $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new Grade();
    }

    /** GET /api/admin/grades */
    public function index(): Response
    {
        $p = $this->page();
        $kw = $p['keyword'];

        if ($kw !== '') {
            $total = (int) (\Core\Database::fetch(
                'SELECT COUNT(*) AS c FROM `gradeinfo` WHERE grade_name LIKE ?',
                [self::like($kw)]
            )['c'] ?? 0);
            $list = \Core\Database::fetchAll(
                'SELECT * FROM `gradeinfo` WHERE grade_name LIKE ? ORDER BY id ASC LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'],
                [self::like($kw)]
            );
        } else {
            $result = $this->model->paginate($p['page'], $p['per_page'], []);
            $list = $result['list'];
            $total = $result['total'];
        }

        // 附带每个单位下的考生数，便于前端提示「不可删除」
        foreach ($list as &$row) {
            $row['stu_count'] = (int) (\Core\Database::fetch(
                'SELECT COUNT(*) AS c FROM `stuinfo` WHERE grade_id = ?',
                [(string) $row['id']]
            )['c'] ?? 0);
        }
        unset($row);

        return $this->ok([
            'list'       => $list,
            'total'      => $total,
            'page'       => $p['page'],
            'per_page'   => $p['per_page'],
            'total_pages'=> (int) ceil($total / max(1, $p['per_page'])),
        ]);
    }

    /** POST /api/admin/grades */
    public function save(): Response
    {
        $in = $this->validate([
            'grade_name' => 'required|maxlen:50',
            'grade_info' => 'maxlen:255',
        ]);
        $name = trim((string) $in['grade_name']);
        if ($this->model->nameTaken($name)) {
            throw new HttpException(409, '该单位名称已存在', 40900);
        }

        $id = $this->model->create([
            'grade_name' => $name,
            'grade_info' => trim((string) ($in['grade_info'] ?? '')) ?: $name,
        ]);

        return $this->ok($this->model->find($id), '添加成功');
    }

    /** PUT /api/admin/grades/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        $row = $this->model->find($id);
        if ($row === null) {
            throw new HttpException(404, '单位不存在', 40400);
        }

        $in = $this->validate([
            'grade_name' => 'required|maxlen:50',
            'grade_info' => 'maxlen:255',
        ]);
        $name = trim((string) $in['grade_name']);
        if ($this->model->nameTaken($name, $id)) {
            throw new HttpException(409, '该单位名称已存在', 40900);
        }

        $this->model->update($id, [
            'grade_name' => $name,
            'grade_info' => trim((string) ($in['grade_info'] ?? '')) ?: $name,
        ]);

        return $this->ok($this->model->find($id), '修改成功');
    }

    /** DELETE /api/admin/grades/{id} */
    public function delete(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '单位不存在', 40400);
        }
        if ($this->model->inUse($id)) {
            throw new HttpException(409, '该单位下仍有考生，无法删除', 40901);
        }

        $this->model->delete($id);
        return $this->ok(null, '删除成功');
    }
}
