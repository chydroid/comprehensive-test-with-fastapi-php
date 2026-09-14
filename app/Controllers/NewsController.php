<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ExamNews;
use Core\HttpException;
use Core\Response;

/**
 * 前台公告（只读）
 */
class NewsController extends BaseController
{
    /** GET /api/public/news —— 公告列表（分页） */
    public function index(): Response
    {
        $p = $this->page(null, 50);
        $model = new ExamNews();
        $result = $model->paginate($p['page'], $p['per_page']);
        return $this->ok($result);
    }

    /** GET /api/public/news/{id} */
    public function show(): Response
    {
        $id = $this->idParam();
        $row = (new ExamNews())->find($id);
        if ($row === null) {
            throw new HttpException(404, '公告不存在', 40400);
        }
        return $this->ok($row);
    }
}
