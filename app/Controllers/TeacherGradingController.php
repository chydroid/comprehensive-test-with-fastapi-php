<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Services\SubjectiveGrading;
use Core\HttpException;
use Core\Response;

/**
 * 教师端主观题批改（A4）。
 *
 * 权限口径与其它教师端 {id} 接口完全一致（见 TeacherExamController::assertOwnExam）：
 * 非本人监考的考试、以及模拟考试，一律以 404 回应而非 403 ——
 * 用响应码区分「不存在」与「无权限」，会把考试编号变成可枚举的资源。
 *
 * 接口：
 *   GET  /api/teacher/exams/{id}/subjective               待批阅总览
 *   GET  /api/teacher/exams/{id}/subjective/{stuId}       某考生答题卡（含参考答案）
 *   POST /api/teacher/exams/{id}/subjective/{stuId}       提交批阅（同步重算总分）
 *   POST /api/teacher/exams/{id}/subjective/{stuId}/revoke 撤销批阅
 */
class TeacherGradingController extends BaseController
{
    /** GET /api/teacher/exams/{id}/subjective */
    public function index(): Response
    {
        $exam = $this->assertOwnExam($this->idParam());

        return $this->ok([
            'exam'     => [
                'id'            => (int) $exam['id'],
                'exam_name'     => (string) ($exam['exam_name'] ?? ''),
                'exam_status'   => (string) ($exam['exam_status'] ?? ''),
                'longtext_val'  => (int) ($exam['longtext_val'] ?? 0),
                'exam_score'    => (int) ($exam['exam_score'] ?? 0),
                'total_questions' => Exam::totalQuestions($exam),
            ],
            'overview' => SubjectiveGrading::overview((int) $exam['id']),
        ]);
    }

    /** GET /api/teacher/exams/{id}/subjective/{stuId} */
    public function show(): Response
    {
        $exam = $this->assertOwnExam($this->idParam());
        $stuId = $this->stuIdParam();

        return $this->ok([
            'stu_id' => $stuId,
            'exam'   => [
                'id'           => (int) $exam['id'],
                'exam_name'    => (string) ($exam['exam_name'] ?? ''),
                'longtext_val' => (int) ($exam['longtext_val'] ?? 0),
            ],
        ] + SubjectiveGrading::paper((int) $exam['id'], $stuId));
    }

    /** POST /api/teacher/exams/{id}/subjective/{stuId} */
    public function grade(): Response
    {
        $exam = $this->assertOwnExam($this->idParam());
        $stuId = $this->stuIdParam();
        $sess = $this->authTeacher();

        // 注意：通用 validate() 会拒绝数组值（"必须是标量值"），
        // 批阅项天然是数组，因此这里手工校验而不是走规则表。
        $raw = $this->request->input('items', []);
        if (!is_array($raw) || $raw === []) {
            throw new HttpException(400, '请至少提交一道题目的评分', 40000);
        }
        if (count($raw) > 200) {
            throw new HttpException(400, '单次提交的题目数量过多（上限 200）', 40000);
        }

        $items = [];
        foreach ($raw as $it) {
            if (!is_array($it)) {
                continue;
            }
            $paperId = (int) ($it['paper_id'] ?? 0);
            if ($paperId <= 0) {
                continue;
            }
            $items[] = [
                'paper_id' => $paperId,
                'score'    => (int) ($it['score'] ?? 0),
                'comment'  => (string) ($it['comment'] ?? ''),
            ];
        }
        if ($items === []) {
            throw new HttpException(400, '没有可保存的评分项', 40000);
        }

        $result = SubjectiveGrading::grade(
            (int) $exam['id'],
            $stuId,
            $items,
            (string) ($sess['tea_name'] ?? '')
        );

        $this->audit('exam.grade', 'exam:' . $exam['id'], [
            'actor'   => 'teacher',
            'stu_id'  => $stuId,
            'saved'   => $result['saved'],
            'skipped' => $result['skipped'],
            'score'   => $result['breakdown']['score'] ?? 0,
        ]);

        return $this->ok($result + ['stu_id' => $stuId], '批阅已保存');
    }

    /** POST /api/teacher/exams/{id}/subjective/{stuId}/revoke */
    public function revoke(): Response
    {
        $exam = $this->assertOwnExam($this->idParam());
        $stuId = $this->stuIdParam();

        $breakdown = SubjectiveGrading::revoke((int) $exam['id'], $stuId);

        $this->audit('exam.grade.revoke', 'exam:' . $exam['id'], [
            'actor'  => 'teacher',
            'stu_id' => $stuId,
            'score'  => $breakdown['score'] ?? 0,
        ]);

        return $this->ok(['stu_id' => $stuId, 'breakdown' => $breakdown], '已撤销该考生的主观题批阅');
    }

    /* ------------------------------------------------------------------ */

    /**
     * 解析并校验 {stuId} 路径参数。
     * 准考证号上限 2147483647（stuinfo.id 是 INT），交给 normalizeStuId 收敛。
     */
    private function stuIdParam(): string
    {
        return $this->normalizeStuId($this->request->param('stuId', ''));
    }

    /**
     * 校验考试归属：非本人监考 / 模拟考试 / 不存在 → 一律 404。
     * 与 TeacherExamController::assertOwnExam 同口径（教师端有意不合并基类，
     * 避免为复用二十行而让两个控制器互相继承）。
     */
    private function assertOwnExam(int $id): array
    {
        $teaName = (string) ($this->authTeacher()['tea_name'] ?? '');

        $row = (new Exam())->find($id);
        if ($row === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if ((string) ($row['exam_class'] ?? '') === Exam::MOCK_CLASS) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if ((string) ($row['exam_tea'] ?? '') !== $teaName) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        return $row;
    }
}
