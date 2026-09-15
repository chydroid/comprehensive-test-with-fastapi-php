<?php

declare(strict_types=1);

namespace App\Controllers;

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
 * 教师端 —— 考试管理
 *
 * 监考教师在考试期间可创建与维护考试（旧系统的行为：教师端可增删改考试）。
 * 与管理员端的主要差异：
 * - **不做**题库容量强校验（沿用旧系统行为：教师端允许先配参数、后补题）
 * - 不能删除已开考/已结束的考试
 * - 教师只能看到自己负责或被指定监考的考试列表（exam_tea 匹配）
 *
 * 试卷组卷参数、状态取值与字段命名完全同 App\Controllers\Admin\ExamController。
 */
class TeacherExamController extends BaseController
{
    private Exam $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new Exam();
    }

    /** GET /api/teacher/exams —— 我负责的考试 */
    public function index(): Response
    {
        $sess = $this->authTeacher();
        $teaName = (string) ($sess['tea_name'] ?? '');

        $p = $this->page();
        $rows = Database::fetchAll(
            'SELECT e.*, s.subj_name, c.category_name
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             LEFT JOIN `exam_category` c ON c.id = e.exam_category_id
             WHERE e.exam_tea = ?
             ORDER BY e.id DESC
             LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'],
            [$teaName]
        );
        $total = (int) (Database::fetch(
            'SELECT COUNT(*) AS c FROM `examinfo` WHERE exam_tea = ?',
            [$teaName]
        )['c'] ?? 0);

        foreach ($rows as &$row) {
            $row['total_questions'] = Exam::totalQuestions($row);
            $row['computed_score']  = Exam::computedTotalScore($row);
            $row['status_summary']  = $this->model->statusSummary((int) $row['id']);
        }
        unset($row);

        return $this->ok([
            'list'        => $rows,
            'total'       => $total,
            'page'        => $p['page'],
            'per_page'    => $p['per_page'],
            'total_pages' => (int) ceil($total / max(1, $p['per_page'])),
            'options'     => $this->formOptions(),
            'types'       => $this->typeMeta(),
        ]);
    }

    /**
     * 归属校验：教师只能操作自己负责（exam_tea 与登录教师同名）的考试。
     *
     * 此前各 {id} 方法只取路径 id 就直接操作，既没读教师会话也没比对 exam_tea，
     * 导致教师之间可水平越权：读取他人考试的考场口令、把他人考试过户到自己名下、
     * 删除他人考试（级联删试卷与成绩）、导出他人班级考生成绩。
     */
    private function assertOwnExam(int $id): array
    {
        $sess = $this->authTeacher();
        $teaName = (string) ($sess['tea_name'] ?? '');

        $row = $this->model->find($id);
        if ($row === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        // 用「不存在」而非「无权限」回应，避免通过响应码探测他人考试编号是否存在
        if ((string) ($row['exam_tea'] ?? '') !== $teaName) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        return $row;
    }

    /** GET /api/teacher/exams/{id} */
    public function show(): Response
    {
        $id = $this->idParam();
        $this->assertOwnExam($id);
        $row = $this->model->detail($id);
        if ($row === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        $row['paper_plan']      = Exam::paperPlan($row);
        $row['total_questions'] = Exam::totalQuestions($row);
        $row['computed_score']  = Exam::computedTotalScore($row);
        $row['available']       = Exam::availableCounts((int) $row['subj_id']);
        $row['status_summary']  = $this->model->statusSummary($id);
        return $this->ok($row);
    }

    /** GET /api/teacher/exams/{id}/quiz-count —— 组卷前题量校验（只读，不阻断） */
    public function checkQuizCount(): Response
    {
        $source = $this->request->query('subj_id', 0) !== 0
            ? 'query'
            : 'input';
        $ip = fn (string $k, mixed $d = null) => $source === 'query'
            ? $this->request->query($k, $d)
            : $this->request->input($k, $d);

        $exam = [
            'subj_id' => (int) $ip('subj_id', 0),
        ];
        foreach (Exam::TYPE_PREFIXES as $type) {
            $exam["{$type}_easy_sum"] = (int) $ip("{$type}_easy_sum", 0);
            $exam["{$type}_mid_sum"]  = (int) $ip("{$type}_mid_sum", 0);
            $exam["{$type}_hard_sum"] = (int) $ip("{$type}_hard_sum", 0);
            $exam["{$type}_val"]      = (int) $ip("{$type}_val", 0);
        }

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

    /** POST /api/teacher/exams */
    public function save(): Response
    {
        $sess = $this->authTeacher();
        $data = $this->collectParams();

        // 教师创建考试时，监考教师默认填自己
        if ($data['exam_tea'] === '') {
            $data['exam_tea'] = (string) ($sess['tea_name'] ?? '');
        }
        $this->assertValid($data);

        $id = $this->model->create($data + ['exam_status' => Exam::STATUS_EXAM, 'exam_pwd' => 0]);
        return $this->ok($this->model->detail($id), '添加成功');
    }

    /** PUT /api/teacher/exams/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        $row = $this->assertOwnExam($id);
        if (str_starts_with((string) $row['exam_status'], 'over')) {
            throw new HttpException(409, '已结束的考试不能修改', 40901);
        }

        $data = $this->collectParams();
        $this->assertValid($data);

        $hasPaper = (int) (Database::fetch(
            'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ?',
            [$id]
        )['c'] ?? 0);
        if ($hasPaper > 0) {
            throw new HttpException(409, '该考试已生成试卷，组卷参数不可修改', 40902);
        }

        $this->model->update($id, $data + ['exam_status' => Exam::STATUS_EXAM]);
        return $this->ok($this->model->detail($id), '修改成功');
    }

    /** POST /api/teacher/exams/{id}/start —— 开考 */
    public function start(): Response
    {
        $id = $this->idParam();
        $row = $this->assertOwnExam($id);
        if ((string) $row['exam_status'] === Exam::STATUS_TESTING) {
            throw new HttpException(409, '该考试已处于进行中状态', 40901);
        }
        if (str_starts_with((string) $row['exam_status'], 'over')) {
            throw new HttpException(409, '已结束的考试不能重新启动', 40902);
        }

        $pwd = $this->model->start($id);
        return $this->ok(['exam_id' => $id, 'exam_pwd' => $pwd], "考试已开考，考场口令：{$pwd}");
    }

    /**
     * POST /api/teacher/exams/{id}/open —— 开放入场：生成考场口令（状态保持未开考）
     */
    public function open(): Response
    {
        $id = $this->idParam();
        $row = $this->assertOwnExam($id);
        if (str_starts_with((string) $row['exam_status'], 'over')) {
            throw new HttpException(409, '已结束的考试不能开放入场', 40901);
        }
        if ((string) $row['exam_status'] === Exam::STATUS_TESTING) {
            throw new HttpException(409, '该考试已开考，无需再开放入场', 40902);
        }

        $pwd = $this->model->openForEntry($id);
        return $this->ok(['exam_id' => $id, 'exam_pwd' => $pwd], "已开放入场，考场口令：{$pwd}");
    }

    /**
     * POST /api/teacher/exams/{id}/generate —— 出题：为参考班级全部考生生成随机试卷
     */
    public function generatePapers(): Response
    {
        $id = $this->idParam();
        $exam = $this->assertOwnExam($id);
        if (!in_array((string) $exam['exam_status'], [Exam::STATUS_EXAM, Exam::STATUS_PAPER], true)) {
            throw new HttpException(409, '只能为未开考的考试出题', 40901);
        }
        if (trim((string) ($exam['stu_class'] ?? '')) === '') {
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
    }

    /** DELETE /api/teacher/exams/{id} */
    public function delete(): Response
    {
        $id = $this->idParam();
        $row = $this->assertOwnExam($id);
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

        return $this->ok(null, '删除成功');
    }

    /** GET /api/teacher/exams/{id}/students —— 考生名单 */
    public function students(): Response
    {
        $examId = $this->idParam();
        $exam = $this->assertOwnExam($examId);

        // 若已排卷，返回实际考生成绩名单；否则按参考班级列出候选人
        $scored = Database::fetchAll(
            'SELECT si.id AS stu_id, si.stu_name, si.stu_sex, si.grade_id, si.class_id,
                    ss.stu_score, ss.stu_status
             FROM `stuinfo` si
             INNER JOIN `stuscore` ss ON si.id = ss.stu_id
             WHERE ss.exam_id = ?
             ORDER BY si.id ASC',
            [$examId]
        );

        if ($scored !== []) {
            return $this->ok([
                'exam_id'   => $examId,
                'source'    => 'paper',
                'total'     => count($scored),
                'list'      => $scored,
                'summary'   => $this->model->statusSummary($examId),
            ]);
        }

        $classIds = array_values(array_filter(
            array_map('trim', explode(',', (string) ($exam['stu_class'] ?? ''))),
            static fn ($v) => $v !== ''
        ));
        $candidates = $classIds === [] ? [] : (new Student())->byClassIds($classIds);

        return $this->ok([
            'exam_id' => $examId,
            'source'  => 'class',
            'total'   => count($candidates),
            'list'    => $candidates,
            'summary' => $this->model->statusSummary($examId),
        ]);
    }

    /** GET /api/teacher/scores —— 成绩列表 */
    public function scores(): Response
    {
        $examId = (int) $this->request->query('exam_id', $this->request->query('id', 0));
        // 只列本教师名下的已结束考试：否则下拉里会出现他人考试，
        // 选中后 assertOwnExam 404 且前端静默失败成空白页。
        $teaName = (string) ($this->authTeacher()['tea_name'] ?? '');
        $exams = (new Exam())->finishedList($teaName);

        if ($examId <= 0) {
            return $this->ok(['exams' => $exams, 'exam_id' => 0, 'list' => []]);
        }
        $this->assertOwnExam($examId);

        return $this->ok([
            'exams'   => $exams,
            'exam_id' => $examId,
            'exam'    => $this->model->detail($examId),
            'list'    => Database::fetchAll(
                'SELECT si.id AS stu_id, si.stu_name, si.grade_id, si.class_id, si.stu_sex,
                        ss.stu_score, ss.stu_status
                 FROM `stuinfo` si
                 INNER JOIN `stuscore` ss ON si.id = ss.stu_id
                 WHERE ss.exam_id = ?
                 ORDER BY si.id ASC',
                [$examId]
            ),
        ]);
    }

    /** GET /api/teacher/scores/export?exam_id=N —— 导出 CSV（不含考场口令） */
    public function exportScores(): Response
    {
        $examId = (int) $this->request->query('exam_id', $this->request->query('id', 0));
        if ($examId <= 0) {
            throw new HttpException(400, '请选择要导出的考试', 40000);
        }
        $exam = $this->assertOwnExam($examId);

        $rows = Database::fetchAll(
            'SELECT si.id AS stu_id, si.stu_name, si.grade_id, si.class_id,
                    ss.stu_score, ss.stu_status
             FROM `stuinfo` si
             INNER JOIN `stuscore` ss ON si.id = ss.stu_id
             WHERE ss.exam_id = ?
             ORDER BY si.id ASC',
            [$examId]
        );

        $statusMap = ['waiting' => '等待', 'online' => '在线', 'locked' => '锁定', 'over' => '已交卷'];
        $buffer = fopen('php://temp', 'r+');
        fwrite($buffer, "\xEF\xBB\xBF");
        fputcsv($buffer, ['准考证号', '姓名', '单位', '班级', '成绩', '状态']);
        foreach ($rows as $r) {
            $status = (string) ($r['stu_status'] ?? '');
            $base = explode(':', $status)[0];
            fputcsv($buffer, [
                (string) $r['stu_id'],
                (string) $r['stu_name'],
                (string) $r['grade_id'],
                (string) $r['class_id'],
                (int) $r['stu_score'],
                $statusMap[$base] ?? $status,
            ]);
        }
        rewind($buffer);
        $content = (string) stream_get_contents($buffer);
        fclose($buffer);

        $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', (string) ($exam['exam_name'] ?? ('exam_' . $examId)));

        return $this->response->downloadText($content, '成绩_' . $examId . '_' . trim((string) $name) . '.csv');
    }

    /* ------------------------------------------------------------------ */

    /** 组装考试字段（GET query 或 POST body） */
    private function collectParams(): array
    {
        $ip = fn (string $k, mixed $d = null) => $this->request->input($k, $this->request->query($k, $d));

        $examDate  = trim((string) $ip('exam_date', ''));
        $startTime = $this->normalizeTime((string) $ip('exam_start_time', ''));
        $endTime   = $this->normalizeTime((string) $ip('exam_end_time', ''));

        $examStart = $this->resolveDatetime($examDate, $startTime, (string) $ip('exam_start', ''));
        $examEnd   = $this->resolveDatetime($examDate, $endTime, (string) $ip('exam_end', ''));

        if ($examStart !== null && $examEnd !== null && $examEnd <= $examStart) {
            $examEnd = date('Y-m-d H:i:s', strtotime($examEnd . ' +1 day') ?: time());
        }

        $data = [
            'exam_name'        => trim((string) $ip('exam_name', '')),
            'exam_class'       => trim((string) $ip('exam_class', '')),
            'exam_category_id' => (int) $ip('exam_category_id', 0),
            'subj_id'          => (int) $ip('subj_id', 0),
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

    /** 教师端宽松校验：必填 + 分值合法性（不校验题库容量） */
    private function assertValid(array $data): void
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
    }

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

    private function typeMeta(): array
    {
        $out = [];
        foreach (Exam::TYPE_PREFIXES as $t) {
            $out[] = ['value' => $t, 'label' => Quiz::TYPE_LABELS[$t] ?? $t];
        }
        return $out;
    }
}
