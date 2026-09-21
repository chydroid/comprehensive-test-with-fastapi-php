<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Models\Quiz;
use App\Models\StuScore;
use App\Services\AuthSession;
use App\Services\CheatGuard;
use App\Services\ExamEngine;
use App\Services\Password;
use App\Services\Setting;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 正式考试（考生端）
 *
 * P0-4 修复：考试进行中下发题目时一律经 Quiz::withoutAnswer() 剥离 quiz_key，
 * 正确答案只在整个考试交卷后（/api/exam/answer）才下发。
 * 旧系统把答案放在 hidden 表单字段里，考生 F12 即可看到全部答案。
 *
 * 会话隔离：考试登录态独立于考生中心登录态（exam_id + stu_id），
 * 以便同一浏览器切换不同考试而不影响个人中心。
 */
class ExamController extends BaseController
{
    private const SESS_EXAM = 'exam_session';

    /**
     * POST /api/exam/login —— 凭准考证号 + 密码 + 考场口令进入考场
     *
     * 入场规则（与需求一致）：
     *  - 必须已由监考「开放入场」（存在考场口令）；
     *  - 口令正确；
     *  - 处于入场窗口：开考前 10 分钟内（由「考试规则」配置）；开考后不再放行；
     *  - 已在本场考试内（online/locked）的考生可随时凭账号密码回到考场（续考）。
     * 入场本身不组卷——组卷由监考「出题」统一完成。
     */
    public function login(): Response
    {
        $in = $this->validate([
            'exam_id'  => 'required|integer',
            'stu_id'   => 'required|maxlen:20',
            'password' => 'required|maxlen:64',
            'exam_pwd' => 'maxlen:20',
        ]);
        $examId = (int) $in['exam_id'];

        // 到点则惰性自动开考（无需常驻定时任务）
        Exam::autoStartIfDue($examId);
        // 到点则惰性自动结束：否则一场过期的 testing 考场会让考生「入场」通过校验，
        // 随后所有接口都在 reading 一个早已结束的场次（BUG-251）。
        Exam::autoEndIfDue($examId);

        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if (str_starts_with((string) $exam['exam_status'], 'over')) {
            throw new HttpException(400, '该考试已结束', 40000);
        }

        // 校验考生凭据
        $students = new \App\Models\Student();
        $student = $students->find($in['stu_id']);
        if ($student === null || !Password::verify($in['password'], (string) $student['stu_pwd'])) {
            throw new HttpException(401, '准考证号或密码不正确', 40101);
        }

        // 旧 md5 哈希验证成功后自动升级为 bcrypt（与门户/考生登录保持一致，
        // 否则只用考场入口登录的旧账号会一直是 md5）
        if (Password::needsRehash((string) $student['stu_pwd'])) {
            $students->update($student['id'], ['stu_pwd' => Password::hash($in['password'])]);
        }

        $scores = new StuScore();
        $score = $scores->findOne($examId, (string) $in['stu_id']);
        $stuStatus = (string) ($score['stu_status'] ?? '');

        // 已交卷 → 不允许再进
        if ($stuStatus !== '' && str_starts_with($stuStatus, 'over')) {
            throw new HttpException(409, '本场考试你已交卷', 40901);
        }

        $inRoom = $score !== null && in_array($stuStatus, ['online', 'locked'], true);

        if (!$inRoom) {
            // 归属校验（首次入场）。普通场次比班级，补考场次比名单（见 isStudentEligible）。
            // 已在本场考试内（online/locked）的考生走续考，不再校验，
            // 避免历史数据把在考考生挡在门外。
            if (!Exam::isStudentEligible($exam, (string) ($student['class_id'] ?? ''), (string) $in['stu_id'])) {
                throw new HttpException(403, '你不在本场考试的参考范围内，请联系监考教师', 40307);
            }
            // 首次入场：需已开放入场 + 口令正确 + 处于入场窗口
            if (!Exam::isOpenForEntry($exam)) {
                throw new HttpException(403, '考场尚未开放入场，请向监考教师确认', 40302);
            }
            if ((string) ($in['exam_pwd'] ?? '') === '' || (string) $exam['exam_pwd'] !== (string) $in['exam_pwd']) {
                throw new HttpException(403, '考场口令不正确，请向监考教师确认', 40303);
            }
            $now = time();
            $opens = Exam::entryOpensAt($exam);
            $closes = Exam::entryClosesAt($exam);
            $lead = Setting::int('exam_entry_lead_minutes', 10);
            $late = Setting::int('exam_entry_late_minutes', 0);
            if ($opens !== null && $now < $opens) {
                $msg = $lead > 0
                    ? "入场尚未开始：开考前 {$lead} 分钟才可进入考场"
                    : '入场尚未开始，请稍候';
                throw new HttpException(403, $msg, 40305);
            }
            if ($closes !== null && $now >= $closes) {
                $msg = $late > 0
                    ? "迟到入场宽限（开考后 {$late} 分钟）已过，无法进入考场"
                    : '考试已开始，无法进入考场';
                throw new HttpException(403, $msg, 40306);
            }
        }

        // 确保成绩记录存在（供状态管理；不组卷）
        if ($score === null) {
            ExamEngine::createScore($examId, (string) $in['stu_id'], (string) ($exam['exam_pwd'] ?? ''));
        }

        // 标记「在考场」；被锁定的考生保持锁定
        if ($stuStatus !== 'locked') {
            $scores->updateStatus($examId, (string) $in['stu_id'], 'online');
        }

        // 多端互踢：生成并写入本次登录的设备令牌（仅启用防作弊时）。
        // 之后任一接口若发现「会话令牌 ≠ 数据库令牌」，即判定本设备已被新登录挤下线。
        $examToken = '';
        if (Setting::bool('enable_cheat_guard')) {
            $examToken = bin2hex(random_bytes(16));
            $scores->setToken($examId, (string) $in['stu_id'], $examToken);
        }

        // 考场登录是从「匿名」提升为「可读写本场试卷」的权限提升，
        // 必须与 AuthSession::login 一致地换发会话 ID，防会话固定（CWE-384）：
        // 否则攻击者预置一个已知 session id 即可在考生登录后复用该 id 读取试题。
        sess_regenerate();
        sess_set(self::SESS_EXAM, [
            'exam_id'    => $examId,
            'stu_id'     => (string) $in['stu_id'],
            'stu_name'   => (string) $student['stu_name'],
            'login_at'   => time(),
            'exam_token' => $examToken,
        ]);

        // 独立考场入口（/exam）不经过个人中心登录，会话里还没有安全令牌。
        // 此处必须补发，否则后续保存答案 / 交卷会被 CSRF 校验拦截（419）。
        $csrf = AuthSession::csrfToken();

        $phase = (string) $exam['exam_status'] === Exam::STATUS_TESTING ? 'answering' : 'waiting';

        return $this->ok([
            'csrf_token' => $csrf,
            'exam' => [
                'id'          => (int) $exam['id'],
                'exam_name'   => $exam['exam_name'],
                'exam_start'  => $exam['exam_start'],
                'exam_end'    => $exam['exam_end'],
                'exam_score'  => (int) $exam['exam_score'],
                'exam_status' => $exam['exam_status'],
            ],
            'stu_name' => $student['stu_name'],
            'phase'    => $phase,
            'warnings' => [],
            'cheat_guard' => Setting::bool('enable_cheat_guard') ? 1 : 0,
        ], '进入考场成功');
    }

