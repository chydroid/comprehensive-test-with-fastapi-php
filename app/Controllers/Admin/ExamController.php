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
use App\Services\ExamRetake;
use App\Services\ScoreAnalysis;
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

        // 列表访问时收敛「完全无人访问的过期考场」：
        // 系统无常驻 cron，过期考场只能靠请求触发流转；管理端考试列表是最高频的
        // 访问入口，在这里触发 autoEndIfDue() 可使过期但无人答题的考场最终收敛为 over，
        // 否则成绩永远停在 testing（见 Exam::autoEndIfDue 注释）。
        // endExam() 幂等：已结束的考场廉价 COUNT 为 0 直接返回，重复调用安全。
        foreach ($result['data'] as &$row) {
            if ((string) ($row['exam_status'] ?? '') === Exam::STATUS_TESTING) {
                if (Exam::autoEndIfDue((int) $row['id'])) {
                    $row['exam_status'] = 'over';
                }
            }
        }
        unset($row);

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
        $row['paper_mode_info'] = Exam::paperMode($row);
        $row['total_questions'] = Exam::totalQuestions($row);
        $row['computed_score']  = Exam::computedTotalScore($row);
        // 仅 random 模式按题型×难度计数列校验题库容量；manual/by_kp 不依赖该矩阵
        $row['stock']           = ((string) ($row['paper_mode'] ?? 'random') === 'random')
            ? Exam::checkStock($row)
            : ['ok' => true, 'shortfall' => []];
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
        $this->persistPaperMode($id, $data);
        $this->recomputeScore($id, $data);
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
        $this->persistPaperMode($id, $data);
        $this->recomputeScore($id, $data);
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
            // 补考名单同样以 exam_id 关联；不清理会留下指向已删考试的孤儿行
            Database::query('DELETE FROM `exam_retake_stu` WHERE exam_id = ?', [$id]);
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
        // 仅 random 模式依赖题型×难度矩阵；manual/by_kp 的题目在出题时已确定，无需此校验
        if ((string) ($row['paper_mode'] ?? 'random') === 'random') {
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
     * POST /api/admin/exams/{id}/generate —— 出题：为已进入考场的考生预生成随机试卷（无考生入场时提示「无需出卷」）
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
            // 不出卷，但「出题」这一动作代表监考已就绪：必须推进 exam → paper，
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

        // 审计必须写在 return 之前 —— 此前放在 return 之后是死代码，出题动作从不留痕
        $this->audit('exam.generate', 'exam:' . $id, ['students' => $result['students'], 'entered' => $result['entered'], 'generated' => $result['generated']]);

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
            'entered'       => $result['entered'],
            'generated'     => $result['generated'],
            'skipped'       => $result['skipped'],
            'warnings'      => array_values($warnings),
        ], "出题完成：为 {$result['entered']} 名已入场考生生成 {$result['generated']} 份，跳过（已有试卷）{$result['skipped']} 份");
    }

    /* ------------------------------------------------------------------ */

    /**
     * GET /api/admin/exams/{id}/retake-candidates —— 补考候选名单（C2）
     *
     * 返回源场次全部参考考生的状态：未通过 / 缺考 / 已通过，供前端默认勾选前两类。
     */
    public function retakeCandidates(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        $percent = $this->request->query('pass_percent');
        return $this->ok(ExamRetake::candidates($id, $percent !== null ? (int) $percent : null));
    }

    /**
     * POST /api/admin/exams/{id}/retake —— 生成补考场次（C2）
     *
     * 新建一场正式考试（retake_of 指向源场次），把所选考生写入补考名单。
     * 之后走与普通考试完全相同的「开放入场 → 出题 → 开考 → 判分」流程。
     */
    public function retake(): Response
    {
        $id = $this->idParam();
        $src = $this->model->find($id);
        if ($src === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        $in = $this->validate([
            'exam_start'   => 'required|maxlen:32',
            'exam_end'     => 'required|maxlen:32',
            'exam_tea'     => 'maxlen:50',
            'pass_percent' => 'integer|min:1|max:100',
            'allow_passed' => 'integer',
        ]);
        // 通用 validate() 拒绝数组值（"必须是标量值"），名单只能手工取 ——
        // 与 A4 批阅 items 同一处理方式。
        $stuIds = $this->request->input('stu_ids', []);
        if (is_string($stuIds)) {
            $decoded = json_decode($stuIds, true);
            $stuIds = is_array($decoded) ? $decoded : explode(',', $stuIds);
        }
        if (!is_array($stuIds) || $stuIds === []) {
            throw new HttpException(400, '请至少选择一名参加补考的考生', 40000);
        }

        $result = ExamRetake::create($id, $stuIds, [
            'exam_start'     => (string) $in['exam_start'],
            'exam_end'       => (string) $in['exam_end'],
            'exam_tea'       => (string) ($in['exam_tea'] ?? ''),
            'pass_percent'   => isset($in['pass_percent']) ? (int) $in['pass_percent'] : null,
            'allow_passed'   => !empty($in['allow_passed']),
        ]);

        $this->audit('exam.retake', 'exam:' . $result['exam_id'], [
            'source_exam' => $id,
            'exam_name'   => $result['exam_name'],
            'roster'      => $result['roster'],
            'pass_score'  => $result['pass_score'],
        ]);

        return $this->ok($result, "已生成补考「{$result['exam_name']}」，共 {$result['roster']} 名考生");
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

        $mode = (string) ($data['paper_mode'] ?? 'random');
        if ($mode === 'manual') {
            $ids = $data['_manual_ids'] ?? [];
            if (count($ids) <= 0) {
                throw new HttpException(400, '手动选题模式请至少选择一道题目', 40003);
            }
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
            if ($strictStock) {
                // 校验每个知识点（及难度）的可用题量是否足够
                $shortfall = [];
                foreach ($plan as $p) {
                    $kp = (string) ($p['kp'] ?? '');
                    $diff = (string) ($p['diff'] ?? '');
                    $need = (int) ($p['cnt'] ?? 0);
                    if ($need <= 0) {
                        continue;
                    }
                    $sql = 'SELECT COUNT(*) AS c FROM `quizlib` WHERE subj_id = ? AND quiz_kp = ?';
                    $params = [(int) $data['subj_id'], $kp];
                    if ($diff !== '') {
                        $sql .= ' AND quiz_diff = ?';
                        $params[] = $diff;
                    }
                    $have = (int) (\Core\Database::fetch($sql, $params)['c'] ?? 0);
                    if ($have < $need) {
                        $shortfall[] = sprintf(
                            '知识点「%s」(%s)：题库有 %d 道，需要 %d 道',
                            $kp,
                            $diff === '' ? '不限难度' : (Quiz::DIFF_LABELS[$diff] ?? $diff),
                            $have,
                            $need
                        );
                    }
                }
                if ($shortfall !== []) {
                    throw new HttpException(400, '题库题量不足：' . implode('；', $shortfall), 40005, $shortfall);
                }
            }
        } else {
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

    /** 保存/更新后固化组卷模式明细（手动选题 / 按知识点计划） */
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
            \Core\Database::query('DELETE FROM `exam_manual_quiz` WHERE exam_id = ?', [$examId]);
            \Core\Database::query('DELETE FROM `exam_kp_plan` WHERE exam_id = ?', [$examId]);
        }
    }

    /** 保存/更新后回填准确满分 */
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

    /** 将 input 解析为知识点计划列表 */
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

    /** GET /api/admin/exams/{id}/analysis —— 成绩与学情分析（A2） */
    public function analysis(): Response
    {
        $this->authAdmin();
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }

        // 及格线默认取后台「考试规则 → 及格线」，与补考名单筛选同口径（可按请求参数覆盖）
        $passLine      = (int) $this->request->query('pass_line', Exam::passPercent());
        $excellentLine = (int) $this->request->query('excellent_line', 85);
        $weakLimit     = (int) $this->request->query('weak_limit', 10);

        return $this->ok(ScoreAnalysis::analyze($id, $passLine, $excellentLine, $weakLimit));
    }

    /** GET /api/admin/quiz-search —— 手动选题：题库检索 */
    public function quizSearch(): Response
    {
        $this->authAdmin();
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

        $total = (int) (\Core\Database::fetch("SELECT COUNT(*) AS c FROM `quizlib` q WHERE {$sqlWhere}", $params)['c'] ?? 0);
        $rows = \Core\Database::fetchAll(
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

    /** GET /api/admin/quiz-kps —— 某科目下的知识点列表与可用题量 */
    public function quizKps(): Response
    {
        $this->authAdmin();
        $subjId = (int) $this->request->query('subj_id', 0);
        $rows = \Core\Database::fetchAll(
            "SELECT quiz_kp, COUNT(*) AS c FROM `quizlib` WHERE subj_id = ? AND quiz_kp <> '' GROUP BY quiz_kp ORDER BY quiz_kp",
            [$subjId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['kp' => (string) $r['quiz_kp'], 'count' => (int) $r['c']];
        }
        return $this->ok(['list' => $out]);
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
