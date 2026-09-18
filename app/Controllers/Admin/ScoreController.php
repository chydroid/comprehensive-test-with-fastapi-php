<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Exam;
use Core\HttpException;
use Core\Response;
use App\Services\InvigilationService;

/**
 * 成绩管理 —— 管理端
 * 权限点：score.view / score.backup / score.export
 */
class ScoreController extends BaseController
{
    private InvigilationService $service;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->service = new InvigilationService();
    }

    /**
     * GET /api/admin/scores
     * 不带 exam_id → 已结束的考试列表
     * 带   exam_id → 该场考试成绩名单
     */
    public function index(): Response
    {
        $examId = $this->resolveExamId();
        $exams = (new Exam())->finishedList();

        if ($examId <= 0) {
            return $this->ok([
                'exams'   => $exams,
                'exam_id' => 0,
                'list'    => [],
            ]);
        }

        $data = $this->service->scoreList(
            $examId,
            (string) $this->request->query('orderby', 'stuid'),
            (string) $this->request->query('order', 'asc')
        );

        $data['exams']       = $exams;
        $data['exam_id']     = $examId;
        $data['can_backup']  = !(new \App\Models\StuScore())->isBackedUp($examId);

        return $this->ok($data);
    }

    /**
     * GET /api/admin/scores/students —— 排序查看（与 index 同源，保留旧系统路由）
     * 出于兼容性保留；内部直接复用 index 逻辑。
     */
    public function listStudents(): Response
    {
        $data = $this->index();
        return $data;
    }

    /** POST /api/admin/scores/backup —— body: {exam_id} */
    public function backup(): Response
    {
        $examId = $this->resolveExamId(true);
        try {
            $count = $this->service->backup($examId);
        } catch (\RuntimeException $e) {
            throw new HttpException(409, $e->getMessage(), 40901);
        }
        $this->audit('score.backup', 'exam:' . $examId, ['backed_up' => $count]);
        return $this->ok(['backed_up' => $count], "备份成功，共备份 {$count} 条成绩记录");
    }

    /** GET /api/admin/scores/backups —— 备份记录列表 */
    public function backupList(): Response
    {
        $list = $this->service->backupList();
        return $this->ok(['list' => $list, 'total' => count($list)]);
    }

    /** GET /api/admin/scores/export?exam_id=N —— 导出 CSV */
    public function exportCsv(): Response
    {
        $examId = $this->resolveExamId(true);
        try {
            // 成绩导出不含考场口令：口令在考试结束后已失效，且教师端导出本就不含，
            // 保持两端口径一致、避免把凭证落进文件（BUG-249）。
            $content = $this->service->csv($examId, false);
            $filename = $this->service->csvFilename($examId);
        } catch (\RuntimeException $e) {
            throw new HttpException(404, $e->getMessage(), 40400);
        }

        return $this->response->downloadText($content, $filename);
    }

    /* ------------------------------------------------------------------ */

    /** 解析并校验 exam_id（支持 exam_id / id 两种参数名） */
    private function resolveExamId(bool $required = false): int
    {
        $examId = (int) $this->request->query(
            'exam_id',
            $this->request->query('id', $this->request->input('exam_id', $this->request->input('examId', 0)))
        );
        if ($examId <= 0) {
            if ($required) {
                throw new HttpException(400, '请选择要操作的考试', 40000);
            }
            return 0;
        }
        if ((new Exam())->find($examId) === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        return $examId;
    }
}