    /**
     * GET /api/exam/status —— 等待室与答题页轮询用：返回本场考试当前阶段。
     * 顺带触发惰性自动开考与超时自动交卷。
     *
     * 未入场时返回 200 + phase:null（而不是 401），原因见方法内注释。
     */
    public function status(): Response
    {
        $sess = $this->examSessionOrNull();
        if ($sess === null) {
            // 未入场（或考场会话已过期）不是错误：本接口是**状态查询**，
            // 与三端 /me 同构，应当如实回答「当前没有进行中的考场会话」。
            //
            // 早期实现直接抛 401，后果被放大成 P1：
            //   1. 公开的考场入口页 /exam 一打开，控制台就出现红色 401；
            //   2. 该页 installErrorHandlers 的 onUnauthorized 是
            //      router.navigate('/')，而 router.navigate 对「同一路径」
            //      不会早退，而是直接重新渲染入口视图（见 core/router.js），
            //      于是形成「重渲染 → 再探测 → 又 401 → 再跳转」的自激环，
            //      实测 6 秒内发出 997 次 /api/exam/status，页面彻底卡死。
            //
            // 客户端三处调用点（入口探测 / 等待室轮询 / 答题页 boot）本来就
            // 以「phase 为空 = 未入场」处理，返回 200 与既有约定完全一致。
            // 响应字段与已登录分支保持一致，避免调用方按会话状态分支解析。
            return $this->ok([
                'phase'             => null,
                'paper_ready'       => false,
                'score_visible'     => Setting::bool('exam_show_score_immediately', true),
                'allow_view_answer' => Setting::bool('exam_allow_view_answer', true),
                'exam'              => null,
                'stu_name'          => '',
                'csrf_token'        => AuthSession::csrfToken(),
                'server_ts'         => time(),
            ]);
        }

        $examId = (int) $sess['exam_id'];
        $stuId = (string) $sess['stu_id'];

        // 多端互踢：本设备令牌与数据库不一致 → 已被新登录挤下线，清空会话回到登录页
        if ($this->deviceKicked($examId, $stuId)) {
            sess_forget(self::SESS_EXAM);
            return $this->ok([
                'phase'             => null,
                'paper_ready'       => false,
                'score_visible'     => Setting::bool('exam_show_score_immediately', true),
                'allow_view_answer' => Setting::bool('exam_allow_view_answer', true),
                'exam'              => null,
                'stu_name'          => '',
                'csrf_token'        => AuthSession::csrfToken(),
                'server_ts'         => time(),
            ]);
        }

        Exam::autoStartIfDue($examId);

        $exam = (new Exam())->find($examId);
        $scores = new StuScore();
        $score = $scores->findOne($examId, $stuId);

        // 到结束时间 → 强制交卷。
        // 原来这里只对「当前考生」判分，考场会永远停在 testing，把全站练习/模拟
        // 与门户「进行中考试」永久冻结；现改为整场惰性结束（含本考生判分），
        // 结束后必须重新取场次与分数，否则 phase 仍按旧状态计算（BUG-251）。
        if ($this->guardExamTime($examId)) {
            $exam  = (new Exam())->find($examId);
            $score = $scores->findOne($examId, $stuId);
        }

        $phase = $this->phaseOf($exam, $score);

        $paperReady = false;
        if ($exam !== null && in_array((string) $exam['exam_status'], [Exam::STATUS_PAPER, Exam::STATUS_TESTING], true)) {
            $paperReady = (int) (Database::fetch(
                'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?',
                [$examId, $stuId]
            )['c'] ?? 0) > 0;
        }

        return $this->ok([
            'phase'       => $phase,
            'paper_ready' => $paperReady,
            'score_visible' => Setting::bool('exam_show_score_immediately', true),
            'allow_view_answer' => Setting::bool('exam_allow_view_answer', true),
            'exam'        => $exam === null ? null : [
                'id'          => (int) $exam['id'],
                'exam_name'   => $exam['exam_name'],
                'exam_start'  => $exam['exam_start'],
                'exam_end'    => $exam['exam_end'],
                'exam_score'  => (int) $exam['exam_score'],
                'exam_status' => $exam['exam_status'],
            ],
            'stu_name'   => $sess['stu_name'] ?? '',
            // 答题页刷新后前端的 csrfToken 会随模块状态一起重置为空，
            // 而本接口是答题页启动 / 轮询的唯一入口，故在此重新下发令牌，
            // 使保存答案 / 交卷可正常通过 CSRF 校验（否则恒 419）。
            'csrf_token' => AuthSession::csrfToken(),
            'server_ts'  => time(),
            'cheat_guard' => Setting::bool('enable_cheat_guard') ? 1 : 0,
        ]);
    }

