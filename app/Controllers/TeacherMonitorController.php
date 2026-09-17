<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Services\InvigilationService;
use Core\HttpException;
use Core\Response;

/**
 * 教师端 —— 考场监控
 *
 * 业务逻辑全部复用 InvigilationService（与管理端同一份实现）。
 * 与管理员端的差异仅在权限：这里只要求教师登录态，
 * 因此额外校验「该考试确由本教师监考」，防止教师越权操作他人考场。
 */
class TeacherMonitorController extends BaseController
{
    private InvigilationService $service;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->service = new InvigilationService();
    }

    /**
     * GET /api/teacher/monitor
     * 不带 exam_id → 我监考的考试列表
     * 带   exam_id → 该场考试考生名单
     */
    public function index(): Response
    {
        $sess = $this->authTeacher();
        $teaName = (string) ($sess['tea_name'] ?? '');
        $examId = (int) $this->request->query('exam_id', $this->request->query('id', 0));

        if ($examId <= 0) {
            // 模拟考试不属于监考范畴（考生自主生成、无人监考），整体排除。
            $exams = \Core\Database::fetchAll(
                "SELECT e.id, e.exam_name, e.exam_class, e.exam_start, e.exam_end, e.exam_status,
                        e.exam_score, s.subj_name
                 FROM `examinfo` e
                 INNER JOIN `subject` s ON s.id = e.subj_id
                 WHERE e.exam_tea = ?
                   AND e.exam_status IN ('exam','paper','testing')
                   AND COALESCE(e.exam_class, '') <> ?
                 ORDER BY e.id DESC",
                [$teaName, Exam::MOCK_CLASS]
            );
            foreach ($exams as &$e) {
                Exam::autoStartIfDue((int) $e['id']);
                $e['exam_status']    = (string) ((new Exam())->find((int) $e['id'])['exam_status'] ?? $e['exam_status']);
                $e['status_summary'] = (new Exam())->statusSummary((int) $e['id']);
            }
            unset($e);
            return $this->ok(['exams' => $exams, 'exam_id' => 0]);
        }

        $this->assertOwnExam($examId, $teaName);
        Exam::autoStartIfDue($examId);
        $data = $this->service->roster(
            $examId,
            (string) $this->request->query('orderby', 'stuid'),
            (string) $this->request->query('order', 'asc')
        );
        $data['exam_id'] = $examId;

        return $this->ok($data);
    }

    /** POST /api/teacher/monitor/lock */
    public function lock(): Response
    {
        [$examId, $stuId] = $this->target();
        $affected = $this->service->lock($examId, $stuId);
        return $this->ok(['affected' => $affected], '已锁定');
    }

    /** POST /api/teacher/monitor/unlock */
    public function unlock(): Response
    {
        [$examId, $stuId] = $this->target();
        $affected = $this->service->unlock($examId, $stuId);
        return $this->ok(['affected' => $affected], '已解锁');
    }

    /** POST /api/teacher/monitor/submit —— 全员交卷 */
    public function submitAll(): Response
    {
        $examId = $this->ownExamId();
        $result = $this->service->submitAll($examId);
        return $this->ok($result, "已强制交卷并判分 {$result['graded']} 人（跳过已交卷 {$result['skipped']} 人）");
    }

    /** POST /api/teacher/monitor/submit-one —— 单个考生交卷并判分 */
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
        return $this->ok($result, $message);
    }

    /** POST /api/teacher/monitor/lock-all */
    public function lockAll(): Response
    {
        $examId = $this->ownExamId();
        $affected = $this->service->lockAll($examId);
        return $this->ok(['affected' => $affected], '已全部锁定');
    }

    /** POST /api/teacher/monitor/unlock-all */
    public function unlockAll(): Response
    {
        $examId = $this->ownExamId();
        $affected = $this->service->unlockAll($examId);
        return $this->ok(['affected' => $affected], '已全部解锁');
    }

    /** POST /api/teacher/monitor/over-all —— 结束整场考试 */
    public function overAll(): Response
    {
        $examId = $this->ownExamId();
        $result = $this->service->endAll($examId);
        return $this->ok($result, "考试已结束，共判分 {$result['graded']} 人");
    }

    /* ------------------------------------------------------------------ */

    private function ownExamId(): int
    {
        $sess = $this->authTeacher();
        $examId = (int) $this->request->input('exam_id', $this->request->input('examId', 0));
        if ($examId <= 0) {
            throw new HttpException(400, '缺少 exam_id 参数', 40000);
        }
        $this->assertOwnExam($examId, (string) ($sess['tea_name'] ?? ''));
        return $examId;
    }

    /** @return array{0:int,1:string} */
    private function target(): array
    {
        $examId = $this->ownExamId();
        $stuId = trim((string) $this->request->input('stu_id', $this->request->input('stuId', '')));
        if ($stuId === '') {
            throw new HttpException(400, '缺少 stu_id 参数', 40001);
        }
        return [$examId, $stuId];
    }

    /** 考试存在 + 由当前教师监考 */
    private function assertOwnExam(int $examId, string $teaName): void
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if ((string) ($exam['exam_tea'] ?? '') !== $teaName) {
            throw new HttpException(403, '该考试不由你监考，无权操作', 40301);
        }
    }
}
