<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Models\Subject;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 在线练习（随机抽一题、逐题练习）
 *
 * 与正式考试的隔离策略由后台「系统设置 → 模拟考试与练习」控制
 * （`exercise_allow_during_exam`，默认**开启**即互不影响）：
 *  - 开启：存在进行中的正式考试时，练习照常可用；
 *  - 关闭：开考期间暂停练习抽题与答案校验。
 *
 * 关闭项存在的原因：本接口按 quiz_id 即可换取任意题目答案，而开考期间
 * /api/exam/paper 会向考生下发其所考题目的 quiz_id —— 若不加限制，
 * 考生可用另一标签页「练习」反查正在考的题目答案（击穿「考试中不下发答案」）。
 * 是否接受该风险属考场纪律取舍，故做成开关而非写死。
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
        $in = $this->validate([
            'subj_id'    => 'integer',
            'quiz_class' => 'in:radio1,radio2,checkbox,text,longtext',
        ]);
        $subjId = (int) ($in['subj_id'] ?? 0);
        $quizClass = (string) ($in['quiz_class'] ?? '');

        $subjects = (new Subject())->all('id ASC');

        // 进行中的正式考试事实（排除模拟考试——它同样以 exam_status='testing'
        // 落库，否则学生自己开一场模拟考试就会把练习误判为「已暂停」）。
        $hasOngoingExam = Exam::hasOngoingFormalExam();
        // 是否因「考场纪律策略」暂停练习：事实 + 后台开关共同决定。
        $practicePaused = $hasOngoingExam && !Exam::allowExerciseDuringExam();

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
            // has_ongoing_exam 是「事实」：当前是否有正式考试在进行。
            'has_ongoing_exam' => $hasOngoingExam,
            // practice_paused 是「策略结论」：练习当前是否被禁用。
            // 前端据此决定是否拦截操作（二者不再等价，必须分开下发）。
            'practice_paused'  => $practicePaused,
        ]);
    }

    /**
     * POST /api/exercise/answer —— 校验练习答案并返回解析
     * body: { quiz_id, stu_key }
     */
    public function check(): Response
    {
        // 仅当后台关闭「正式考试期间开放在线练习」时才拦截。
        // 拦截原因见类注释：本接口按 quiz_id 即可换取任意题目的正确答案，
        // 而 /api/exam/paper 又向考生下发了所考题目的 quiz_id —— 若不拦截，
        // 考生可在开考期间用另一标签页「练习」反查正在考的题目答案，
        // 等于绕过「考试中不下发答案」的防护。
        if (Exam::hasOngoingFormalExam() && !Exam::allowExerciseDuringExam()) {
            throw new HttpException(403, '当前有正在进行的正式考试，练习功能已临时暂停', 40308);
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

        return $this->ok([
            'correct'      => $isRight,
            'answer'       => $correct,
            'user_answer'  => $user,
            'question_id'  => $quizId,
        ], $isRight ? '回答正确' : '回答错误');
    }
}