    /** POST /api/exam/logout */
    public function logout(): Response
    {
        $sess = $this->examSessionOrNull();
        if ($sess !== null) {
            // 退出考场时清空设备令牌，避免残留令牌干扰后续重新入场
            (new StuScore())->clearToken((int) $sess['exam_id'], (string) $sess['stu_id']);
        }
        sess_forget(self::SESS_EXAM);
        return $this->ok(null, '已退出考场');
    }

    /**
     * POST /api/exam/cheat —— 上报一次异常行为（切屏 / 失焦）。
     * 仅在启用防作弊时记录；未启用或越权调用一律静默成功，不泄露开关状态。
     */
    public function reportCheat(): Response
    {
        if (!Setting::bool('enable_cheat_guard')) {
            return $this->ok(null);
        }
        $sess = $this->examSession();
        $examId = (int) $sess['exam_id'];
        $stuId = (string) $sess['stu_id'];
        $in = $this->validate([
            'type'   => 'required|maxlen:32',
            'detail' => 'maxlen:500',
        ]);
        CheatGuard::report($examId, $stuId, (string) $in['type'], (string) ($in['detail'] ?? ''));
        return $this->ok(null, '已记录');
    }

    /**
     * GET /api/exam/paper?paper_id=N —— 取某题（不下发答案）
     * 缺 paper_id 时返回第一题。
     */
    public function paper(): Response
    {
        $sess = $this->examSession();
        $examId = (int) $sess['exam_id'];
        $stuId = (string) $sess['stu_id'];

        // 多端互踢：本设备令牌与数据库不一致 → 已被新登录挤下线
        if ($this->deviceKicked($examId, $stuId)) {
            sess_forget(self::SESS_EXAM);
            throw new HttpException(409, '您已在其他设备登录，本次会话已结束', 40902);
        }

        $score = (new StuScore())->findOne($examId, $stuId);
        if ($score === null) {
            throw new HttpException(404, '考卷不存在', 40400);
        }
        $status = (string) ($score['stu_status'] ?? '');
        if (str_starts_with($status, 'over')) {
            throw new HttpException(409, '本场考试已交卷', 40901);
        }
        if ($status === 'locked') {
            throw new HttpException(403, '你已被监考教师锁定，暂时不能作答', 40304);
        }

        // 未开考不得取题（考生可在等待室轮询 /status）
        Exam::autoStartIfDue($examId);
        $exam = (new Exam())->find($examId);
        if ($exam === null || (string) $exam['exam_status'] !== Exam::STATUS_TESTING) {
            throw new HttpException(409, '考试尚未开始，请稍候', 40903);
        }
        // 超时 → 整场惰性结束（本考生已一并判分）
        if ($this->guardExamTime($examId)) {
            throw new HttpException(409, '考试时间已到，已自动交卷', 40901);
        }

        // 心跳：保持在线状态，供监考端统计
        (new StuScore())->update($score['id'], ['stu_status' => 'online']);

        $nav = ExamEngine::navigation($examId, $stuId);
        if ($nav['total'] === 0) {
            // 惰性补卷：出题只面向「进入考场」的考生，监考点过出题之后才入场的
            // 考生不会有一份预生成试卷。此处为其按需生成（幂等：已有卷则不重复排），
            // 避免开考后卡在「试卷尚未生成」而无卷可答。
            ExamEngine::generatePaper($examId, $stuId);
            $nav = ExamEngine::navigation($examId, $stuId);
        }
        if ($nav['total'] === 0) {
            throw new HttpException(404, '试卷尚未生成，请联系监考教师', 40400);
        }

        $paperId = (int) $this->request->query('paper_id', 1);
        if ($paperId < 1 || $paperId > $nav['total']) {
            throw new HttpException(400, '题号超出范围', 40000);
        }

        $row = Database::fetch(
            'SELECT sp.paper_id, sp.quiz_class, sp.stu_key, sp.quiz_status, sp.option_order,
                    q.quiz_title, q.quiz_option, q.quiz_pic_name, q.id AS quiz_id
             FROM `stupaper` sp
             INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ? AND sp.paper_id = ?',
            [$examId, $stuId, $paperId]
        );
        if ($row === null) {
            throw new HttpException(404, '题目不存在', 40400);
        }
        // P0-4：剥离答案后下发
        $question = Quiz::withoutAnswer($row);
        $question['paper_id'] = (int) $row['paper_id'];
        $question['stu_key']  = (string) ($row['stu_key'] ?? '');
        $question['answered'] = (int) $row['quiz_status'] !== 0;
        // 选项乱序：前端据此重排选项展示顺序（答案键仍是原始字母，不影响判分）
        $question['option_order'] = (string) ($row['option_order'] ?? '');

        return $this->ok([
            'question'   => $question,
            'paper_id'   => $paperId,
            'navigation' => $nav,
            'exam_end'   => $this->examEnd($examId),
            'server_ts'  => time(),
        ]);
    }

