<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Models\Quiz;
use App\Models\Subject;
use App\Services\ExamEngine;
use App\Services\WrongBook;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 模拟考试（考生自主组卷 / 限时答题 / 结果与错题回顾）
 *
 * 实现要点：
 * - 模拟考试在 examinfo 中以 exam_class='模拟考试' 标记，examinfo.exam_status 随答题流转
 *   （testing → over），与正式考试的区分完全靠 exam_class。
 * - **与正式考试互不影响**：模拟考试整体排除在管理端/教师端的考试管理、监考列表之外
 *   （Exam::adminList / TeacherMonitorController），也不计入「进行中的正式考试」。
 * - 与正式考试的关系由 Exam::mockPause() 统一裁决，分两层：
 *     ① 全局层：存在进行中的正式考试时**默认暂停**模拟考试（组卷与错题回顾），
 *        管理员可在后台开启 `mock_allow_during_exam` 放开；
 *     ② 个人层：考生本人正在考场内（已入场且考试进行中）时**一律暂停**，
 *        后台开关无法放开。
 *   进入暂停态后，已在进行的模拟考试不再允许继续作答（paper/save），但仍可交卷
 *   （submit）关闭本场——否则考生会留下永远无法结束的半成品场次并白占一次每日额度。
 * - 数据上限同样走后台：`mock_daily_limit`（每人每日场次，默认 5）、
 *   `mock_max_questions`（单场题目总数，默认 100）。
 * - 组卷不区分难度（与旧系统一致），按题型抽取指定数量。
 * - 分值：判断题 2 / 单选 2 / 多选 3 / 填空 5（沿用旧系统模拟考试默认分值）。
 *
 * 修复的旧系统缺陷：
 * - review() 先比较后归一化，导致多选题字母顺序不同被误判为错题
 *   （旧代码 `if ($answer === $correct) continue;` 在归一化之前）。
 *   本实现统一先经 Quiz::isCorrect() 归一化再判定。
 * - 模拟考试题目同样不下发 quiz_key，避免考生在答题过程中查看到答案。
 */
class ExerciseExamController extends BaseController
{
    private const MOCK_CLASS = Exam::MOCK_CLASS;

    /** 模拟考试默认分值 */
    private const VALUE_MAP = ['radio1' => 2, 'radio2' => 2, 'checkbox' => 3, 'text' => 5, 'longtext' => 0];

    /**
     * GET /api/exercise/mock/config —— 可选科目 + 当前考生可用的模拟考试配额
     *
     * 配额放在这里而非 counts()：进入组卷页时就要据此禁用按钮并给出
     * 「今日剩余 N 场 / 单场上限 M 题」的提示，避免考生配好卷才被拒。
     */
    public function config(): Response
    {
        $sess = $this->authStudent();
        return $this->ok([
            'subjects' => (new Subject())->all('id ASC'),
            'limits'   => self::quota((string) $sess['id']),
        ]);
    }

    /**
     * 当前考生的模拟考试配额视图（供前端展示与按钮控制）。
     *
     * @return array{daily_limit:int,used_today:int,remaining:int,max_questions:int,paused:bool,pause_reason:string}
     */
    private static function quota(string $stuId): array
    {
        $limit = Exam::mockDailyLimit();
        $used = Exam::mockUsedToday($stuId);
        $pause = Exam::mockPause($stuId);
        return [
            'daily_limit'   => $limit,
            'used_today'    => $used,
            'remaining'     => max(0, $limit - $used),
            'max_questions' => Exam::mockMaxQuestions(),
            'paused'        => $pause['paused'],
            // pause_reason：'self_in_exam'（本人在考，硬约束）/
            //               'exam_ongoing'（全局策略暂停）/ ''（未暂停）
            'pause_reason'  => $pause['reason'],
        ];
    }

    /** GET /api/exercise/mock/counts?subj_id=N —— 指定科目各题型可用题量 */
    public function counts(): Response
    {
        $in = $this->validate(['subj_id' => 'required|integer']);
        $subjId = (int) $in['subj_id'];

        $rows = Database::fetchAll(
            'SELECT quiz_class, COUNT(*) AS c FROM `quizlib` WHERE subj_id = ? GROUP BY quiz_class',
            [$subjId]
        );
        $counts = ['radio1' => 0, 'radio2' => 0, 'checkbox' => 0, 'text' => 0, 'longtext' => 0];
        foreach ($rows as $r) {
            $counts[(string) $r['quiz_class']] = (int) $r['c'];
        }
        return $this->ok(['subj_id' => $subjId, 'counts' => $counts]);
    }

