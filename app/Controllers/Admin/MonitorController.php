<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Exam;
use App\Services\CheatGuard;
use App\Services\InvigilationService;
use Core\HttpException;
use Core\Response;

/**
 * 考场监控 —— 管理端
 * 权限点：monitor.view（只读）/ monitor.control（锁定·解锁·交卷·结束）
 *
 * 业务实现统一收敛在 InvigilationService，与教师端共用同一份逻辑。
 */
class MonitorController extends BaseController
{
    private InvigilationService $service;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->service = new InvigilationService();
    }

    /**
     * GET /api/admin/monitor
     * 不带 exam_id → 返回可监控的考试列表（前端进入选择页）
     * 带   exam_id → 返回该场考试的考生名单与状态统计
     */
    public function index(): Response
    {
        $examId = (int) $this->request->query('exam_id', $this->request->query('id', 0));

        if ($examId <= 0) {
            // 仅列出仍在流程中的考试（未开考/已排卷/进行中）
            $rows = [];
            foreach ((new Exam())->adminList(['status' => ''], 0, 200)['data'] as $e) {
                if (in_array((string) $e['exam_status'], [Exam::STATUS_EXAM, Exam::STATUS_PAPER, Exam::STATUS_TESTING], true)) {
                    // 到点惰性自动结束：过期考场立刻收敛为 over 并从这里消失，
                    // 否则它会一直挂在监考选择列表上（BUG-251）。
                    if (Exam::autoEndIfDue((int) $e['id'])) {
                        continue;
                    }
                    // 到点惰性自动开考
                    Exam::autoStartIfDue((int) $e['id']);
                    $e['exam_status']    = (string) ((new Exam())->find((int) $e['id'])['exam_status'] ?? $e['exam_status']);
                    $e['status_summary'] = (new Exam())->statusSummary((int) $e['id']);
                    $rows[] = $e;
                }
            }
            return $this->ok(['exams' => $rows, 'exam_id' => 0]);
        }

        $this->assertExam($examId);
        Exam::autoStartIfDue($examId);
        Exam::autoEndIfDue($examId);
        $data = $this->service->roster(
            $examId,
            (string) $this->request->query('orderby', 'stuid'),
            (string) $this->request->query('order', 'asc')
        );
        $data['exam_id'] = $examId;

        return $this->ok($data);
    }

    /** POST /api/admin/monitor/lock —— body: {exam_id, stu_id} */
    public function lock(): Response
    {
        [$examId, $stuId] = $this->target();
        $affected = $this->service->lock($examId, $stuId);
        $this->audit('monitor.lock', "exam:{$examId}", ['stu_id' => $stuId]);
        return $this->ok(['affected' => $affected], '已锁定');
    }

    /** POST /api/admin/monitor/unlock */
    public function unlock(): Response
    {
        [$examId, $stuId] = $this->target();
        $affected = $this->service->unlock($examId, $stuId);
        return $this->ok(['affected' => $affected], '已解锁');
    }

    /** POST /api/admin/monitor/submit —— 全员交卷并判分（考试继续） */
    public function submitAll(): Response
    {
        $examId = $this->examId();
        $result = $this->service->submitAll($examId);
        $this->audit('monitor.submit', "exam:{$examId}", $result);
        return $this->ok($result, "已强制交卷并判分 {$result['graded']} 人（跳过已交卷 {$result['skipped']} 人）");
    }

    /** POST /api/admin/monitor/submit-one —— body: {exam_id, stu_id} 单个考生交卷并判分 */
    public function submitOne(): Response
    {
        [$examId, $stuId] = $this->target();
        $result = $this->service->submitOne($examId, $stuId);
        if ($result === null) {
            throw new HttpException(404, '该考生不在本场考试名单中', 40401);
        }
        $message = $result['skipped'] > 0
            ? '该考生已交卷'
            : "已强制交卷并判分（{$result['score']} 分）";
        $this->audit('monitor.submit-one', "exam:{$examId}", ['stu_id' => $stuId, 'score' => $result['score'] ?? null, 'skipped' => $result['skipped'] ?? 0]);
        return $this->ok($result, $message);
    }

    /** POST /api/admin/monitor/lock-all */
    public function lockAll(): Response
    {
        $examId = $this->examId();
        $affected = $this->service->lockAll($examId);
        $this->audit('monitor.lock-all', "exam:{$examId}", ['affected' => $affected]);
        return $this->ok(['affected' => $affected], '已全部锁定');
    }

    /** POST /api/admin/monitor/unlock-all */
    public function unlockAll(): Response
    {
        $examId = $this->examId();
        $affected = $this->service->unlockAll($examId);
        $this->audit('monitor.unlock-all', "exam:{$examId}", ['affected' => $affected]);
        return $this->ok(['affected' => $affected], '已全部解锁');
    }

    /** POST /api/admin/monitor/over-all —— 结束整场考试 */
    public function overAll(): Response
    {
        $examId = $this->examId();
        $result = $this->service->endAll($examId);
        $this->audit('monitor.over-all', "exam:{$examId}", $result);
        return $this->ok($result, "考试已结束，共判分 {$result['graded']} 人");
    }

    /** GET /api/admin/monitor/cheat-events?exam_id=&stu_id= —— 本场异常行为记录 */
    public function cheatEvents(): Response
    {
        $examId = $this->examId();
        $stuId = trim((string) $this->request->query('stu_id', ''));
        return $this->ok(['events' => CheatGuard::events($examId, $stuId === '' ? null : $stuId)]);
    }

    /* ------------------------------------------------------------------ */

    private function examId(): int
    {
        // 兼容 query（GET 列表 / 前端 { query } 传参）与 body（POST 操作）两种来源
        $all = $this->request->all();
        $examId = (int) ($all['exam_id'] ?? $all['examId'] ?? 0);
        if ($examId <= 0) {
            throw new HttpException(400, '缺少 exam_id 参数', 40000);
        }
        $this->assertExam($examId);
        return $examId;
    }

    /** @return array{0:int,1:string} */
    private function target(): array
    {
        $examId = $this->examId();
        $stuId = trim((string) $this->request->input('stu_id', $this->request->input('stuId', '')));
        if ($stuId === '') {
            throw new HttpException(400, '缺少 stu_id 参数', 40001);
        }
        return [$examId, $stuId];
    }

    private function assertExam(int $examId): void
    {
        if ((new Exam())->find($examId) === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
    }
}