    /** POST /api/exam/paper/save —— 保存单题答案并返回下一题号 */
    public function savePaper(): Response
    {
        $sess = $this->examSession();
        $examId = (int) $sess['exam_id'];
        $stuId = (string) $sess['stu_id'];

        // 多端互踢：本设备令牌与数据库不一致 → 已被新登录挤下线
        if ($this->deviceKicked($examId, $stuId)) {
            sess_forget(self::SESS_EXAM);
            throw new HttpException(409, '您已在其他设备登录，本次会话已结束', 40902);
        }

        $score = (new StuScore())->findOne($examId, $stuId);
        if ($score === null) {
            throw new HttpException(404, '考卷不存在', 40400);
        }
        if (str_starts_with((string) $score['stu_status'], 'over')) {
            throw new HttpException(409, '本场考试已交卷', 40901);
        }
        if ($score['stu_status'] === 'locked') {
            throw new HttpException(403, '你已被监考教师锁定，暂时不能作答', 40304);
        }
        // 超时不再接受保存，直接交卷
        if ($this->guardExamTime($examId, $stuId)) {
            return $this->ok(['submitted' => true], '考试时间已到，已自动交卷');
        }

        $in = $this->validate([
            'paper_id' => 'required|integer',
        ]);
        $paperId = (int) $in['paper_id'];

        $row = Database::fetch(
            'SELECT quiz_class FROM `stupaper` WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
            [$examId, $stuId, $paperId]
        );
        if ($row === null) {
            throw new HttpException(404, '题目不存在', 40400);
        }

        $type = (string) $row['quiz_class'];
        // 注意：多选题前端提交数组，故 stu_key 不参与 validate()（其标量防护会拒绝数组），
        // 直接取原始输入后交由 ExamEngine 归一化并做长度约束。
        $raw = $this->request->input('stu_key', '');
        $answer = ExamEngine::normalizeSubmission($type, $raw);
        if (strlen($answer) > 5000) {
            throw new HttpException(400, '答案长度超出限制', 40000);
        }

        ExamEngine::saveAnswer($examId, $stuId, $paperId, $answer);

        $nav = ExamEngine::navigation($examId, $stuId);
        $next = $paperId + 1;

        return $this->ok([
            'saved'          => true,
            'paper_id'       => $paperId,
            'next_paper_id'  => $next <= $nav['total'] ? $next : null,
            'navigation'     => $nav,
        ], '答案已保存');
    }