    /**
     * POST /api/exercise/mock/start —— 创建模拟考试并组卷
     * body: { subj_id, radio1_count, radio2_count, checkbox_count, text_count }
     */
    public function start(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];

        // 与正式考试的关系由 Exam::mockPause 统一裁决（全局开关 + 本人在考硬约束）。
        // 必须拦截的原因：模拟考试同样从 quizlib 抽题，交卷后 review() 会整卷
        // 下发 quiz_key —— 开考期间可用它批量导出题库答案。
        $this->assertNotPaused($stuId);

        // 单场题目总数上限（后台可配，默认 100）
        $maxQuestions = Exam::mockMaxQuestions();

        $in = $this->validate([
            'subj_id'        => 'required|integer',
            'radio1_count'   => 'integer|min:0|max:' . $maxQuestions,
            'radio2_count'   => 'integer|min:0|max:' . $maxQuestions,
            'checkbox_count' => 'integer|min:0|max:' . $maxQuestions,
            'text_count'     => 'integer|min:0|max:' . $maxQuestions,
        ]);
        $subjId = (int) $in['subj_id'];

        $wanted = [
            'radio1'   => (int) ($in['radio1_count'] ?? 0),
            'radio2'   => (int) ($in['radio2_count'] ?? 0),
            'checkbox' => (int) ($in['checkbox_count'] ?? 0),
            'text'     => (int) ($in['text_count'] ?? 0),
        ];
        $total = array_sum($wanted);
        if ($total <= 0) {
            throw new HttpException(400, '请至少配置一道题目', 40000);
        }
        if ($total > $maxQuestions) {
            throw new HttpException(
                400,
                "单场模拟考试题目总数不能超过 {$maxQuestions} 题（当前 {$total} 题）",
                40002,
                ['max' => $maxQuestions, 'requested' => $total]
            );
        }

        // 每人每日场次上限（后台可配，默认 5）
        $dailyLimit = Exam::mockDailyLimit();
        if (Exam::mockUsedToday($stuId) >= $dailyLimit) {
            throw new HttpException(
                429,
                "你今天的模拟考试场次已用完（每日上限 {$dailyLimit} 场），请明天再试",
                42901,
                ['daily_limit' => $dailyLimit]
            );
        }

        // 题库是否够用（提前显式校验，避免生成残缺试卷）
        $avail = [];
        foreach (Database::fetchAll(
            'SELECT quiz_class, COUNT(*) AS c FROM `quizlib` WHERE subj_id = ? GROUP BY quiz_class',
            [$subjId]
        ) as $r) {
            $avail[(string) $r['quiz_class']] = (int) $r['c'];
        }
        $short = [];
        foreach ($wanted as $type => $n) {
            if ($n > ($avail[$type] ?? 0)) {
                $short[] = ['type' => $type, 'label' => Quiz::TYPE_LABELS[$type] ?? $type,
                            'need' => $n, 'have' => $avail[$type] ?? 0];
            }
        }
        if ($short !== []) {
            throw new HttpException(400, '题库数量不足，请调整题目数量', 40001, $short);
        }

