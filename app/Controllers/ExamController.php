<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Models\Quiz;
use App\Models\StuScore;
use App\Services\AuthSession;
use App\Services\ExamEngine;
use App\Services\Password;
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

    /** POST /api/exam/login —— 凭准考证号 + 密码 + 考场口令进入考试 */
    public function login(): Response
    {
        $in = $this->validate([
            'exam_id'  => 'required|integer',
            'stu_id'   => 'required|maxlen:20',
            'password' => 'required|maxlen:64',
            'exam_pwd' => 'required|maxlen:20',
        ]);
        $examId = (int) $in['exam_id'];

        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if ($exam['exam_status'] !== Exam::STATUS_TESTING) {
            throw new HttpException(400, '该考试尚未开始或已结束', 40000);
        }

        // 校验考生凭据
        $student = (new \App\Models\Student())->find($in['stu_id']);
        if ($student === null || !Password::verify($in['password'], (string) $student['stu_pwd'])) {
            throw new HttpException(401, '准考证号或密码不正确', 40101);
        }

        // 必须已被排入本场考试
        $score = (new StuScore())->findOne($examId, (string) $in['stu_id']);
        if ($score === null) {
            throw new HttpException(403, '你未被分配到本场考试', 40302);
        }

        // 考场口令（对比 stuscore.stu_pwd 快照）
        if ((string) ($score['stu_pwd'] ?? '') === '' || (string) $score['stu_pwd'] !== $in['exam_pwd']) {
            throw new HttpException(403, '考场密码不正确，请向监考教师确认', 40303);
        }

        $status = (string) ($score['stu_status'] ?? '');

        // 被监考教师锁定 → 拒绝入场
        if ($status === 'locked') {
            throw new HttpException(403, '你已被监考教师锁定，暂时不能进入', 40304);
        }

        // 已交卷但考试仍在进行 → 重置为可作答，让考生重新进入（与旧系统一致）
        if (str_starts_with($status, 'over')) {
            (new StuScore())->update($score['id'], ['stu_status' => 'online']);
            Database::query(
                "UPDATE `stupaper` SET quiz_status = 0, stu_key = ''
                 WHERE exam_id = ? AND stu_id = ?",
                [$examId, $in['stu_id']]
            );
        }

        // 组卷（幂等）+ 标记在线
        $result = ExamEngine::generatePaper($examId, (string) $in['stu_id']);
        (new StuScore())->update($score['id'], ['stu_status' => 'online']);

        sess_set(self::SESS_EXAM, [
            'exam_id'  => $examId,
            'stu_id'   => (string) $in['stu_id'],
            'stu_name' => (string) $student['stu_name'],
            'login_at' => time(),
        ]);

        return $this->ok([
            'exam' => [
                'id'          => (int) $exam['id'],
                'exam_name'   => $exam['exam_name'],
                'exam_end'    => $exam['exam_end'],
                'exam_score'  => (int) $exam['exam_score'],
            ],
            'stu_name'  => $student['stu_name'],
            'warnings'  => $result['warnings'],
        ], '进入考场成功');
    }

    /** POST /api/exam/logout */
    public function logout(): Response
    {
        sess_forget(self::SESS_EXAM);
        return $this->ok(null, '已退出考场');
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
        $this->guardExamTime($examId, $stuId);

        // 心跳：保持在线状态，供监考端统计
        (new StuScore())->update($score['id'], ['stu_status' => 'online']);

        $nav = ExamEngine::navigation($examId, $stuId);
        if ($nav['total'] === 0) {
            throw new HttpException(404, '试卷尚未生成，请联系监考教师', 40400);
        }

        $paperId = (int) $this->request->query('paper_id', 1);
        if ($paperId < 1 || $paperId > $nav['total']) {
            throw new HttpException(400, '题号超出范围', 40000);
        }

        $row = Database::fetch(
            'SELECT sp.paper_id, sp.quiz_class, sp.stu_key, sp.quiz_status,
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
        return $this->ok([
            'submitted' => true,
            'score'     => $got,
            'exam_score'=> (int) (new Exam())->find($examId)['exam_score'],
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

        return $this->ok([
            'submitted'  => $score !== null && str_starts_with((string) $score['stu_status'], 'over'),
            'score'      => (int) ($score['stu_score'] ?? 0),
            'exam_score' => (int) ($exam['exam_score'] ?? 0),
            'exam_name'  => $exam['exam_name'] ?? '',
            'stu_name'   => $sess['stu_name'] ?? '',
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

    /** 读取考试会话，未登录抛 401 */
    private function examSession(): array
    {
        $s = sess_get(self::SESS_EXAM);
        if (!is_array($s) || !isset($s['exam_id'], $s['stu_id'])) {
            throw new HttpException(401, '请先登录考场', 40102);
        }
        return $s;
    }

    private function examEnd(int $examId): ?string
    {
        $exam = (new Exam())->find($examId);
        return $exam['exam_end'] ?? null;
    }

    /**
     * 考试时间兜底：超过 exam_end 后强制交卷（含自动判分）。
     * @return bool 是否已因超时交卷
     */
    private function guardExamTime(int $examId, string $stuId): bool
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null || $exam['exam_status'] !== Exam::STATUS_TESTING) {
            return false;
        }
        $end = strtotime((string) $exam['exam_end']);
        if ($end === false || $end >= time()) {
            return false;
        }
        ExamEngine::autoGrade($examId, $stuId);
        return true;
    }
}
