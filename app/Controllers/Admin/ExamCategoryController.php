<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ExamCategory;
use Core\HttpException;
use Core\Response;

/**
 * 考试类别管理 —— exam_category
 * 权限点：category.view / category.edit
 */
class ExamCategoryController extends BaseController
{
    private ExamCategory $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new ExamCategory();
    }

    /** GET /api/admin/exam-categories */
    public function index(): Response
    {
        $list = $this->model->ordered();

        // 一次聚合代替逐行 COUNT（BUG-220）
        $examCount = [];
        foreach (\Core\Database::fetchAll(
            'SELECT exam_category_id, COUNT(*) AS c FROM `examinfo` GROUP BY exam_category_id'
        ) as $r) {
            $examCount[(int) $r['exam_category_id']] = (int) $r['c'];
        }
        foreach ($list as &$row) {
            $row['exam_count'] = $examCount[(int) $row['id']] ?? 0;
        }
        unset($row);

        return $this->ok(['list' => $list, 'total' => count($list)]);
    }

    /** POST /api/admin/exam-categories */
    public function save(): Response
    {
        $in = $this->validate([
            'category_name' => 'required|maxlen:50',
            'sort_order'    => 'integer|min:0|max:9999',
        ]);
        $name = trim((string) $in['category_name']);
        if ($this->model->nameTaken($name)) {
            throw new HttpException(409, '该类别名称已存在', 40900);
        }

        $id = $this->model->create([
            'category_name' => $name,
            'sort_order'    => (int) ($in['sort_order'] ?? 0),
        ]);

        return $this->ok($this->model->find($id), '添加成功');
    }

    /** PUT /api/admin/exam-categories/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '类别不存在', 40400);
        }

        $in = $this->validate([
            'category_name' => 'required|maxlen:50',
            'sort_order'    => 'integer|min:0|max:9999',
        ]);
        $name = trim((string) $in['category_name']);
        if ($this->model->nameTaken($name, $id)) {
            throw new HttpException(409, '该类别名称已存在', 40900);
        }

        $this->model->update($id, [
            'category_name' => $name,
            'sort_order'    => (int) ($in['sort_order'] ?? 0),
        ]);

        return $this->ok($this->model->find($id), '修改成功');
    }

    /** DELETE /api/admin/exam-categories/{id} */
    public function delete(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '类别不存在', 40400);
        }
        if ($this->model->inUse($id)) {
            throw new HttpException(409, '该类别下有考试关联，无法删除', 40901);
        }

        $this->model->delete($id);
        return $this->ok(null, '删除成功');
    }
}
