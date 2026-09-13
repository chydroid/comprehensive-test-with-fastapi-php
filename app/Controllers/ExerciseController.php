<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Subject;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 在线练习（随机抽一题、逐题练习）
 *
 * 业务约束（沿用旧系统）：存在进行中的正式考试（exam_status = 'testing'）时，
 * 为避免泄露同题库题目，暂停练习功能。
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

        // 进行中的正式考试会锁定练习（防题目泄露）
        $ongoing = Database::fetch("SELECT COUNT(*) AS c FROM `examinfo` WHERE exam_status = 'testing'");
        $hasOngoingExam = (int) ($ongoing['c'] ?? 0) > 0;

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

        if (!$hasOngoingExam && $subjId > 0 && $quizClass !== '') {
            $quizCount = $types[$quizClass] ?? 0;
            if ($quizCount === 0) {
                throw new HttpException(404, '该科目下此题型暂无题目', 40400);
            }
            $offset = random_int(0, $quizCount - 1);
            $row = Database::fetch(
                'SELECT q.id, q.subj_id, q.quiz_title, q.quiz_class, q.quiz_option,
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
        ]);
    }

    /**
     * POST /api/exercise/answer —— 校验练习答案并返回解析
     * body: { quiz_id, stu_key }
     */
    public function check(): Response
    {
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