        // 组卷（不区分难度）。考试记录创建也纳入同一事务：
        // 既保证「记录 + 试卷 + 成绩」三者原子，也便于在事务内复检每日场次。
        $paperId = 1;
        $examId  = 0;
        Database::beginTransaction();
        try {
            // 事务内复检：两次请求几乎同时通过预检时，把上限守住（降低并发双开概率）。
            if (Exam::mockUsedToday($stuId) >= $dailyLimit) {
                throw new HttpException(
                    429,
                    "你今天的模拟考试场次已用完（每日上限 {$dailyLimit} 场），请明天再试",
                    42901,
                    ['daily_limit' => $dailyLimit]
                );
            }

            $examId = (int) ExamEngine::createPracticeExam([
                'exam_name' => '模拟考试_' . date('Y-m-d_H-i-s'),
                'subj_id'   => $subjId,
            ]);

            foreach ($wanted as $type => $n) {
                if ($n <= 0) {
                    continue;
                }
                $rows = Database::fetchAll(
                    'SELECT id FROM `quizlib` WHERE subj_id = ? AND quiz_class = ?
                     ORDER BY RAND() LIMIT ' . (int) $n,
                    [$subjId, $type]
                );
                foreach ($rows as $q) {
                    Database::query(
                        "INSERT INTO `stupaper`
                            (exam_id, stu_id, paper_id, quiz_id, quiz_class, stu_key, quiz_status)
                         VALUES (?, ?, ?, ?, ?, '', 0)",
                        [$examId, $stuId, $paperId, (int) $q['id'], $type]
                    );
                    $paperId++;
                }
            }
            ExamEngine::createScore($examId, $stuId, '');
            Database::query(
                "UPDATE `stuscore` SET `stu_status` = 'online' WHERE exam_id = ? AND stu_id = ?",
                [$examId, $stuId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $this->ok([
            'exam_id'       => $examId,
            'total'         => $paperId - 1,
            'total_score'   => self::computeTotalScore($wanted),
        ] + self::quota($stuId), '模拟考试已开始');
    }

    /** GET /api/exercise/mock/paper?exam_id=N&paper_id=M */
    public function paper(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];
        $examId = (int) $this->request->query('exam_id', 0);
        $this->assertMockExam($examId, $stuId);
        // 暂停期间不得继续作答：考生若在入场/开考后仍切到模拟页答题，即为
        // 「一边考试一边模拟」，与需求相悖。取题也必须拦，否则题目仍会一题题流出。
        $this->assertNotPaused($stuId);

        $nav = ExamEngine::navigation($examId, $stuId);
        if ($nav['total'] === 0) {
            throw new HttpException(404, '试卷尚未生成', 40400);
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
        $question = Quiz::withoutAnswer($row);
        $question['paper_id'] = (int) $row['paper_id'];
        $question['stu_key']  = (string) ($row['stu_key'] ?? '');
        $question['answered'] = (int) $row['quiz_status'] !== 0;

        return $this->ok([
            'question' => $question,
            'paper_id' => $paperId,
            'navigation' => $nav,
        ]);
    }

    /** POST /api/exercise/mock/save —— 保存单题 */
    public function savePaper(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];

        $in = $this->validate([
            'exam_id'  => 'required|integer',
            'paper_id' => 'required|integer',
        ]);
        $examId = (int) $in['exam_id'];
        $paperId = (int) $in['paper_id'];
        $this->assertMockExam($examId, $stuId);
        // 同 paper()：暂停期间不接受继续作答
        $this->assertNotPaused($stuId);

        $row = Database::fetch(
            'SELECT quiz_class FROM `stupaper` WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
            [$examId, $stuId, $paperId]
        );
        if ($row === null) {
            throw new HttpException(404, '题目不存在', 40400);
        }

        $answer = ExamEngine::normalizeSubmission(
            (string) $row['quiz_class'],
            $this->request->input('stu_key', '')
        );
        ExamEngine::saveAnswer($examId, $stuId, $paperId, $answer);

        $nav = ExamEngine::navigation($examId, $stuId);
        $next = $paperId + 1;
        return $this->ok([
            'saved'         => true,
            'next_paper_id' => $next <= $nav['total'] ? $next : null,
            'navigation'    => $nav,
        ], '答案已保存');
    }

    /** POST /api/exercise/mock/submit —— 交卷并判分 */
    public function submitPaper(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];

        $in = $this->validate(['exam_id' => 'required|integer']);
        $examId = (int) $in['exam_id'];
        $this->assertMockExam($examId, $stuId);

        // 交卷**刻意不设暂停守卫**：暂停可能在考生答到一半时生效（考试开始 /
        // 被拉进考场），此时若连交卷也拦掉，考生会留下永远无法结束的 testing
        // 场次，并白占一次每日额度。交卷本身不泄露答案（gradeMock 只回成绩与
        // 对错数，不下发 quiz_key），真正的披露点是 review()，已单独拦截。
        $result = self::gradeMock($examId, $stuId);
        return $this->ok($result, '交卷成功');
    }

