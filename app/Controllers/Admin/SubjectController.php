<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Quiz;
use App\Models\Subject;
use Core\HttpException;
use Core\Response;

/**
 * 科目管理 —— subject
 * 权限点：subject.view / subject.edit
 *
 * 删除保护：科目下若已有题库或考试，则禁止删除（旧系统无此校验，
 * 会导致题库与考试变成孤儿数据）。
 */
class SubjectController extends BaseController
{
    private Subject $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new Subject();
    }

    /** GET /api/admin/subjects */
    public function index(): Response
    {
        $p = $this->page();
        $kw = $p['keyword'];

        if ($kw !== '') {
            $total = (int) (\Core\Database::fetch(
                'SELECT COUNT(*) AS c FROM `subject` WHERE subj_name LIKE ?',
                [self::like($kw)]
            )['c'] ?? 0);
            $list = \Core\Database::fetchAll(
                'SELECT * FROM `subject` WHERE subj_name LIKE ? ORDER BY id ASC LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'],
                [self::like($kw)]
            );
        } else {
            $result = $this->model->paginate($p['page'], $p['per_page'], []);
            $list = $result['list'];
            $total = $result['total'];
        }

        // 附带题量与考试数，便于前端提示。
        // 两张表各一次 GROUP BY 代替每科目两次 COUNT（BUG-220）
        $quizCount = [];
        foreach (\Core\Database::fetchAll(
            'SELECT subj_id, COUNT(*) AS c FROM `quizlib` GROUP BY subj_id'
        ) as $r) {
            $quizCount[(int) $r['subj_id']] = (int) $r['c'];
        }
        $examCount = [];
        foreach (\Core\Database::fetchAll(
            'SELECT subj_id, COUNT(*) AS c FROM `examinfo` GROUP BY subj_id'
        ) as $r) {
            $examCount[(int) $r['subj_id']] = (int) $r['c'];
        }
        foreach ($list as &$row) {
            $sid = (int) $row['id'];
            $row['quiz_count'] = $quizCount[$sid] ?? 0;
            $row['exam_count'] = $examCount[$sid] ?? 0;
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

    /** GET /api/admin/subjects/{id} */
    public function show(): Response
    {
        $id = $this->idParam();
        $row = $this->model->find($id);
        if ($row === null) {
            throw new HttpException(404, '科目不存在', 40400);
        }
        $row['quiz_count'] = (int) (\Core\Database::fetch(
            'SELECT COUNT(*) AS c FROM `quizlib` WHERE subj_id = ?',
            [$id]
        )['c'] ?? 0);
        $row['type_counts'] = Quiz::countsBySubject($id);

        return $this->ok($row);
    }

    /** POST /api/admin/subjects */
    public function save(): Response
    {
        $in = $this->validate([
            'subj_name' => 'required|maxlen:50',
            'subj_info' => 'maxlen:255',
        ]);
        $name = trim((string) $in['subj_name']);
        if ($this->model->nameTaken($name)) {
            throw new HttpException(409, '该科目名称已存在', 40900);
        }

        $id = $this->model->create([
            'subj_name' => $name,
            'subj_info' => trim((string) ($in['subj_info'] ?? '')),
        ]);

        return $this->ok($this->model->find($id), '添加成功');
    }

    /** PUT /api/admin/subjects/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '科目不存在', 40400);
        }

        $in = $this->validate([
            'subj_name' => 'required|maxlen:50',
            'subj_info' => 'maxlen:255',
        ]);
        $name = trim((string) $in['subj_name']);
        if ($this->model->nameTaken($name, $id)) {
            throw new HttpException(409, '该科目名称已存在', 40900);
        }

        $this->model->update($id, [
            'subj_name' => $name,
            'subj_info' => trim((string) ($in['subj_info'] ?? '')),
        ]);

        return $this->ok($this->model->find($id), '修改成功');
    }

    /** DELETE /api/admin/subjects/{id} */
    public function delete(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '科目不存在', 40400);
        }
        if ($this->model->hasQuizzes($id)) {
            throw new HttpException(409, '该科目下仍有试题，请先清理题库', 40901);
        }
        if ($this->model->hasExams($id)) {
            throw new HttpException(409, '该科目下仍有考试，无法删除', 40902);
        }

        $this->model->delete($id);
        return $this->ok(null, '删除成功');
    }
}