    /** POST /api/exam/paper/submit —— 交卷并自动判分 */
    public function submitPaper(): Response
    {
        $sess = $this->examSession();
        $examId = (int) $sess['exam_id'];
        $stuId = (string) $sess['stu_id'];

        $score = (new StuScore())->findOne($examId, $stuId);
        if ($score === null) {
            throw new HttpException(404, '考卷不存在', 40400);
        }
        if (str_starts_with((string) $score['stu_status'], 'over')) {
            // 幂等：重复交卷返回既有成绩
            return $this->ok([
                'already_submitted' => true,
                'score'             => (int) $score['stu_score'],
            ], '本场考试已交卷');
        }

        $got = ExamEngine::autoGrade($examId, $stuId);
        // 后台可关闭「交卷后立即显示成绩」：关闭时不下发分数，避免考中泄题或攀比
        $visible = Setting::bool('exam_show_score_immediately', true);
        return $this->ok([
            'submitted'     => true,
            'score'         => $visible ? $got : null,
            'score_visible' => $visible,
            'exam_score'    => $visible ? (int) (new Exam())->find($examId)['exam_score'] : null,
        ], '交卷成功');
    }

    /** GET /api/exam/over —— 交卷结果页数据 */
    public function over(): Response
    {
        $sess = $this->examSession();
        $examId = (int) $sess['exam_id'];
        $stuId = (string) $sess['stu_id'];

        $score = (new StuScore())->findOne($examId, $stuId);
        $exam = (new Exam())->find($examId);
        $visible = Setting::bool('exam_show_score_immediately', true);

        return $this->ok([
            'submitted'     => $score !== null && str_starts_with((string) $score['stu_status'], 'over'),
            'score'         => $visible ? (int) ($score['stu_score'] ?? 0) : null,
            'exam_score'    => $visible ? (int) ($exam['exam_score'] ?? 0) : null,
            'score_visible' => $visible,
            'exam_name'     => $exam['exam_name'] ?? '',
            'stu_name'      => $sess['stu_name'] ?? '',
        ]);
    }