    /** GET /api/exercise/mock/over?exam_id=N —— 结果页 */
    public function over(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];
        $examId = (int) $this->request->query('exam_id', 0);
        $this->assertMockExam($examId, $stuId);

        $score = Database::fetch(
            'SELECT stu_score, stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        $total = ExamEngine::navigation($examId, $stuId);

        return $this->ok([
            'exam_id'     => $examId,
            'score'       => (int) ($score['stu_score'] ?? 0),
            'total_score' => self::totalScoreOf($examId, $stuId),
            'total_count' => (int) $total['total'],
            'submitted'   => $score !== null && str_starts_with((string) $score['stu_status'], 'over'),
        ]);
    }

    /**
     * GET /api/exercise/mock/review?exam_id=N —— 错题回顾
     *
     * 旧系统 bug：先比较后归一化 → 多选题字母顺序不同即被误判为错题。
     * 本实现统一先归一化再比较。
     */
    public function review(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];
        $examId = (int) $this->request->query('exam_id', 0);
        $this->assertMockExam($examId, $stuId);

        // 错题回顾会整卷下发 quiz_key。暂停期间禁止查看：模拟考试可自由组卷，
        // 若不拦截，考生可组一场覆盖某科目全部题目的模拟考试并立即交卷，
        // 再借复盘导出该科目答案（可能是本场考试用题）。
        $this->assertNotPaused($stuId);

        // 仅复盘已交卷的模拟考试
        $score = Database::fetch(
            'SELECT stu_status, stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        if ($score === null || !str_starts_with((string) $score['stu_status'], 'over')) {
            throw new HttpException(409, '请先交卷后再查看错题回顾', 40902);
        }

        $papers = Database::fetchAll(
            'SELECT sp.paper_id, sp.quiz_class, sp.stu_key,
                    q.quiz_title, q.quiz_option, q.quiz_key, q.quiz_pic_name
             FROM `stupaper` sp
             INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ?
             ORDER BY sp.paper_id ASC',
            [$examId, $stuId]
        );

        $wrong = [];
        $right = 0;
        foreach ($papers as $p) {
            $type = (string) $p['quiz_class'];
            $correct = (string) ($p['quiz_key'] ?? '');
            $user = (string) ($p['stu_key'] ?? '');

            // 归一化后比较（修复旧系统缺陷）
            $isRight = $correct !== '' && Quiz::isCorrect($type, $correct, $user);
            if ($isRight) {
                $right++;
                continue;
            }
            $wrong[] = [
                'paper_id'        => (int) $p['paper_id'],
                'quiz_class'      => $type,
                'quiz_class_name' => Quiz::TYPE_LABELS[$type] ?? $type,
                'quiz_title'      => $p['quiz_title'],
                'quiz_option_list'=> Quiz::parseOptions((string) ($p['quiz_option'] ?? '')),
                'quiz_pic_name'   => $p['quiz_pic_name'] ?? '',
                'stu_key'         => $user,
                'quiz_key'        => $correct,
            ];
        }

