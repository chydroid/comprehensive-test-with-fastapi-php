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
use App\Services\ExamComposer;
use App\Services\ExamEngine;
use App\Services\ExamRetake;
use App\Services\ScoreAnalysis;
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
        // 模拟考试是考生自主生成的临时记录，不属于「考试管理」范畴，必须整体排除：
        // 否则教师端考试列表与列表上方的 total 都会被考生的练习行为污染。
        $rows = Database::fetchAll(
            'SELECT e.*, s.subj_name, c.category_name
             FROM `examinfo` e
             INNER JOIN `subject` s ON s.id = e.subj_id
             LEFT JOIN `exam_category` c ON c.id = e.exam_category_id
             WHERE e.exam_tea = ? AND COALESCE(e.exam_class, \'\') <> ?
             ORDER BY e.id DESC
             LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'],
            [$teaName, Exam::MOCK_CLASS]
        );
        $total = (int) (Database::fetch(
            'SELECT COUNT(*) AS c FROM `examinfo`
             WHERE exam_tea = ? AND COALESCE(exam_class, \'\') <> ?',
            [$teaName, Exam::MOCK_CLASS]
        )['c'] ?? 0);

        foreach ($rows as &$row) {
            // 列表访问时收敛「完全无人访问的过期考场」：与管理端同理，
            // 无常驻 cron，过期考场只能靠请求触发；教师端列表是高频访问入口。
            if ((string) ($row['exam_status'] ?? '') === Exam::STATUS_TESTING) {
                if (Exam::autoEndIfDue((int) $row['id'])) {
                    $row['exam_status'] = 'over';
                }
            }
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
        // 模拟考试整体排除在教师端考试管理之外。此处统一兜底，使 show/update/
        // delete/students/quiz-count 等全部 {id} 接口一次性拒绝，避免「列表不展示
        // 但直连 id 仍可操作」的旁路（例如误删考生的模拟记录）。
        if ((string) ($row['exam_class'] ?? '') === Exam::MOCK_CLASS) {
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
        $row['paper_mode_info'] = Exam::paperMode($row);
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

        // 教师只能创建自己负责的考试：忽略前端传入的 exam_tea，强制归属本人，
        // 防止横向把考试挂到他人名下（BUG-241）。
        $data['exam_tea'] = (string) ($sess['tea_name'] ?? '');
        $this->assertValid($data, true);

        $id = $this->model->create($data + ['exam_status' => Exam::STATUS_EXAM, 'exam_pwd' => 0]);
        $this->persistPaperMode($id, $data);
        $this->recomputeScore($id, $data);
        $this->audit('exam.create', 'exam:' . $id, ['exam_name' => $data['exam_name'] ?? '', 'actor' => 'teacher']);
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

        // 编辑考试不应改写状态：状态流转只交由 open/start/generatePapers/over 等
        // 专用接口负责；此处注入 exam_status 会破坏已出题考试的惰性自动开考链路
        // （autoStartIfDue 仅推进 paper 状态）。与管理端 update 行为保持一致（BUG-242）。
        $this->model->update($id, $data);
        $this->persistPaperMode($id, $data);
        $this->recomputeScore($id, $data);
        $this->audit('exam.update', 'exam:' . $id, ['exam_name' => $data['exam_name'] ?? null, 'actor' => 'teacher']);
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
        $this->audit('exam.start', 'exam:' . $id, ['exam_pwd' => $pwd, 'actor' => 'teacher']);
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
        $this->audit('exam.open', 'exam:' . $id, ['exam_pwd' => $pwd, 'actor' => 'teacher']);
        return $this->ok(['exam_id' => $id, 'exam_pwd' => $pwd], "已开放入场，考场口令：{$pwd}");
    }

    /**
     * GET /api/teacher/exams/{id}/retake-candidates —— 补考候选名单（C2）
     */
    public function retakeCandidates(): Response
    {
        $id = $this->idParam();
        $this->assertOwnExam($id);
        $percent = $this->request->query('pass_percent');
        return $this->ok(ExamRetake::candidates($id, $percent !== null ? (int) $percent : null));
    }

    /**
     * POST /api/teacher/exams/{id}/retake —— 生成补考场次（C2）
     *
     * 与教师新建考试同一约束：补考场次强制归属本人（忽略前端传入的 exam_tea），
     * 否则教师可以借补考入口把考试挂到他人名下（BUG-241 同型缺陷）。
     */
    public function retake(): Response
    {
        $id = $this->idParam();
        $this->assertOwnExam($id);
        $sess = $this->authTeacher();

        $in = $this->validate([
            'exam_start'   => 'required|maxlen:32',
            'exam_end'     => 'required|maxlen:32',
            'pass_percent' => 'integer|min:1|max:100',
            'allow_passed' => 'integer',
        ]);
        // 通用 validate() 拒绝数组值（"必须是标量值"），名单只能手工取
        $stuIds = $this->request->input('stu_ids', []);
        if (is_string($stuIds)) {
            $decoded = json_decode($stuIds, true);
            $stuIds = is_array($decoded) ? $decoded : explode(',', $stuIds);
        }
        if (!is_array($stuIds) || $stuIds === []) {
            throw new HttpException(400, '请至少选择一名参加补考的考生', 40000);
        }

        $result = ExamRetake::create($id, $stuIds, [
            'exam_start'   => (string) $in['exam_start'],
            'exam_end'     => (string) $in['exam_end'],
            'exam_tea'     => (string) ($sess['tea_name'] ?? ''),
            'pass_percent' => isset($in['pass_percent']) ? (int) $in['pass_percent'] : null,
            'allow_passed' => !empty($in['allow_passed']),
        ]);

        $this->audit('exam.retake', 'exam:' . $result['exam_id'], [
            'source_exam' => $id,
            'exam_name'   => $result['exam_name'],
            'roster'      => $result['roster'],
            'actor'       => 'teacher',
        ]);

        return $this->ok($result, "已生成补考「{$result['exam_name']}」，共 {$result['roster']} 名考生");
    }

    /**
     * POST /api/teacher/exams/{id}/generate —— 出题：为已进入考场的考生生成随机试卷（无考生入场时提示「无需出卷」）
     */
    public function generatePapers(): Response
    {
        $id = $this->idParam();
        $exam = $this->assertOwnExam($id);
        if (!in_array((string) $exam['exam_status'], [Exam::STATUS_EXAM, Exam::STATUS_PAPER], true)) {
            throw new HttpException(409, '只能为未开考的考试出题', 40901);
        }
        // 名单来源与 ExamEngine 同口径：普通场次按参考班级展开，补考场次按名单。
        // 此前只检查 stu_class，补考（按名单开考）会被「未设置参考班级」误挡。
        if (Exam::studentIdsForExam($exam) === []) {
            throw new HttpException(
                400,
                Exam::isRetake($exam) ? '该补考场次没有考生名单，无法出题' : '该考试未设置参考班级，无法出题',
                40000
            );
        }

        $result = ExamEngine::generateForClass($id, $exam);
        // 考场无人入场：不应强行给全班出卷，提示监考即可（出卷只给已进入考场的考生）。
        if ($result['entered'] === 0) {
            // 不出卷，但「出题」代表监考已就绪：必须推进 exam → paper，
            // 否则此后入场的考生无卷、整场也无法到点自动开考（见 ExamEngine::markReady）。
            ExamEngine::markReady($id);
            return $this->ok([
                'exam_id'       => $id,
                'student_total' => 0,
                'entered'       => 0,
                'generated'     => 0,
                'skipped'       => 0,
                'warnings'      => [],
            ], '本考场暂无考生入场，无需出卷');
        }

        $warnings = array_map(static fn (array $w): array => [
            'type'       => $w['type'],
            'label'      => Quiz::TYPE_LABELS[$w['type']] ?? $w['type'],
            'diff'       => $w['diff'],
            'diff_label' => Quiz::DIFF_LABELS[$w['diff']] ?? $w['diff'],
            'need'       => $w['need'],
            'have'       => $w['have'],
        ], $result['warnings']);

        $this->audit('exam.generate', 'exam:' . $id, ['students' => $result['students'], 'entered' => $result['entered'], 'generated' => $result['generated'], 'actor' => 'teacher']);

        return $this->ok([
            'exam_id'       => $id,
            'student_total' => $result['students'],
            'entered'       => $result['entered'],
            'generated'     => $result['generated'],
            'skipped'       => $result['skipped'],
            'warnings'      => array_values($warnings),
        ], "出题完成：为 {$result['entered']} 名已入场考生生成 {$result['generated']} 份，跳过（已有试卷）{$result['skipped']} 份");
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
            // 补考名单同样以 exam_id 关联；不清理会留下指向已删考试的孤儿行
            Database::query('DELETE FROM `exam_retake_stu` WHERE exam_id = ?', [$id]);
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        $this->audit('exam.delete', 'exam:' . $id, ['exam_name' => $row['exam_name'] ?? '', 'actor' => 'teacher']);
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

    /** GET /api/teacher/exams/{id}/analysis —— 成绩与学情分析（A2） */
    public function analysis(): Response
    {
        $id = $this->idParam();
        $this->assertOwnExam($id);

        // 及格线默认取后台「考试规则 → 及格线」，与补考名单筛选同口径（可按请求参数覆盖）
        $passLine      = (int) $this->request->query('pass_line', Exam::passPercent());
        $excellentLine = (int) $this->request->query('excellent_line', 85);
        $weakLimit     = (int) $this->request->query('weak_limit', 10);

        return $this->ok(ScoreAnalysis::analyze($id, $passLine, $excellentLine, $weakLimit));
    }

    /**
     * POST /api/teacher/exams/{id}/compose —— C4 AI 智能组卷（只读建议）。
     *
     * 用 POST 而非 GET：本接口可能触发外部模型调用（有费用、有副作用配额），
     * 用 GET 会被浏览器预取、爬虫、缓存中间件无意间重复触发。
     *
     * 只产出建议题目列表，不改考试、不写库 —— 真正成卷要走 applyComposition()。
     */
    public function compose(): Response
    {
        $exam = $this->assertOwnExam($this->idParam());

        $spec = [
            'count' => (int) $this->request->input('count', 10),
            'easy'  => (int) $this->request->input('easy', 3),
            'mid'   => (int) $this->request->input('mid', 4),
            'hard'  => (int) $this->request->input('hard', 3),
            'kps'   => (array) $this->request->input('kps', []),
            'types' => (array) $this->request->input('types', []),
        ];

        $result = ExamComposer::suggest((int) $exam['id'], $spec);

        return $this->ok($result);
    }

    /**
     * POST /api/teacher/exams/{id}/apply-composition —— C4 采用 AI 组卷建议。
     *
     * 教师勾选建议题目后调用：选中题落库（远程新题先入库）、设为 manual 组卷、
     * 回填满分。考试已开始（testing/over）则拒绝（409）。
     */
    public function applyComposition(): Response
    {
        $exam = $this->assertOwnExam($this->idParam());

        $raw = $this->request->input('questions', []);
        if (!is_array($raw)) {
            throw new HttpException(400, '请提交要采用的题目列表', 40000);
        }

        $result = ExamComposer::apply((int) $exam['id'], $raw);

        $this->audit('exam.compose.apply', 'exam:' . $exam['id'], [
            'actor'   => 'teacher',
            'applied' => $result['applied'],
            'skipped' => $result['skipped'],
            'new'     => $result['new'],
        ]);

        return $this->ok($result, '已采用 AI 组卷建议');
    }

    /** GET /api/teacher/quiz-search —— 手动选题：题库检索（科目/题型/知识点/关键字） */
    public function quizSearch(): Response
    {
        $this->authTeacher();
        $subjId = (int) $this->request->query('subj_id', 0);
        $class   = trim((string) $this->request->query('quiz_class', ''));
        $kp      = trim((string) $this->request->query('quiz_kp', ''));
        $keyword = trim((string) $this->request->query('keyword', ''));
        $p = $this->page();

        $where = ['q.subj_id = ?'];
        $params = [$subjId];
        if ($class !== '') {
            $where[] = 'q.quiz_class = ?';
            $params[] = $class;
        }
        if ($kp !== '') {
            $where[] = 'q.quiz_kp = ?';
            $params[] = $kp;
        }
        if ($keyword !== '') {
            $where[] = '(q.quiz_title LIKE ? OR q.quiz_key LIKE ?)';
            $kw = '%' . addcslashes($keyword, '%_\\') . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        $sqlWhere = implode(' AND ', $where);

        $total = (int) (Database::fetch("SELECT COUNT(*) AS c FROM `quizlib` q WHERE {$sqlWhere}", $params)['c'] ?? 0);
        $rows = Database::fetchAll(
            "SELECT q.id, q.quiz_title, q.quiz_class, q.quiz_diff, q.quiz_kp, q.quiz_option, s.subj_name
             FROM `quizlib` q INNER JOIN `subject` s ON s.id = q.subj_id
             WHERE {$sqlWhere} ORDER BY q.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}",
            $params
        );
        foreach ($rows as &$r) {
            $r['quiz_type_label']  = Quiz::TYPE_LABELS[$r['quiz_class']] ?? '';
            $r['quiz_diff_label']  = Quiz::DIFF_LABELS[$r['quiz_diff']] ?? '';
            $r['quiz_option_list'] = Quiz::parseOptions((string) ($r['quiz_option'] ?? ''));
            unset($r['quiz_option']);
        }
        unset($r);

        return $this->ok([
            'list'        => $rows,
            'total'       => $total,
            'page'        => $p['page'],
            'per_page'    => $p['per_page'],
            'total_pages' => (int) ceil($total / max(1, $p['per_page'])),
        ]);
    }

    /** GET /api/teacher/quiz-kps —— 某科目下的知识点列表与可用题量（按知识点组卷用） */
    public function quizKps(): Response
    {
        $this->authTeacher();
        $subjId = (int) $this->request->query('subj_id', 0);
        $rows = Database::fetchAll(
            "SELECT quiz_kp, COUNT(*) AS c FROM `quizlib` WHERE subj_id = ? AND quiz_kp <> '' GROUP BY quiz_kp ORDER BY quiz_kp",
            [$subjId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['kp' => (string) $r['quiz_kp'], 'count' => (int) $r['c']];
        }
        return $this->ok(['list' => $out]);
    }

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

        // A3 组卷多样化：组卷模式（random / manual / by_kp）
        $mode = trim((string) $ip('paper_mode', 'random'));
        if (!in_array($mode, Exam::PAPER_MODES, true)) {
            $mode = 'random';
        }
        $data['paper_mode'] = $mode;

        foreach (Exam::TYPE_PREFIXES as $type) {
            $data["{$type}_easy_sum"] = (int) $ip("{$type}_easy_sum", 0);
            $data["{$type}_mid_sum"]  = (int) $ip("{$type}_mid_sum", 0);
            $data["{$type}_hard_sum"] = (int) $ip("{$type}_hard_sum", 0);
            $data["{$type}_val"]      = (int) $ip("{$type}_val", 0);
        }

        // 手动选题 / 按知识点比例 的明细随考试一起保存（存入独立表，非 examinfo 列）
        if ($mode === 'manual') {
            $data['_manual_ids'] = $this->parseIntList($ip('manual_ids', []));
        } elseif ($mode === 'by_kp') {
            $data['_kp_plan'] = $this->parseKpPlan($ip('kp_plan', []));
        }

        // 成绩公示粒度（文档 B4）：非法值一律回落 private —— 宁可少公开，不可误公开
        $vis = trim((string) $ip('score_visibility', Exam::VIS_PRIVATE));
        $data['score_visibility'] = in_array($vis, Exam::SCORE_VISIBILITIES, true) ? $vis : Exam::VIS_PRIVATE;

        // 电子证书达标分（C1）：0 = 本场不发放证书
        $data['cert_threshold'] = max(0, (int) $ip('cert_threshold', 0));

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

    /**
     * 教师端宽松校验：必填 + 分值合法性（不校验题库容量）。
     *
     * @param bool $isCreate 创建态放宽：允许「先建空考试、再由 AI 组卷 / 手动编辑器补题」，
     *                       因此不强制结束时间与抽题数量；名称 / 科目 / 开始时间仍然必填。
     *                       编辑态保持严格——防止把正式考试的题目改空或时间窗口改缺。
     */
    private function assertValid(array $data, bool $isCreate = false): void
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
        // 开始时间始终必填；结束时间在创建态可为空（草稿考试，开考前再补；
        // 模型层 exam_end 为 NULL/空串即「不过期」），编辑态仍强制以保证时间窗口完整。
        if ($data['exam_start'] === null) {
            throw new HttpException(400, '请填写考试开始时间', 40002);
        }
        if (!$isCreate && $data['exam_end'] === null) {
            throw new HttpException(400, '请填写考试结束时间', 40002);
        }

        // 手动选题 / 按知识点计划的明细校验：创建态与编辑态都执行（所选题目合法性不能放宽）。
        // 仅 random 模式的「至少一道题」在创建态放宽（见下方 else 分支），以允许 AI 组卷草稿。
        $mode = (string) ($data['paper_mode'] ?? 'random');
        if ($mode === 'manual') {
            $ids = $data['_manual_ids'] ?? [];
            if (count($ids) <= 0) {
                throw new HttpException(400, '手动选题模式请至少选择一道题目', 40003);
            }
            // 校验所选题目均存在且归属于本考试科目，避免跨科目组卷
            $ph = implode(',', $ids);
            $rows = \Core\Database::fetchAll("SELECT id, subj_id, quiz_class FROM `quizlib` WHERE id IN ({$ph})");
            if (count($rows) !== count($ids)) {
                throw new HttpException(400, '存在无效或已删除的题目', 40005);
            }
            foreach ($rows as $r) {
                if ((int) $r['subj_id'] !== (int) $data['subj_id']) {
                    throw new HttpException(400, '所选题目必须与考试科目一致', 40006);
                }
                $type = (string) $r['quiz_class'];
                if ((int) ($data["{$type}_val"] ?? 0) <= 0) {
                    throw new HttpException(400, sprintf(
                        '%s 在手动选题中已选用，其每题分值必须大于 0',
                        Quiz::TYPE_LABELS[$type] ?? $type
                    ), 40004);
                }
            }
        } elseif ($mode === 'by_kp') {
            $plan = $data['_kp_plan'] ?? [];
            $sum = 0;
            foreach ($plan as $p) {
                $sum += (int) ($p['cnt'] ?? 0);
            }
            if ($sum <= 0) {
                throw new HttpException(400, '按知识点比例模式请至少为某个知识点配置抽题数量', 40003);
            }
        } else {
            // random 模式：编辑态要求至少一道题；创建态允许「0 题草稿」，
            // 由 AI 组卷（composeApply）或手动选题编辑器后续补入，
            // 否则新建考试点「AI 智能组卷」会因尚未配题被 400 卡死。
            if (!$isCreate && Exam::totalQuestions($data) <= 0) {
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

        // 证书达标分不得高于满分，否则本场永远发不出证书（静默失效，最难排查）。
        // by_kp 模式在首次出题前 exam_score 还是 0，那种情况无从判定，跳过。
        $total = (int) ($data['exam_score'] ?? 0);
        if ((int) ($data['cert_threshold'] ?? 0) > 0 && $total > 0 && (int) $data['cert_threshold'] > $total) {
            throw new HttpException(400, sprintf(
                '证书达标分（%d）不能高于本场满分（%d）',
                (int) $data['cert_threshold'],
                $total
            ), 40006);
        }
    }

    /**
     * 保存/更新后固化组卷模式明细（手动选题 / 按知识点计划）。
     * 先清后写，保证编辑时覆盖旧明细；包在调用方事务之外、独立执行（明细非考试主行）。
     */
    private function persistPaperMode(int $examId, array $data): void
    {
        $mode = (string) ($data['paper_mode'] ?? 'random');
        if ($mode === 'manual') {
            \Core\Database::query('DELETE FROM `exam_manual_quiz` WHERE exam_id = ?', [$examId]);
            $ids = array_values(array_unique($data['_manual_ids'] ?? []));
            foreach ($ids as $i => $qid) {
                \Core\Database::query(
                    'INSERT INTO `exam_manual_quiz` (exam_id, quiz_id, sort) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE sort = VALUES(sort)',
                    [$examId, (int) $qid, $i]
                );
            }
        } elseif ($mode === 'by_kp') {
            \Core\Database::query('DELETE FROM `exam_kp_plan` WHERE exam_id = ?', [$examId]);
            $plan = $data['_kp_plan'] ?? [];
            foreach ($plan as $i => $p) {
                $cnt = (int) ($p['cnt'] ?? 0);
                if ($cnt <= 0) {
                    continue;
                }
                \Core\Database::query(
                    'INSERT INTO `exam_kp_plan` (exam_id, kp, diff, cnt, sort) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE cnt = VALUES(cnt), sort = VALUES(sort)',
                    [$examId, (string) ($p['kp'] ?? ''), (string) ($p['diff'] ?? ''), $cnt, $i]
                );
            }
        } else {
            // random 模式：清理可能残留的明细
            \Core\Database::query('DELETE FROM `exam_manual_quiz` WHERE exam_id = ?', [$examId]);
            \Core\Database::query('DELETE FROM `exam_kp_plan` WHERE exam_id = ?', [$examId]);
        }
    }

    /** 保存/更新后回填准确满分（manual 按所选题型；by_kp 在出题时由引擎确定） */
    private function recomputeScore(int $examId, array $data): void
    {
        $score = Exam::computedTotalScore($data);
        if ($score > 0) {
            \Core\Database::query('UPDATE `examinfo` SET exam_score = ? WHERE id = ?', [$score, $examId]);
        }
    }

    /** 将 input 解析为整数列表（兼容数组或 JSON 字符串） */
    private function parseIntList(mixed $raw): array
    {
        if (is_string($raw)) {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                $raw = $dec;
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $raw), static fn (int $i): bool => $i > 0));
    }

    /** 将 input 解析为知识点计划列表（兼容数组或 JSON 字符串） */
    private function parseKpPlan(mixed $raw): array
    {
        if (is_string($raw)) {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                $raw = $dec;
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $p) {
            if (!is_array($p)) {
                continue;
            }
            $kp = trim((string) ($p['kp'] ?? ''));
            $cnt = (int) ($p['cnt'] ?? 0);
            if ($kp === '' || $cnt <= 0) {
                continue;
            }
            $out[] = ['kp' => $kp, 'diff' => trim((string) ($p['diff'] ?? '')), 'cnt' => $cnt];
        }
        return $out;
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
