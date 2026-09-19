<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Quiz;
use App\Services\ExamEngine;
use App\Services\WrongBook;
use Core\Database;
use Core\HttpException;

/**
 * 考生错题本（A1）
 *
 * 错题在三个判分落点（正式考试 / 模拟考试 / 在线练习）自动沉淀到 wrong_book 表，
 * 本控制器只负责「查看」与「错题重练」：
 * - GET  /api/student/wrong-book        列表 + 统计（支持 mastered/subj_id/quiz_class/exam_type 过滤）
 * - GET  /api/student/wrong-book/practice 抽取待重练题目（不下发答案）
 * - POST /api/student/wrong-book/check   校验重练答案，答对标记 mastered、答错继续归集
 */
class StudentWrongBookController extends BaseController
{
    /** GET /api/student/wrong-book */
    public function index(): \Core\Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];

        $in = $this->validate([
            'page'       => 'integer|min:1',
            'per_page'   => 'integer|min:1|max:100',
            'mastered'   => 'in:0,1',
            'subj_id'    => 'integer',
            'quiz_class' => 'in:radio1,radio2,checkbox,text,longtext',
            'exam_type'  => 'in:formal,mock,exercise',
        ]);

        $page = max(1, (int) ($in['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($in['per_page'] ?? 20)));

        $filters = [];
        if (isset($in['mastered'])) {
            $filters['mastered'] = (int) $in['mastered'];
        }
        if (!empty($in['subj_id'])) {
            $filters['subj_id'] = (int) $in['subj_id'];
        }
        if (!empty($in['quiz_class'])) {
            $filters['quiz_class'] = $in['quiz_class'];
        }
        if (!empty($in['exam_type'])) {
            $filters['exam_type'] = $in['exam_type'];
        }

        $res = WrongBook::list($stuId, $filters, $page, $perPage);
        foreach ($res['data'] as &$row) {
            $row['quiz_type_label'] = Quiz::TYPE_LABELS[$row['quiz_class']] ?? '未知';
            $row['quiz_diff_label'] = Quiz::DIFF_LABELS[$row['quiz_diff']] ?? '';
            $row['quiz_option_list'] = Quiz::parseOptions((string) ($row['quiz_option'] ?? ''));
        }
        unset($row);

        $subjects = Database::fetchAll('SELECT id, subj_name FROM `subject` ORDER BY id ASC');

        return $this->ok([
            'list'     => $res['data'],
            'total'    => $res['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'stats'    => $res['stats'],
            'subjects' => $subjects,
        ]);
    }

    /** GET /api/student/wrong-book/practice */
    public function practice(): \Core\Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];

        $in = $this->validate([
            'subj_id' => 'integer',
            'limit'   => 'integer|min:1|max:100',
        ]);
        $subjId = (int) ($in['subj_id'] ?? 0);
        $limit = min(100, max(1, (int) ($in['limit'] ?? 20)));

        $res = WrongBook::practicePick($stuId, $subjId, $limit);
        return $this->ok($res);
    }

    /** POST /api/student/wrong-book/check */
    public function check(): \Core\Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];

        $in = $this->validate([
            'quiz_id' => 'required|integer',
            'stu_key' => 'maxlen:2000',
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
        $user = ExamEngine::normalizeSubmission($type, $in['stu_key'] ?? '');
        $isRight = $correct !== '' && Quiz::isCorrect($type, $correct, $user);

        if ($isRight) {
            WrongBook::markMastered($stuId, $quizId);
        } else {
            // 重练仍错：确保仍留在错题本并累加一次
            WrongBook::collect($stuId, $quizId, 'exercise');
        }

        return $this->ok([
            'correct'      => $isRight,
            'answer'       => $correct,
            'user_answer'  => $user,
            'question_id'  => $quizId,
        ], $isRight ? '回答正确，已标记为掌握' : '仍答错，继续加油');
    }
}
