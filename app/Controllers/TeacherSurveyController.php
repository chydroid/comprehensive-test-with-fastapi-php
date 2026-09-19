<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Services\Survey;
use Core\HttpException;
use Core\Response;

/**
 * 教师端考后问卷（C5）。
 *
 * 权限口径与其它教师端 {id} 接口完全一致：非本人监考的考试、以及模拟考试，
 * 一律 404 而不是 403 —— 用响应码区分「不存在」与「无权限」等于把考试编号
 * 变成可枚举的资源。
 *
 * 接口：
 *   GET /api/teacher/exams/{id}/survey        题目配置（含统计概览）
 *   PUT /api/teacher/exams/{id}/survey        整体替换题目
 */
class TeacherSurveyController extends BaseController
{
    /** GET /api/teacher/exams/{id}/survey */
    public function show(): Response
    {
        $exam = $this->assertOwnExam($this->idParam());
        $examId = (int) $exam['id'];

        return $this->ok([
            'exam'      => [
                'id'           => $examId,
                'exam_name'    => (string) ($exam['exam_name'] ?? ''),
                'exam_status'  => (string) ($exam['exam_status'] ?? ''),
                'exam_start'   => (string) ($exam['exam_start'] ?? ''),
            ],
            'questions' => Survey::questions($examId),
            'stats'     => Survey::stats($examId),
            'types'     => self::typeOptions(),
        ]);
    }

    /** PUT /api/teacher/exams/{id}/survey */
    public function save(): Response
    {
        $exam = $this->assertOwnExam($this->idParam());
        $examId = (int) $exam['id'];

        // validate() 拒绝数组值，题目列表天然是数组，手工校验
        $raw = $this->request->input('questions', []);
        if (!is_array($raw)) {
            throw new HttpException(400, '提交内容格式不正确', 40000);
        }
        if (count($raw) > Survey::MAX_QUESTIONS) {
            throw new HttpException(400, '单场问卷最多 ' . Survey::MAX_QUESTIONS . ' 道题', 40000);
        }

        $result = Survey::save($examId, $raw);

        $this->audit('exam.survey.save', 'exam:' . $examId, [
            'actor'  => 'teacher',
            'count'  => $result['count'],
        ]);

        return $this->ok($result, '问卷已保存');
    }

    /* ------------------------------------------------------------------ */

    /** @return array<int,array{value:string,label:string}> */
    private static function typeOptions(): array
    {
        $out = [];
        foreach (Survey::TYPES as $t) {
            $out[] = ['value' => $t, 'label' => Survey::TYPE_LABELS[$t] ?? $t];
        }
        return $out;
    }

    /**
     * 校验考试归属：非本人监考 / 模拟考试 / 不存在 → 一律 404。
     * 与 TeacherGradingController::assertOwnExam 同口径（教师端各控制器有意
     * 各自实现这二十行，不为复用而互相继承）。
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
