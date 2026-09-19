<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Models\Subject;
use App\Services\WrongBook;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 在线练习（随机抽一题、逐题练习）
 *
 * 与正式考试的关系分两层，均由 Exam::exercisePause() 统一裁决：
 *  ① 全局层：存在进行中的正式考试时**默认暂停**练习，管理员可在
 *     「系统设置 → 模拟考试与练习」开启 `exercise_allow_during_exam` 放开；
 *  ② 个人层：考生本人正在考场内（已入场且考试进行中）时**一律暂停**，
 *     后台开关无法放开——同一人一边答题一边练习反查答案是纪律红线。
 *
 * 为什么必须拦：本接口按 quiz_id 即可换取任意题目答案，而开考期间
 * /api/exam/paper 会向考生下发其所考题目的 quiz_id —— 若不拦截，
 * 考生可用另一标签页「练习」反查正在考的题目答案（击穿「考试中不下发答案」）。
 *
 * 安全改进：练习模式同样不下发 quiz_key —— 答案通过独立的
 * POST /api/exercise/answer 校验后才返回，且前端不预置答案。
 */
class ExerciseController extends BaseController
{
    /**
     * GET|POST /api/exercise
     * 参数：subj_id、quiz_class，按当前科目+题型随机抽一题
     */
    public function index(): Response
    {
        // 暂停判定需要「本人是否在考场内」，故必须取当前考生身份
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];

        $in = $this->validate([
            'subj_id'    => 'integer',
            'quiz_class' => 'in:radio1,radio2,checkbox,text,longtext',
        ]);
        $subjId = (int) ($in['subj_id'] ?? 0);
        $quizClass = (string) ($in['quiz_class'] ?? '');

        $subjects = (new Subject())->all('id ASC');

        // has_ongoing_exam 是「事实」：当前是否有正式考试在进行（排除模拟考试
        // —— 它同样以 exam_status='testing' 落库，否则学生自己开一场模拟考试
        // 就会把练习误判为「已暂停」）。
        $hasOngoingExam = Exam::hasOngoingFormalExam();
        // 暂停结论由「全局开关 + 本人是否在考」共同得出，reason 用于前端区分文案。
        $pause = Exam::exercisePause($stuId);
        $practicePaused = $pause['paused'];

        $question = null;
        $quizCount = 0;
        $types = [];

        if ($subjId > 0) {
            // 该科目下各题型可用题量，供前端选择
            $rows = Database::fetchAll(
                'SELECT quiz_class, COUNT(*) AS c FROM `quizlib`
                 WHERE subj_id = ? GROUP BY quiz_class',
                [$subjId]
            );
            foreach ($rows as $r) {
                $types[(string) $r['quiz_class']] = (int) $r['c'];
            }
        }

        if (!$practicePaused && $subjId > 0 && $quizClass !== '') {
            $quizCount = $types[$quizClass] ?? 0;
            if ($quizCount === 0) {
                throw new HttpException(404, '该科目下此题型暂无题目', 40400);
            }
            $offset = random_int(0, $quizCount - 1);
            $row = Database::fetch(
                'SELECT q.id AS quiz_id, q.subj_id, q.quiz_title, q.quiz_class, q.quiz_option,
                        q.quiz_diff, q.quiz_pic_name, s.subj_name
                 FROM `quizlib` q
                 INNER JOIN `subject` s ON s.id = q.subj_id
                 WHERE q.subj_id = ? AND q.quiz_class = ?
                 ORDER BY q.id ASC LIMIT 1 OFFSET ' . $offset,
                [$subjId, $quizClass]
            );
            if ($row !== null) {
                // 练习时也不带答案下发（答案走 /api/exercise/answer 校验接口）
                $question = \App\Models\Quiz::withoutAnswer($row);
            }
        }

        return $this->ok([
            'subjects'         => $subjects,
            'types'            => $types,
            'question'         => $question,
            'subj_id'          => $subjId,
            'quiz_class'       => $quizClass,
            'quiz_count'       => $quizCount,
            'has_ongoing_exam' => $hasOngoingExam,
            // practice_paused 是「策略结论」：练习当前是否被禁用。
            // 前端据此决定是否拦截操作（与 has_ongoing_exam 不再等价）。
            'practice_paused'  => $practicePaused,
            // pause_reason：'self_in_exam'（本人在考，硬约束）/
            //               'exam_ongoing'（全局策略暂停）/ ''（未暂停）
            'pause_reason'     => $pause['reason'],
        ]);
    }

    /**
     * POST /api/exercise/answer —— 校验练习答案并返回解析
     * body: { quiz_id, stu_key }
     */
    public function check(): Response
    {
        $sess = $this->authStudent();
        $pause = Exam::exercisePause((string) $sess['id']);
        // 拦截原因见类注释：本接口按 quiz_id 即可换取任意题目的正确答案，
        // 而 /api/exam/paper 又向考生下发了所考题目的 quiz_id —— 若不拦截，
        // 考生可在开考期间用另一标签页「练习」反查正在考的题目答案，
        // 等于绕过「考试中不下发答案」的防护。
        if ($pause['paused']) {
            throw new HttpException(403, self::pauseMessage($pause['reason']), 40308);
        }

        $in = $this->validate([
            'quiz_id' => 'required|integer',
        ]);
        $quizId = (int) $in['quiz_id'];

        $row = Database::fetch(
            'SELECT id, quiz_class, quiz_key FROM `quizlib` WHERE id = ? LIMIT 1',
            [$quizId]
        );
        if ($row === null) {
            throw new HttpException(404, '题目不存在', 40400);
        }

        $type = (string) $row['quiz_class'];
        $correct = (string) $row['quiz_key'];
        $userRaw = $this->request->input('stu_key', '');
        $user = \App\Services\ExamEngine::normalizeSubmission($type, $userRaw);

        // 记录答题次数，用于题库质量统计（对应旧库 quiz_hits / quiz_key_ok）
        $isRight = $correct !== '' && \App\Models\Quiz::isCorrect($type, $correct, $user);
        Database::query(
            'UPDATE `quizlib` SET quiz_hits = quiz_hits + 1' . ($isRight ? ', quiz_key_ok = quiz_key_ok + 1' : '') . ' WHERE id = ?',
            [$quizId]
        );

        // 错题本沉淀（旁路，不影响练习校验）：答对则标记掌握，答错则归集。
        $stuId = (string) $sess['id'];
        if ($isRight) {
            WrongBook::markMastered($stuId, $quizId);
        } else {
            WrongBook::collect($stuId, $quizId, 'exercise');
        }

        return $this->ok([
            'correct'      => $isRight,
            'answer'       => $correct,
            'user_answer'  => $user,
            'question_id'  => $quizId,
        ], $isRight ? '回答正确' : '回答错误');
    }

    /** 按暂停原因给出面向考生的文案（两种原因的可行动指引不同） */
    private static function pauseMessage(string $reason): string
    {
        return $reason === Exam::PAUSE_SELF_IN_EXAM
            ? '你正在参加正式考试，不能同时进行在线练习；请先交卷或退出考场'
            : '当前有正在进行的正式考试，练习功能已临时暂停';
    }
}
