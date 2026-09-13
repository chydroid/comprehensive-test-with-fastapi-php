<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ExamNews;
use App\Services\AuthSession;
use Core\HttpException;
use Core\Response;

/**
 * 新闻公告管理 —— examnews
 * 权限点：news.view / news.edit
 */
class NewsController extends BaseController
{
    private ExamNews $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new ExamNews();
    }

    /** GET /api/admin/news */
    public function index(): Response
    {
        $p = $this->page();
        $kw = $p['keyword'];

        if ($kw !== '') {
            $total = (int) (\Core\Database::fetch(
                'SELECT COUNT(*) AS c FROM `examnews` WHERE news_title LIKE ?',
                ['%' . $kw . '%']
            )['c'] ?? 0);
            $list = \Core\Database::fetchAll(
                'SELECT * FROM `examnews` WHERE news_title LIKE ? ORDER BY id DESC
                 LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'],
                ['%' . $kw . '%']
            );
        } else {
            $result = $this->model->paginate($p['page'], $p['per_page'], []);
            $list = $result['list'];
            $total = $result['total'];
        }

        return $this->ok([
            'list'        => $list,
            'total'       => $total,
            'page'        => $p['page'],
            'per_page'    => $p['per_page'],
            'total_pages' => (int) ceil($total / max(1, $p['per_page'])),
        ]);
    }

    /** GET /api/admin/news/{id} */
    public function show(): Response
    {
        $row = $this->model->find($this->idParam());
        if ($row === null) {
            throw new HttpException(404, '公告不存在', 40400);
        }
        return $this->ok($row);
    }

    /** POST /api/admin/news */
    public function save(): Response
    {
        $sess = $this->authAdmin();
        $in = $this->validate([
            'news_title' => 'required|maxlen:200',
            'news_info'  => 'maxlen:65535',
        ]);

        $id = $this->model->create([
            'news_title'  => trim((string) $in['news_title']),
            'news_info'   => (string) ($in['news_info'] ?? ''),
            'news_writer' => (string) ($sess['username'] ?? ''),
            'news_time'   => date('Y-m-d H:i:s'),
        ]);

        return $this->ok($this->model->find($id), '发布成功');
    }

    /** PUT /api/admin/news/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '公告不存在', 40400);
        }
        $in = $this->validate([
            'news_title' => 'required|maxlen:200',
            'news_info'  => 'maxlen:65535',
        ]);

        $this->model->update($id, [
            'news_title' => trim((string) $in['news_title']),
            'news_info'  => (string) ($in['news_info'] ?? ''),
        ]);

        return $this->ok($this->model->find($id), '修改成功');
    }

    /** DELETE /api/admin/news/{id} */
    public function delete(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '公告不存在', 40400);
        }
        $this->model->delete($id);
        return $this->ok(null, '删除成功');
    }
}