    /**
     * GET /api/exam/answer —— 查看答案解析
     * 仅当已交卷（或考试已结束）时下发 quiz_key。
     */
    public function viewAnswer(): Response
    {
        $sess = $this->examSession();
        $examId = (int) $sess['exam_id'];
        $stuId = (string) $sess['stu_id'];

        $score = (new StuScore())->findOne($examId, $stuId);
        $exam = (new Exam())->find($examId);

        $submitted = $score !== null && str_starts_with((string) $score['stu_status'], 'over');
        $examOver = $exam !== null && !in_array($exam['exam_status'], Exam::ACTIVE_STATUSES, true);

        if (!$submitted && !$examOver) {
            throw new HttpException(403, '考试尚未结束，不能查看答案', 40305);
        }
        // 后台可关闭「交卷后可查看答案解析」，用于需要复用同批题目的多场考试
        if (!Setting::bool('exam_allow_view_answer', true)) {
            throw new HttpException(403, '本场考试暂不开放答案解析查看', 40307);
        }

        $papers = ExamEngine::paperWithAnswers($examId, $stuId);
        // 汇总各题型得分情况
        $valMap = [
            'radio1'   => (int) ($exam['radio1_val'] ?? 0),
            'radio2'   => (int) ($exam['radio2_val'] ?? 0),
            'checkbox' => (int) ($exam['checkbox_val'] ?? 0),
            'text'     => (int) ($exam['text_val'] ?? 0),
        ];
        $summary = [];
        foreach ($papers as $p) {
            $t = (string) $p['quiz_class'];
            if (!isset($summary[$t])) {
                $summary[$t] = ['type' => $t, 'label' => Quiz::TYPE_LABELS[$t] ?? '未知',
                                'total' => 0, 'right' => 0, 'score' => 0];
            }
            $summary[$t]['total']++;
            if ($p['is_correct'] === true) {
                $summary[$t]['right']++;
                $summary[$t]['score'] += $valMap[$t] ?? 0;
            }
        }

        return $this->ok([
            'papers'     => $papers,
            'summary'    => array_values($summary),
            'score'      => (int) ($score['stu_score'] ?? 0),
            'exam_score' => (int) ($exam['exam_score'] ?? 0),
            'exam_name'  => $exam['exam_name'] ?? '',
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * 本场考试对当前考生的阶段：
     *   submitted 已交卷 / answering 答题中 / waiting 等待开考 / closed 已结束
     */
    private function phaseOf(?array $exam, ?array $score): string
    {
        if ($exam === null) {
            return 'closed';
        }
        $stuStatus = (string) ($score['stu_status'] ?? '');
        if ($stuStatus !== '' && str_starts_with($stuStatus, 'over')) {
            return 'submitted';
        }
        $examStatus = (string) $exam['exam_status'];
        if (str_starts_with($examStatus, 'over')) {
            return 'closed';
        }
        return $examStatus === Exam::STATUS_TESTING ? 'answering' : 'waiting';
    }

    /** 读取考试会话，未登录抛 401 */
    private function examSession(): array
    {
        $s = $this->examSessionOrNull();
        if ($s === null) {
            throw new HttpException(401, '请先登录考场', 40102);
        }
        return $s;
    }

    /**
     * 读取考试会话，未入场返回 null（不抛异常）。
     * 供「状态查询」类接口使用：这类接口把「没有会话」当作一种正常结果回答，
     * 而不是当作鉴权失败——否则公开页面会在控制台报 401，且容易被前端的
     * 401 全局跳转放大成请求风暴（见 status() 内注释）。
     */
    private function examSessionOrNull(): ?array
    {
        $s = sess_get(self::SESS_EXAM);
        return is_array($s) && isset($s['exam_id'], $s['stu_id']) ? $s : null;
    }

    /**
     * 多端互踢判定：启用防作弊且「会话令牌 ≠ 数据库令牌」时返回 true。
     * 该情形说明当前会话已被同一账号的新登录挤下线。
     */
    private function deviceKicked(int $examId, string $stuId): bool
    {
        if (!Setting::bool('enable_cheat_guard')) {
            return false;
        }
        $token = (string) (sess_get(self::SESS_EXAM)['exam_token'] ?? '');
        if ($token === '') {
            return false;
        }
        $stored = (string) ((new StuScore())->tokenOf($examId, $stuId) ?? '');
        return $stored !== '' && $stored !== $token;
    }

    private function examEnd(int $examId): ?string
    {
        $exam = (new Exam())->find($examId);
        return $exam['exam_end'] ?? null;
    }

    /**
     * 考试时间兜底：超过 exam_end 后强制交卷（含自动判分）。
     *
     * 实现收敛为「整场惰性结束」——原先只判分当前考生，考场会永远停在 testing，
     * 导致全站练习/模拟被永久冻结、门户常驻幽灵考试、考生被钉死在「在考」硬约束
     * （BUG-251）。整场结束后 exam_status 变为 over，本场全部未交卷考生一并判分，
     * 且 autoEndIfDue() 幂等，故重复轮询不会重复判分。
     *
     * @return bool 是否已因超时结束（本场考生均已交卷）
     */
    private function guardExamTime(int $examId): bool
    {
        return Exam::autoEndIfDue($examId);
    }
}
