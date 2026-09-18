<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\Grade;
use App\Models\Quiz;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\ExamEngine;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 考试管理 —— examinfo
 * 权限点：exam.view / exam.add / exam.edit / exam.delete / exam.start / exam.generate
 *
 * 组卷参数（重要，勿臆断列名）：
 *   每种题型（radio1/radio2/checkbox/text）有 3 个难度抽题数：
 *     {type}_easy_sum / {type}_mid_sum / {type}_hard_sum
 *   以及 1 个每题分值：
 *     {type}_val      （**不是** _score）
 *   满分为 exam_score，由组卷参数推算而来（Exam::computedTotalScore）。
 *
 * 状态取值：testing 进行中 / exam 已开考 / paper 已排卷 / over 已结束 / overBak 已备份
 *
 * 相对于旧系统的改进：
 * - 保存/更新时校验题库容量（Exam::checkStock），不足直接拒绝并返回缺题明细，
 *   而不是让考生拿到残缺试卷。
 * - 排卷（generatePapers）返回每个考生的组卷结果与缺题告警，不再静默。
 */
class ExamController extends BaseController
{
    private Exam $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new Exam();
    }

    /** GET /api/admin/exams */
    public function index(): Response
    {
        $p = $this->page();
        $filters = [
            'subj_id' => (int) $this->request->query('subj_id', 0),
            'status'  => (string) $this->request->query('status', ''),
            'keyword' => $p['keyword'],
        ];

        $result = $this->model->adminList($filters, $p['offset'], $p['per_page']);

        // 附带考生进度概览 + 组卷推算满分
        foreach ($result['data'] as &$row) {
            $row['total_questions']   = Exam::totalQuestions($row);
            $row['computed_score']    = Exam::computedTotalScore($row);
            $row['status_summary']    = $this->model->statusSummary((int) $row['id']);
        }
        unset($row);

        return $this->ok([
            'list'        => $result['data'],
            'total'       => $result['total'],
            'page'        => $p['page'],
            'per_page'    => $p['per_page'],
            'total_pages' => (int) ceil($result['total'] / max(1, $p['per_page'])),
            'options'     => $this->formOptions(),
            'statuses'    => $this->statusOptions(),
            'types'       => $this->typeMeta(),
        ]);
    }

    /** GET /api/admin/exams/{id} */
    public function show(): Response
    {
        $id = $this->idParam();
        $row = $this->model->detail($id);
        if ($row === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }

        $row['paper_plan']      = Exam::paperPlan($row);
        $row['total_questions'] = Exam::totalQuestions($row);
        $row['computed_score']  = Exam::computedTotalScore($row);
        $row['stock']           = Exam::checkStock($row);
        $row['available']       = Exam::availableCounts((int) $row['subj_id']);
        $row['status_summary']  = $this->model->statusSummary($id);

        return $this->ok($row);
    }

    /**
     * GET /api/admin/exams/quiz-count —— 组卷前校验题库容量（Ajax）
     * 查询参数即组卷参数，与保存接口同构。
     */
    public function checkQuizCount(): Response
    {
        $exam = $this->collectParams();
        $stock = Exam::checkStock($exam);

        return $this->ok([
            'ok'        => $stock['ok'],
            'shortfall' => $stock['shortfall'],
            'problems'  => array_map(
                static fn (array $s): string => sprintf(
                    '%s(%s)：题库有 %d 道，需要 %d 道',
                    Quiz::TYPE_LABELS[$s['type']] ?? $s['type'],
                    Quiz::DIFF_LABELS[$s['diff']] ?? $s['diff'],
                    $s['have'],
                    $s['need']
                ),
                $stock['shortfall']
            ),
            'total_questions' => Exam::totalQuestions($exam),
            'computed_score'  => Exam::computedTotalScore($exam),
        ]);
    }

    /** POST /api/admin/exams */
    public function save(): Response
    {
        $data = $this->collectParams();
        $this->assertValid($data, true);

        $id = $this->model->create($data + ['exam_status' => Exam::STATUS_EXAM, 'exam_pwd' => 0]);
        $this->audit('exam.create', 'exam:' . $id, ['exam_name' => $data['exam_name'] ?? '']);
        return $this->ok($this->model->detail($id), '添加成功');
    }

    /** PUT /api/admin/exams/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        $row = $this->model->find($id);
        if ($row === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if (str_starts_with((string) $row['exam_status'], 'over')) {
            throw new HttpException(409, '已结束的考试不能再修改', 40901);
        }

        $data = $this->collectParams();
        $this->assertValid($data, true);

        // 已开考的考试不允许改组卷参数（否则与已生成试卷不一致）
        if ((string) $row['exam_status'] === Exam::STATUS_TESTING) {
            $hasPaper = (int) (Database::fetch(
                'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ?',
                [$id]
            )['c'] ?? 0);
            if ($hasPaper > 0) {
                throw new HttpException(409, '该考试已开考且已生成试卷，组卷参数不可修改', 40902);
            }
        }

        // 不要把 exam_status 无条件打回 exam：出题后状态已是 paper，
        // 而 Exam::autoStartIfDue() 只在 paper 状态推进到 testing，
        // 一旦被改回 exam，到点将永不自动开考，考生会卡在等待室。
        // 状态流转只由 启动/出题/开考/结束 这些专用接口负责。
        $this->model->update($id, $data);
        $this->audit('exam.update', 'exam:' . $id, ['exam_name' => $data['exam_name'] ?? null]);
        return $this->ok($this->model->detail($id), '修改成功');
    }

    /** DELETE /api/admin/exams/{id} —— 仅未开考/未排卷的考试可删，级联清理 */
    public function delete(): Response
    {
        $id = $this->idParam();
        $row = $this->model->find($id);
        if ($row === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if (!in_array((string) $row['exam_status'], [Exam::STATUS_EXAM, Exam::STATUS_PAPER], true)) {
            throw new HttpException(409, '该考试已开考或已结束，无法删除', 40901);
        }

        Database::beginTransaction();
        try {
            $this->model->delete($id);
            Database::query('DELETE FROM `stupaper` WHERE exam_id = ?', [$id]);
            Database::query('DELETE FROM `stuscore` WHERE exam_id = ?', [$id]);
            // 备份行同样按 exam_id 关联，一并清理，避免孤儿数据
            Database::query('DELETE FROM `stuscorebak` WHERE exam_id = ?', [$id]);
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        $this->audit('exam.delete', 'exam:' . $id, ['exam_name' => $row['exam_name'] ?? '']);
        return $this->ok(null, '删除成功');
    }

    /**
     * POST /api/admin/exams/{id}/start —— 启动考试，生成考场口令
     */
    public function start(): Response
    {
        $id = $this->idParam();
        $row = $this->model->detail($id);
        if ($row === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if ((string) $row['exam_status'] === Exam::STATUS_TESTING) {
            throw new HttpException(409, '该考试已处于进行中状态', 40901);
        }
        if (str_starts_with((string) $row['exam_status'], 'over')) {
            throw new HttpException(409, '已结束的考试不能重新启动', 40902);
        }

        // 启动前必须确保题库容量充足，避免开考后考生拿到残缺试卷
        $stock = Exam::checkStock($row);
        if (!$stock['ok']) {
            throw new HttpException(
                400,
                '题库题量不足，无法开考：' . implode('；', array_map(
                    static fn (array $s): string => sprintf(
                        '%s(%s) 需 %d 道，实际 %d 道',
                        Quiz::TYPE_LABELS[$s['type']] ?? $s['type'],
                        Quiz::DIFF_LABELS[$s['diff']] ?? $s['diff'],
                        $s['need'],
                        $s['have']
                    ),
                    $stock['shortfall']
                )),
                40002,
                $stock['shortfall']
            );
        }

        $pwd = $this->model->start($id);
        $this->audit('exam.start', 'exam:' . $id, ['exam_pwd' => $pwd]);
        return $this->ok(['exam_id' => $id, 'exam_pwd' => $pwd], "考试已启动，考场口令：{$pwd}");
    }

    /**
     * POST /api/admin/exams/{id}/open —— 开放入场：生成考场口令，状态保持「未开考」。
     * 开放入场后考生才能在「开考前 15 分钟内」凭口令进入考场（等待室）。
     */
    public function open(): Response
    {
        $id = $this->idParam();
        $row = $this->model->detail($id);
        if ($row === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if (str_starts_with((string) $row['exam_status'], 'over')) {
            throw new HttpException(409, '已结束的考试不能开放入场', 40901);
        }
        if ((string) $row['exam_status'] === Exam::STATUS_TESTING) {
            throw new HttpException(409, '该考试已开考，无需再开放入场', 40902);
        }

        $pwd = $this->model->openForEntry($id);
        $this->audit('exam.open', 'exam:' . $id, ['exam_pwd' => $pwd]);
        return $this->ok(['exam_id' => $id, 'exam_pwd' => $pwd], "已开放入场，考场口令：{$pwd}");
    }

    /**
     * POST /api/admin/exams/{id}/generate —— 出题：为参考班级的全部考生预生成随机试卷
     */
    public function generatePapers(): Response
    {
        $id = $this->idParam();
        $exam = $this->model->detail($id);
        if ($exam === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if (!in_array((string) $exam['exam_status'], [Exam::STATUS_EXAM, Exam::STATUS_PAPER], true)) {
            throw new HttpException(409, '只能为未开考的考试出题', 40901);
        }
        $stuClass = trim((string) ($exam['stu_class'] ?? ''));
        if ($stuClass === '') {
            throw new HttpException(400, '该考试未设置参考班级，无法出题', 40000);
        }

        $result = ExamEngine::generateForClass($id, $exam);

        if ($result['students'] === 0) {
            throw new HttpException(400, '该班级下没有考生，请先导入考生信息', 40003);
        }

        $warnings = array_map(static fn (array $w): array => [
            'type'       => $w['type'],
            'label'      => Quiz::TYPE_LABELS[$w['type']] ?? $w['type'],
            'diff'       => $w['diff'],
            'diff_label' => Quiz::DIFF_LABELS[$w['diff']] ?? $w['diff'],
            'need'       => $w['need'],
            'have'       => $w['have'],
        ], $result['warnings']);

        return $this->ok([
            'exam_id'       => $id,
            'student_total' => $result['students'],
            'generated'     => $result['generated'],
            'skipped'       => $result['skipped'],
            'warnings'      => array_values($warnings),
        ], "出题完成：新生成 {$result['generated']} 份，跳过（已有试卷）{$result['skipped']} 份");
        $this->audit('exam.generate', 'exam:' . $id, ['students' => $result['students'], 'generated' => $result['generated']]);
    }

    /* ------------------------------------------------------------------ */

    /** 从请求参数组装考试字段（POST body 或 GET query 均可） */
    private function collectParams(): array
    {
        $ip = fn (string $k, mixed $d = null) => $this->request->input($k, $this->request->query($k, $d));

        $examDate  = trim((string) $ip('exam_date', ''));
        $startTime = $this->normalizeTime((string) $ip('exam_start_time', ''));
        $endTime   = $this->normalizeTime((string) $ip('exam_end_time', ''));

        // 兼容直接传完整 datetime 的情况
        $examStart = $this->resolveDatetime($examDate, $startTime, (string) $ip('exam_start', ''));
        $examEnd   = $this->resolveDatetime($examDate, $endTime, (string) $ip('exam_end', ''));

        // 结束时间跨天
        if ($examStart !== null && $examEnd !== null && $examEnd <= $examStart) {
            $examEnd = date('Y-m-d H:i:s', strtotime($examEnd . ' +1 day') ?: time());
        }

        $subjId = (int) $ip('subj_id', 0);
        $data = [
            'exam_name'        => trim((string) $ip('exam_name', '')),
            'exam_class'       => trim((string) $ip('exam_class', '')),
            'exam_category_id' => (int) $ip('exam_category_id', 0),
            'subj_id'          => $subjId,
            'exam_start'       => $examStart,
            'exam_end'         => $examEnd,
            'exam_tea'         => trim((string) $ip('exam_tea', '')),
            'stu_class'        => $this->resolveClasses($ip('stu_class', '')),
        ];

        foreach (Exam::TYPE_PREFIXES as $type) {
            $data["{$type}_easy_sum"] = (int) $ip("{$type}_easy_sum", 0);
            $data["{$type}_mid_sum"]  = (int) $ip("{$type}_mid_sum", 0);
            $data["{$type}_hard_sum"] = (int) $ip("{$type}_hard_sum", 0);
            $data["{$type}_val"]      = (int) $ip("{$type}_val", 0);
        }

        $data['exam_score'] = Exam::computedTotalScore($data);
        return $data;
    }

    /** 参考班级：数组 → 逗号分隔字符串；班级名称统一折算为班级 ID */
    private function resolveClasses(mixed $raw): string
    {
        return SchoolClass::resolveIds($raw);
    }

    /** 时间归一：1750 / 17：50 / 17:50 → 17:50 */
    private function normalizeTime(string $time): string
    {
        $time = str_replace('：', ':', trim($time));
        $digits = preg_replace('/[^0-9]/', '', $time) ?? '';
        if (strlen($digits) === 4 && !str_contains($time, ':')) {
            return substr($digits, 0, 2) . ':' . substr($digits, 2, 2);
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return $time;
        }
        return $time;
    }

    /** 组合日期与时间为 datetime；已是完整 datetime 则直接采用 */
    private function resolveDatetime(string $date, string $time, string $full): ?string
    {
        if ($full !== '' && str_contains($full, '-') && str_contains($full, ':')) {
            $ts = strtotime($full);
            return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        }
        if ($date === '' || $time === '') {
            return null;
        }
        $ts = strtotime($date . ' ' . $time . ':00');
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** 保存前校验：必填 + 题库容量 */
    private function assertValid(array $data, bool $strictStock): void
    {
        if ($data['exam_name'] === '') {
            throw new HttpException(400, '请输入考试名称', 40000);
        }
        if ((int) $data['subj_id'] <= 0) {
            throw new HttpException(400, '请选择考试科目', 40000);
        }
        if ((new Subject())->find((int) $data['subj_id']) === null) {
            throw new HttpException(400, '所选科目不存在', 40001);
        }
        if ($data['exam_start'] === null || $data['exam_end'] === null) {
            throw new HttpException(400, '请填写完整的考试开始与结束时间', 40002);
        }
        if (Exam::totalQuestions($data) <= 0) {
            throw new HttpException(400, '请至少配置一道题目的抽题数量', 40003);
        }
        foreach (Exam::TYPE_PREFIXES as $type) {
            $n = $data["{$type}_easy_sum"] + $data["{$type}_mid_sum"] + $data["{$type}_hard_sum"];
            if ($n > 0 && (int) $data["{$type}_val"] <= 0) {
                throw new HttpException(400, sprintf(
                    '%s 已配置抽题数量，但每题分值必须大于 0',
                    Quiz::TYPE_LABELS[$type] ?? $type
                ), 40004);
            }
        }

        if ($strictStock) {
            $stock = Exam::checkStock($data);
            if (!$stock['ok']) {
                throw new HttpException(
                    400,
                    '题库题量不足：' . implode('；', array_map(
                        static fn (array $s): string => sprintf(
                            '%s(%s)：题库有 %d 道，需要 %d 道',
                            Quiz::TYPE_LABELS[$s['type']] ?? $s['type'],
                            Quiz::DIFF_LABELS[$s['diff']] ?? $s['diff'],
                            $s['have'],
                            $s['need']
                        ),
                        $stock['shortfall']
                    )),
                    40005,
                    $stock['shortfall']
                );
            }
        }
    }

    /** 表单下拉选项 */
    private function formOptions(): array
    {
        return [
            'subjects'   => (new Subject())->all('id ASC'),
            'teachers'   => array_map(
                static fn (array $t): array => ['id' => $t['id'], 'tea_name' => $t['tea_name']],
                (new Teacher())->all('id ASC')
            ),
            'grades'     => (new Grade())->all('id ASC'),
            'classes'    => (new SchoolClass())->all('id ASC'),
            'categories' => (new ExamCategory())->ordered(),
        ];
    }

    /** 状态选项 */
    private function statusOptions(): array
    {
        return [
            ['value' => Exam::STATUS_EXAM,     'label' => '未开考'],
            ['value' => Exam::STATUS_PAPER,    'label' => '已排卷'],
            ['value' => Exam::STATUS_TESTING,  'label' => '进行中'],
            ['value' => 'over',                'label' => '已结束'],
            ['value' => Exam::STATUS_OVER_BAK, 'label' => '已备份'],
        ];
    }

    /** 题型元信息（前端渲染组卷表格用） */
    private function typeMeta(): array
    {
        $out = [];
        foreach (Exam::TYPE_PREFIXES as $t) {
            $out[] = ['value' => $t, 'label' => Quiz::TYPE_LABELS[$t] ?? $t];
        }
        return $out;
    }
}
