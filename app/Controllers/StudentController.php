<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\StuScoreBak;
use App\Models\Student;
use App\Services\AuthSession;
use App\Services\Password;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 考生个人中心：资料、改密、成绩、待考考试
 */
class StudentController extends BaseController
{
    /** GET /api/student/info —— 个人信息（含单位/班级名称） */
    public function info(): Response
    {
        $sess = $this->authStudent();
        $row = (new Student())->find($sess['id']);
        if ($row === null) {
            throw new HttpException(404, '考生不存在', 40400);
        }
        $data = Student::sanitize($row);
        $data['grade_name'] = $this->nameOf(new Grade(), (string) ($row['grade_id'] ?? ''));
        $data['class_name'] = $this->nameOf(new SchoolClass(), (string) ($row['class_id'] ?? ''));
        return $this->ok($data);
    }

    /** PUT /api/student/info —— 修改资料（姓名、性别、单位、班级） */
    public function saveInfo(): Response
    {
        $sess = $this->authStudent();
        $in = $this->validate([
            'stu_name' => 'required|maxlen:50',
            'stu_sex'  => 'maxlen:10',
            'grade_id' => 'maxlen:100',
            'class_id' => 'maxlen:100',
        ]);

        $students = new Student();
        $row = $students->find($sess['id']);
        if ($row === null) {
            throw new HttpException(404, '考生不存在', 40400);
        }

        // 改名需保证唯一（原系统无此约束，重名会导致登录歧义）
        if ($in['stu_name'] !== $row['stu_name']) {
            $dup = $students->findByName($in['stu_name']);
            if ($dup !== null && (string) $dup['id'] !== (string) $row['id']) {
                throw new HttpException(409, '该姓名已被其他考生使用', 40900);
            }
        }

        $students->update($row['id'], [
            'stu_name' => $in['stu_name'],
            'stu_sex'  => $in['stu_sex'] ?? (string) $row['stu_sex'],
            'grade_id' => Grade::resolveId($in['grade_id'] ?? (string) $row['grade_id']),
            'class_id' => SchoolClass::resolveId($in['class_id'] ?? (string) $row['class_id']),
        ]);

        // 同步会话中的姓名
        $sess['stu_name'] = $in['stu_name'];
        sess_set(AuthSession::STUDENT, $sess);

        return $this->ok(Student::sanitize($students->find($row['id'])), '资料已更新');
    }

    /** PUT /api/student/password —— 修改密码 */
    public function savePwd(): Response
    {
        $sess = $this->authStudent();
        $in = $this->validate([
            'old_pwd' => 'required|maxlen:64',
            'new_pwd' => \App\Services\Password::rule(),
            'new_pwd2'=> 'required|maxlen:64',
        ]);
        if ($in['new_pwd'] !== $in['new_pwd2']) {
            throw new HttpException(400, '两次输入的新密码不一致', 40000);
        }

        $students = new Student();
        $row = $students->find($sess['id']);
        if ($row === null || !Password::verify($in['old_pwd'], (string) $row['stu_pwd'])) {
            throw new HttpException(400, '原密码不正确', 40000);
        }
        if (Password::isWeak($in['new_pwd'], (string) $row['stu_name'])) {
            throw new HttpException(400, '新密码过于简单，请使用至少 6 位且非纯数字的组合', 40000);
        }

        $students->update($row['id'], ['stu_pwd' => Password::hash($in['new_pwd'])]);
        // 改密后轮换会话与 CSRF 令牌，使旧令牌失效
        AuthSession::login(AuthSession::STUDENT, [
            'id'       => $row['id'],
            'stu_name' => $row['stu_name'],
            'grade_id' => $row['grade_id'] ?? '',
            'class_id' => $row['class_id'] ?? '',
        ]);

        return $this->ok(['csrf_token' => AuthSession::csrfToken()], '密码修改成功');
    }

    /** GET /api/student/scores —— 我的成绩（含统计与备份库历史） */
    public function scores(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];

        $live = Database::fetchAll(
            "SELECT sc.id, sc.exam_id, sc.stu_score, sc.stu_status,
                    e.exam_name, e.exam_start, e.exam_end, s.subj_name
             FROM `stuscore` sc
             LEFT JOIN `examinfo` e ON e.id = sc.exam_id
             LEFT JOIN `subject` s ON s.id = e.subj_id
             WHERE sc.stu_id = ?
             ORDER BY sc.exam_id DESC",
            [$stuId]
        );

        $stats = Database::fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN stu_status LIKE 'over%' THEN 1 ELSE 0 END) AS finished,
                    AVG(CASE WHEN stu_status LIKE 'over%' THEN stu_score END) AS avg_score,
                    MAX(stu_score) AS best_score
             FROM `stuscore` WHERE stu_id = ?",
            [$stuId]
        ) ?? [];

        return $this->ok([
            'list' => $live,
            'stats' => [
                'total'      => (int) ($stats['total'] ?? 0),
                'finished'   => (int) ($stats['finished'] ?? 0),
                'avg_score'  => $stats['avg_score'] !== null ? round((float) $stats['avg_score'], 1) : 0,
                'best_score' => (int) ($stats['best_score'] ?? 0),
            ],
            'history' => (new StuScoreBak())->where(['stu_id' => $stuId], 'id DESC'),
        ]);
    }

    /**
     * GET /api/student/exams —— 我的待考考试
     * 每场附带入场状态（entry_state/can_enter/入场时间/是否已开放入场），
     * 并顺带触发惰性自动开考。
     */
    public function exams(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];
        $list = Exam::pendingForStudent($stuId, (string) ($sess['class_id'] ?? ''));

        $examModel = new Exam();
        foreach ($list as &$e) {
            // 到点自动开考（惰性）
            Exam::autoStartIfDue((int) $e['id']);

            // 取最新考试状态（autoStart 可能刚推进状态）
            $fresh = $examModel->find((int) $e['id']);
            if ($fresh !== null) {
                $e['exam_status'] = $fresh['exam_status'];
                $e['exam_pwd']    = $fresh['exam_pwd'] ?? '';
            }

            // pendingForStudent 已 LEFT JOIN stuscore，直接据此判定入场状态
            $score = ($e['stu_status'] ?? null) !== null
                ? ['stu_status' => $e['stu_status'], 'stu_score' => $e['stu_score'] ?? 0]
                : null;
            $e = array_merge($e, Exam::entryState($e, $score));

            // 考场口令是入场凭证，绝不能下发给考生（前端只需 needs_pwd / pwd_ready
            // 这类布尔位）。此前把整行 exam_pwd 回传，考生刷接口即可拿到本场口令。
            unset($e['exam_pwd'], $e['stu_pwd']);
        }
        unset($e);

        return $this->ok(['list' => $list]);
    }

    /** GET /api/student/options —— 修改资料页的下拉数据 */
    public function options(): Response
    {
        $sess = $this->authStudent();
        return $this->ok([
            'grades'  => (new Grade())->all('id ASC'),
            'classes' => (new SchoolClass())->all('id ASC'),
            'current' => [
                'grade_id' => (string) ($sess['grade_id'] ?? ''),
                'class_id' => (string) ($sess['class_id'] ?? ''),
            ],
        ]);
    }

    /** 用字典表把 id 翻成名称（测试数据里 grade/class 存的是名称而非 id，两者都兼容） */
    private function nameOf(\Core\Model $model, string $id): string
    {
        if ($id === '') {
            return '';
        }
        $row = $model->find((int) $id);
        return (string) ($row['grade_name'] ?? $row['class_name'] ?? $id);
    }
}