        return $this->ok([
            'exam_id'     => $examId,
            'score'       => (int) ($score['stu_score'] ?? 0),
            'total_score' => self::totalScoreOf($examId, $stuId),
            'total_count' => count($papers),
            'right_count' => $right,
            'wrong'       => $wrong,
        ]);
    }

    /** POST /api/exercise/mock/logout —— 退出模拟考试 */
    public function logout(): Response
    {
        return $this->ok(null, '已退出模拟考试');
    }

    /* ------------------------------------------------------------------ */

    /**
     * 模拟考试暂停守卫：命中则 403（原因决定文案）。
     * 决策的唯一出处是 Exam::mockPause()，控制器只负责翻译成 HTTP 响应。
     */
    private function assertNotPaused(string $stuId): void
    {
        $pause = Exam::mockPause($stuId);
        if (!$pause['paused']) {
            return;
        }
        $msg = $pause['reason'] === Exam::PAUSE_SELF_IN_EXAM
            ? '你正在参加正式考试，不能同时进行模拟考试；请先交卷或退出考场'
            : '当前有正在进行的正式考试，模拟考试已临时暂停';
        throw new HttpException(403, $msg, 40308);
    }

    /** 校验 exam_id 确为该考生的模拟考试 */
    private function assertMockExam(int $examId, string $stuId): void
    {
        if ($examId <= 0) {
            throw new HttpException(400, '缺少 exam_id 参数', 40000);
        }
        $exam = (new Exam())->find($examId);
        if ($exam === null || (string) ($exam['exam_class'] ?? '') !== self::MOCK_CLASS) {
            throw new HttpException(404, '模拟考试不存在', 40400);
        }
        $score = Database::fetch(
            'SELECT id FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        if ($score === null) {
            throw new HttpException(403, '该模拟考试不属于当前考生', 40306);
        }
    }

    /** 判分并落库，返回结果摘要 */
    private static function gradeMock(int $examId, string $stuId): array
    {
        $papers = Database::fetchAll(
            'SELECT sp.paper_id, sp.quiz_id, sp.quiz_class, sp.stu_key, q.quiz_key
             FROM `stupaper` sp
             INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ?
             ORDER BY sp.paper_id ASC',
            [$examId, $stuId]
        );

        $score = 0;
        $total = 0;
        $right = 0;
        $wrong = [];
        // 已交卷的模拟考试重交：成绩不覆盖（下方 UPDATE 已用 LEFT(stu_status,4)<>'over' 守卫），
        // 错题本也不应重复累加 → 仅当本场尚未结束才沉淀。
        $alreadyOver = (function () use ($examId, $stuId): bool {
            $s = Database::fetch(
                'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
                [$examId, $stuId]
            );
            return $s !== null && str_starts_with((string) $s['stu_status'], 'over');
        })();
        Database::beginTransaction();
        try {
            foreach ($papers as $p) {
                $type = (string) $p['quiz_class'];
                $val = self::VALUE_MAP[$type] ?? 0;
                $total += $val;

                Database::query(
                    'UPDATE `stupaper` SET quiz_status = 1
                     WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
                    [$examId, $stuId, (int) $p['paper_id']]
                );

                $correct = (string) ($p['quiz_key'] ?? '');
                $user = (string) ($p['stu_key'] ?? '');
                if ($val > 0 && $correct !== '' && Quiz::isCorrect($type, $correct, $user)) {
                    $score += $val;
                    $right++;
                } else {
                    // 答错：收集进错题本（旁路，不阻断交卷）
                    $wrong[] = ['quiz_id' => (int) $p['quiz_id'], 'paper_id' => (int) $p['paper_id']];
                }
            }
            // 幂等门禁：已交卷的不再覆盖成绩（与 ExamEngine::autoGrade 对齐）
            Database::query(
                "UPDATE `stuscore` SET stu_score = ?, stu_status = 'over'
                 WHERE exam_id = ? AND stu_id = ? AND LEFT(stu_status, 4) <> 'over'",
                [$score, $examId, $stuId]
            );
            // 沉淀错题本（仅首次交卷，避免重复累加）
            if (!$alreadyOver) {
                WrongBook::collectMany($stuId, $wrong, 'mock', $examId);
            }
            // 模拟考试结束，阶段流转到 over
            Database::query("UPDATE `examinfo` SET exam_status = 'over' WHERE id = ?", [$examId]);
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'exam_id'     => $examId,
            'score'       => $score,
            'total_score' => $total,
            'total_count' => count($papers),
            'right_count' => $right,
        ];
    }

    /** 本次模拟考试的满分 */
    private static function totalScoreOf(int $examId, string $stuId): int
    {
        $rows = Database::fetchAll(
            'SELECT quiz_class, COUNT(*) AS c FROM `stupaper`
             WHERE exam_id = ? AND stu_id = ? GROUP BY quiz_class',
            [$examId, $stuId]
        );
        $n = 0;
        foreach ($rows as $r) {
            $n += (int) $r['c'] * (self::VALUE_MAP[(string) $r['quiz_class']] ?? 0);
        }
        return $n;
    }

    private static function computeTotalScore(array $wanted): int
    {
        $n = 0;
        foreach ($wanted as $type => $count) {
            $n += $count * (self::VALUE_MAP[$type] ?? 0);
        }
        return $n;
    }
}
