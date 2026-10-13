<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\StuScoreBak;
use App\Models\Student;
use App\Models\Quiz;
use App\Services\AuthSession;
use App\Services\Material;
use App\Services\Password;
use App\Services\ScoreBoard;
use App\Services\Survey;
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

    /**
     * GET /api/student/scores —— 我的成绩（含统计与备份库历史）
     *
     * 每行附带本场的 score_visibility 与 retake_of：前端据此决定是否显示
     * 「成绩榜」入口、以及是否给该行打「补考」标记 —— 都是已 join 出来的列，
     * 不额外查库。
     */
    public function scores(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];

        $live = Database::fetchAll(
            "SELECT sc.id, sc.exam_id, sc.stu_score, sc.stu_status,
                    e.exam_name, e.exam_start, e.exam_end, e.exam_score,
                    e.score_visibility, e.retake_of, e.cert_threshold, s.subj_name
             FROM `stuscore` sc
             LEFT JOIN `examinfo` e ON e.id = sc.exam_id
             LEFT JOIN `subject` s ON s.id = e.subj_id
             WHERE sc.stu_id = ?
             ORDER BY sc.exam_id DESC",
            [$stuId]
        );

        // 证书关联：一次查完，避免逐行查 certificate（N+1）
        $certs = [];
        foreach (Database::fetchAll(
            'SELECT exam_id, cert_no FROM `certificate` WHERE stu_id = ?',
            [$stuId]
        ) as $c) {
            $certs[(int) $c['exam_id']] = (string) $c['cert_no'];
        }

        // C5：哪些场次配了考后问卷。同样一次查完，不在循环里逐行问数据库。
        $surveyed = [];
        $examIds = array_values(array_filter(array_map(
            static fn (array $r): int => (int) ($r['exam_id'] ?? 0),
            $live
        ), static fn (int $id): bool => $id > 0));
        if ($examIds !== []) {
            $ph = implode(',', array_fill(0, count($examIds), '?'));
            foreach (Database::fetchAll(
                "SELECT DISTINCT exam_id FROM `exam_survey` WHERE exam_id IN ({$ph})",
                $examIds
            ) as $r) {
                $surveyed[(int) $r['exam_id']] = true;
            }
        }

        foreach ($live as &$row) {
            $row['cert_no'] = $certs[(int) $row['exam_id']] ?? '';
            $row['can_view_board'] = in_array(
                (string) ($row['score_visibility'] ?? Exam::VIS_PRIVATE),
                [Exam::VIS_CLASS, Exam::VIS_PUBLIC],
                true
            );
            $row['has_survey'] = isset($surveyed[(int) $row['exam_id']]);
        }
        unset($row);

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
            'certificates' => count($certs),
            'history' => (new StuScoreBak())->where(['stu_id' => $stuId], 'id DESC'),
        ]);
    }

    /**
     * GET /api/student/score-board?exam_id=N —— 本场成绩榜（文档 B4 成绩公示）
     *
     * 可见范围由**逐场配置**的 score_visibility 决定，判定只在 ScoreBoard 里做一次：
     *   private 仅本人 / class 本班同学 / public 全体考生。
     * 前端不得自行过滤 —— 否则「前端隐藏」会变成唯一防线。
     */
    public function scoreBoard(): Response
    {
        $sess = $this->authStudent();
        $examId = (int) $this->request->query('exam_id', 0);
        return $this->ok(ScoreBoard::forExam(
            $examId,
            (string) $sess['id'],
            (string) ($sess['class_id'] ?? '')
        ));
    }

    /* ------------------------------------------------------------------ */
    /* C5 考后问卷                                                          */
    /* ------------------------------------------------------------------ */

    /** GET /api/student/survey?exam_id=N —— 本场问卷（题目 + 本人已作答） */
    public function survey(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];
        $examId = (int) $this->request->query('exam_id', 0);
        if ($examId <= 0) {
            throw new HttpException(400, '缺少考试编号', 40000);
        }

        // 只有本场考生能看问卷：否则任何登录考生都能枚举 exam_id 读到别场的题目
        $row = Database::fetch(
            'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        if ($row === null) {
            throw new HttpException(404, '您未参加本场考试', 40400);
        }

        return $this->ok(['exam_id' => $examId, 'finished' => str_starts_with((string) ($row['stu_status'] ?? ''), 'over')]
            + Survey::forStudent($examId, $stuId));
    }

    /** POST /api/student/survey —— 提交考后反馈 */
    public function submitSurvey(): Response
    {
        $sess = $this->authStudent();
        $stuId = (string) $sess['id'];
        $examId = (int) $this->request->input('exam_id', 0);
        if ($examId <= 0) {
            throw new HttpException(400, '缺少考试编号', 40000);
        }

        $row = Database::fetch(
            'SELECT stu_status FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
            [$examId, $stuId]
        );
        if ($row === null) {
            throw new HttpException(404, '您未参加本场考试', 40400);
        }
        // 交卷前不允许填反馈：题目都还没做完，反馈没有意义，
        // 而且会诱导考生为了填问卷而提前交卷。
        if (!str_starts_with((string) ($row['stu_status'] ?? ''), 'over')) {
            throw new HttpException(400, '考试交卷后才能填写反馈', 40000);
        }

        // 通用 validate() 会拒绝数组（"必须是标量值"），answers 天然是 map，手工取
        $raw = $this->request->input('answers', []);
        if (!is_array($raw)) {
            throw new HttpException(400, '提交内容格式不正确', 40000);
        }

        $result = Survey::submit($examId, $stuId, $raw);
        return $this->ok($result, '反馈已提交，感谢您的评价');
    }

    /* ------------------------------------------------------------------ */
    /* C3 学习资料库（考生端）                                              */
    /* ------------------------------------------------------------------ */

    /** GET /api/student/materials —— 浏览学习资料 */
    public function materials(): Response
    {
        $this->authStudent();
        // 同 StudentWrongBookController::index()：复用 page() 取**带上限**的分页参数，
        // 避免 ($page-1)*$perPage 溢出成浮点后 OFFSET 插值报 SQL 语法错误（BUG-260）。
        // page() 返回关联数组，须按键名取值。
        $p = $this->page(null, 60);
        $page = (int) $p['page'];
        $perPage = (int) $p['per_page'];

        $result = Material::list(
            [
                'subj_id'  => (int) $this->request->query('subj_id', 0),
                'category' => (string) $this->request->query('category', ''),
                'keyword'  => (string) $this->request->query('keyword', ''),
            ],
            ($page - 1) * $perPage,
            $perPage
        );

        return $this->ok([
            'data'       => $result['data'],
            'total'      => $result['total'],
            'page'       => $page,
            'per_page'   => $perPage,
            'categories' => Material::categories(),
        ]);
    }

    /** POST /api/student/materials/{id}/hit —— 记一次浏览/下载 */
    public function materialHit(): Response
    {
        $this->authStudent();
        $id = $this->idParam();
        Material::hit($id);
        return $this->ok(['id' => $id]);
    }

    /**
     * GET /api/student/quiz-search —— 题目搜索（学习检索）
     *
     * 考生登录后按「科目 + 题干关键词（≥3 字）」模糊检索题库，返回题干、选项与
     * 正确答案（正确选项以 correct 标记），供背题 / 查阅。
     *
     * 分页：缺省分页（每页 20）；`all=1` 时返回该关键词的**全部**命中（不分页，上限 5000），
     * 供前端「取一次完整集、之后在已加载结果上继续精化」——继续输入只在本地过滤、不发新请求，
     * 仅当关键词变宽/改变（不再是已加载关键词的子串）时才重新取。
     *
     * ⚠️ 与考试 / 练习不同，本入口是「学习检索」场景，**刻意下发 quiz_key 与正确选项**：
     *    Quiz::withoutAnswer 的「答案绝不下发给考生端」约束只适用于判分链路
     *    （正式考试 / 练习 / 模拟考），此处是明确的例外——只读、不判分、不写成绩。
     *    仅按题干 quiz_title 匹配，不触及任何考试会话或成绩。
     */
    public function quizSearch(): Response
    {
        $this->authStudent();

        $subjId  = (int) $this->request->query('subj_id', 0);
        $keyword = trim((string) $this->request->query('keyword', ''));
        $class   = trim((string) $this->request->query('quiz_class', ''));

        $empty = static fn (string $hint, string $kw): array => [
            'list' => [], 'total' => 0, 'page' => 1,
            'per_page' => 0, 'total_pages' => 0, 'keyword' => $kw, 'hint' => $hint,
        ];
        if ($subjId <= 0) {
            return $this->ok($empty('请先选择科目', $keyword));
        }
        // 题干关键词至少 3 字：避免过短关键词导致全表扫描与无意义结果
        if (mb_strlen($keyword) < 3) {
            return $this->ok($empty('请输入至少 3 个字的题干关键词', $keyword));
        }

        // all=1：返回该关键词的「全部」命中（不分页），供前端在已加载结果上继续精化筛选；
        // 缺省仍走分页（兼容其他调用方）。
        $all = (int) $this->request->query('all', 0) === 1;

        $where  = ['q.subj_id = ?', 'q.quiz_title LIKE ?'];
        $params = [$subjId, '%' . addcslashes($keyword, '%_\\') . '%'];
        if ($class !== '' && in_array($class, Quiz::TYPES, true)) {
            $where[]  = 'q.quiz_class = ?';
            $params[] = $class;
        }
        $sqlWhere = implode(' AND ', $where);

        $total = (int) (Database::fetch("SELECT COUNT(*) AS c FROM `quizlib` q WHERE {$sqlWhere}", $params)['c'] ?? 0);

        $selectSql = "SELECT q.id, q.quiz_title, q.quiz_class, q.quiz_diff, q.quiz_kp, q.quiz_option, q.quiz_key, s.subj_name
                      FROM `quizlib` q INNER JOIN `subject` s ON s.id = q.subj_id
                      WHERE {$sqlWhere} ORDER BY q.id";
        if ($all) {
            // 安全上限：单科目题库量级（当前最大 1601），防止异常大结果集
            $cap = 5000;
            $page = 1;
            $perPage = $total;
            $rows = Database::fetchAll($selectSql . " LIMIT {$cap}", $params);
        } else {
            $p = $this->page(20, 100);
            $page = $p['page'];
            $perPage = $p['per_page'];
            $rows = Database::fetchAll($selectSql . " LIMIT {$perPage} OFFSET {$p['offset']}", $params);
        }

        foreach ($rows as &$r) {
            $cls = (string) $r['quiz_class'];
            $key = strtoupper((string) ($r['quiz_key'] ?? ''));
            // 多选答案为字母集合（去重）；单选/判断为单字母
            $correctLetters = $cls === 'checkbox'
                ? array_values(array_unique(str_split($key)))
                : str_split($key);
            $opts = Quiz::parseOptions((string) ($r['quiz_option'] ?? ''));
            foreach ($opts as &$o) {
                $o['correct'] = in_array($o['key'], $correctLetters, true);
            }
            unset($o);
            $r['quiz_option_list'] = $opts;
            $r['quiz_key']         = $key;   // 学习检索：下发答案
            $r['quiz_type_label']  = Quiz::TYPE_LABELS[$cls] ?? '';
            $r['quiz_diff_label']  = Quiz::DIFF_LABELS[$r['quiz_diff'] ?? ''] ?? '';
            unset($r['quiz_option']);
        }
        unset($r);

        return $this->ok([
            'list'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $all ? 1 : (int) ceil($total / max(1, $perPage)),
            'keyword'     => $keyword,
            'all'         => $all,
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

        // 两轮：先让所有场次完成惰性开考，再一次性取回最新状态。
        // 逐场 find() 在这个列表上是典型的 N+1（BUG-220）；状态必须在流转之后读，
        // 所以整体后移为一次 IN 查询，而不是把查询提到循环外。
        foreach ($list as $e) {
            Exam::autoStartIfDue((int) $e['id']);
        }
        $freshMap = [];
        $ids = array_map(static fn (array $e): int => (int) $e['id'], $list);
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Database::fetchAll(
                "SELECT id, exam_status, exam_pwd FROM `examinfo` WHERE id IN ({$ph})",
                $ids
            ) as $r) {
                $freshMap[(int) $r['id']] = [
                    'exam_status' => (string) ($r['exam_status'] ?? ''),
                    'exam_pwd'    => (string) ($r['exam_pwd'] ?? ''),
                ];
            }
        }

        foreach ($list as &$e) {
            // 惰性开考可能刚推进状态，用刷新后的值覆盖
            if (isset($freshMap[(int) $e['id']])) {
                $e['exam_status'] = $freshMap[(int) $e['id']]['exam_status'];
                // 考生端「已开放入场」判定依赖 exam_pwd，但口令是入场凭证、绝不能下发给考生：
                // 这里仅临时喂给 entryState() 计算 pwd_ready，末尾的 unset 会统一剥离 exam_pwd。
                // （pendingForStudent 的 SELECT 故意不含 exam_pwd，避免首页等其他调用方泄密；
                //  本端点是唯一需要该值来计算入场状态的入口。）
                $e['exam_pwd'] = $freshMap[(int) $e['id']]['exam_pwd'];
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
