<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Password;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 考生管理 —— stuinfo
 * 权限点：student.view / student.add / student.edit / student.delete / student.import
 *
 * schema 注意：
 * - stuinfo.id 即准考证号（非自增），登录凭据
 * - grade_id / class_id 在库中为 varchar，按字符串处理
 * - 默认密码取考生本人准考证号
 *
 * 相对旧系统的改进：
 * - 新增走 id 唯一校验（不依赖数据库报错）
 * - 密码统一用 bcrypt（旧库 md5 由 Password::verify 兼容）
 * - 导入支持 CSV / TXT，按准考证号 UPSERT，逐行返回失败原因
 */
class StudentController extends BaseController
{
    private Student $model;
    private Grade $grades;
    private SchoolClass $classes;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model   = new Student();
        $this->grades  = new Grade();
        $this->classes = new SchoolClass();
    }

    /** GET /api/admin/students */
    public function index(): Response
    {
        $p = $this->page();
        $gradeId = trim((string) $this->request->query('grade_id', ''));
        $classId = trim((string) $this->request->query('class_id', ''));
        $kw = $p['keyword'];

        $where = ['1=1'];
        $params = [];
        if ($gradeId !== '') {
            $where[] = 'grade_id = ?';
            $params[] = $gradeId;
        }
        if ($classId !== '') {
            $where[] = 'class_id = ?';
            $params[] = $classId;
        }
        if ($kw !== '') {
            $where[] = '(stu_name LIKE ? OR id LIKE ?)';
            $params[] = self::like($kw);
            $params[] = self::like($kw);
        }
        $sqlWhere = implode(' AND ', $where);

        $total = (int) (Database::fetch(
            "SELECT COUNT(*) AS c FROM `stuinfo` WHERE {$sqlWhere}",
            $params
        )['c'] ?? 0);

        $list = Database::fetchAll(
            "SELECT * FROM `stuinfo` WHERE {$sqlWhere} ORDER BY id ASC
             LIMIT {$p['per_page']} OFFSET {$p['offset']}",
            $params
        );

        // 单位/班级名映射，避免前端再查一次
        $gradeMap = $this->nameMap($this->grades);
        $classMap = $this->nameMap($this->classes);
        foreach ($list as &$row) {
            $row = Student::sanitize($row) ?? $row;
            $row['grade_name'] = $gradeMap[(string) ($row['grade_id'] ?? '')] ?? '';
            $row['class_name'] = $classMap[(string) ($row['class_id'] ?? '')] ?? '';
        }
        unset($row);

        return $this->ok([
            'list'        => $list,
            'total'       => $total,
            'page'        => $p['page'],
            'per_page'    => $p['per_page'],
            'total_pages' => (int) ceil($total / max(1, $p['per_page'])),
            'grades'      => $this->grades->all('id ASC'),
            'classes'     => $this->classes->all('id ASC'),
        ]);
    }

    /** GET /api/admin/students/{id} */
    public function show(): Response
    {
        $id = (string) $this->request->param('id', '');
        $row = $this->model->find($id);
        if ($row === null) {
            throw new HttpException(404, '考生不存在', 40400);
        }

        $data = Student::sanitize($row);
        $data['grade_name'] = $this->nameOf($this->grades, (string) ($row['grade_id'] ?? ''));
        $data['class_name'] = $this->nameOf($this->classes, (string) ($row['class_id'] ?? ''));
        $data['scores'] = Database::fetchAll(
            'SELECT ss.exam_id, ss.stu_score, ss.stu_status, e.exam_name, s.subj_name
             FROM `stuscore` ss
             INNER JOIN `examinfo` e ON e.id = ss.exam_id
             INNER JOIN `subject` s ON s.id = e.subj_id
             WHERE ss.stu_id = ?
             ORDER BY ss.exam_id DESC',
            [$id]
        );

        return $this->ok($data);
    }

    /** GET /api/admin/students/check-id?id=xxx —— 准考证号是否可用 */
    public function checkId(): Response
    {
        $id = trim((string) $this->request->query('id', ''));
        if ($id === '') {
            throw new HttpException(400, '请提供准考证号', 40000);
        }
        return $this->ok(['id' => $id, 'exists' => $this->model->existsId($id)]);
    }

    /** POST /api/admin/students */
    public function save(): Response
    {
        $in = $this->validate([
            // stuinfo.id 为 int 列，非数字准考证号若放行会以 SQL 报错形式 500（并泄露 SQL），
            // 故与考生自助注册保持一致，在此按纯数字校验。
            'id'       => 'required|regex:/^\d{1,20}$/',
            'stu_name' => 'required|maxlen:50',
            'password' => 'maxlen:64',
            'stu_sex'  => 'maxlen:10',
            'grade_id' => 'maxlen:100',
            'class_id' => 'maxlen:100',
        ]);
        $id = $this->normalizeStuId($in['id']);

        if ($this->model->existsId($id)) {
            throw new HttpException(409, "准考证号「{$id}」已存在", 40900);
        }
        $name = trim((string) $in['stu_name']);
        if ($this->model->nameTaken($name)) {
            throw new HttpException(409, '该姓名已被其他考生使用', 40901);
        }

        $password = trim((string) ($in['password'] ?? ''));
        if ($password === '') {
            $password = $id; // 默认密码 = 准考证号
        } elseif (Password::isWeak($password, $id)) {
            throw new HttpException(400, '密码过于简单，请使用至少 6 位且非纯数字的组合', 40002);
        }

        // id 为业务主键，Model::create 无法回填，故直接写入
        Database::query(
            'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $id,
                $name,
                Password::hash($password),
                  trim((string) ($in['stu_sex'] ?? '')),
                  Grade::resolveId($in['grade_id'] ?? ''),
                  SchoolClass::resolveId($in['class_id'] ?? ''),
              ]
          );

        return $this->ok(Student::sanitize($this->model->find($id)), '添加成功');
    }

    /** PUT /api/admin/students/{id} */
    public function update(): Response
    {
        $id = (string) $this->request->param('id', '');
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '考生不存在', 40400);
        }

        $in = $this->validate([
            'stu_name' => 'required|maxlen:50',
            'password' => 'maxlen:64',
            'stu_sex'  => 'maxlen:10',
            'grade_id' => 'maxlen:100',
            'class_id' => 'maxlen:100',
        ]);
        $name = trim((string) $in['stu_name']);
        if ($this->model->nameTaken($name, $id)) {
            throw new HttpException(409, '该姓名已被其他考生使用', 40901);
        }

        $data = [
            'stu_name' => $name,
            'stu_sex'  => trim((string) ($in['stu_sex'] ?? '')),
            // 与 save()/import() 一致：单位/班级按「名称或 ID」归一为 ID（BUG-240）。
            // 否则把名称存进 class_id 会与 examinfo.stu_class（存 ID）失配，
            // 导致该考生从排卷/入场资格链上整体消失。
            'grade_id' => Grade::resolveId($in['grade_id'] ?? ''),
            'class_id' => SchoolClass::resolveId($in['class_id'] ?? ''),
        ];

        $password = trim((string) ($in['password'] ?? ''));
        if ($password !== '') {
            if (Password::isWeak($password, $id)) {
                throw new HttpException(400, '密码过于简单，请使用至少 6 位且非纯数字的组合', 40002);
            }
            $data['stu_pwd'] = Password::hash($password);
        }

        $this->model->update($id, $data);
        return $this->ok(Student::sanitize($this->model->find($id)), '修改成功');
    }

    /** DELETE /api/admin/students/{id} */
    public function delete(): Response
    {
        $id = (string) $this->request->param('id', '');
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '考生不存在', 40400);
        }

        Database::beginTransaction();
        try {
            $this->model->delete($id);
            // 级联清理该考生的答卷与成绩（旧系统会残留孤儿数据）
            Database::query('DELETE FROM `stupaper` WHERE stu_id = ?', [$id]);
            Database::query('DELETE FROM `stuscore` WHERE stu_id = ?', [$id]);
            // 备份行同样按 stu_id 关联，一并清理，避免孤儿数据
            Database::query('DELETE FROM `stuscorebak` WHERE stu_id = ?', [$id]);
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $this->ok(null, '删除成功');
    }

    /**
     * POST /api/admin/students/import —— 批量导入
     * 输入：CSV 文本（body: content）或上传文件（multipart: file）
     * 列顺序：准考证号, 姓名, 密码(可空), 性别, 单位id, 班级id
     */
    public function import(): Response
    {
        $content = $this->readImportContent();
        if (trim($content) === '') {
            throw new HttpException(400, '导入内容为空', 40000);
        }

        // 统一换行，去掉 BOM
        $content = str_replace("\xEF\xBB\xBF", '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $imported = 0;
        $errors = [];
        $rowNo = 0;

        foreach ($lines as $line) {
            $rowNo++;
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = str_contains($line, ',') ? str_getcsv($line) : explode("\t", $line);

            // 跳过表头
            if ($rowNo === 1 && preg_match('/准考证|stu.?id|姓名/u', $line)) {
                continue;
            }

            $sid = trim((string) ($parts[0] ?? ''));
            $name = trim((string) ($parts[1] ?? ''));
            if ($sid === '' || $name === '') {
                $errors[] = "第 {$rowNo} 行：缺少准考证号或姓名";
                continue;
            }

            $password = trim((string) ($parts[2] ?? ''));
            if ($password === '') {
                $password = $sid;
            }
            $sex = trim((string) ($parts[3] ?? ''));
            // CSV 里第 5/6 列通常是单位名与班级名，统一折算为 ID
            $gradeId = Grade::resolveId($parts[4] ?? '');
            $classId = SchoolClass::resolveId($parts[5] ?? '');

            // 姓名冲突（他人占用）则跳过，避免登录歧义
            if ($this->model->nameTaken($name, $sid)) {
                $errors[] = "第 {$rowNo} 行：姓名「{$name}」已被其他考生使用";
                continue;
            }

            try {
                $exists = $this->model->existsId($sid);
                if ($exists) {
                    // 已存在 → 更新资料（不改密码，除非显式提供）
                    $data = ['stu_name' => $name, 'stu_sex' => $sex, 'grade_id' => $gradeId, 'class_id' => $classId];
                    if (trim((string) ($parts[2] ?? '')) !== '') {
                        $data['stu_pwd'] = Password::hash($password);
                    }
                    $this->model->update($sid, $data);
                } else {
                    Database::query(
                        'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$sid, $name, Password::hash($password), $sex, $gradeId, $classId]
                    );
                }
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "第 {$rowNo} 行导入失败";
            }
        }

        if ($imported === 0) {
            throw new HttpException(
                400,
                '导入失败：' . implode('；', array_slice($errors, 0, 5)),
                40001,
                ['errors' => $errors]
            );
        }

        return $this->ok([
            'imported' => $imported,
            'failed'   => count($errors),
            'errors'   => array_slice($errors, 0, 50),
        ], "成功导入 {$imported} 名考生" . (count($errors) > 0 ? '，' . count($errors) . ' 条失败' : ''));
    }

    /* ------------------------------------------------------------------ */

    /** 读取导入内容：优先 multipart 文件，其次 body 里的 content 字段 */
    private function readImportContent(): string
    {
        $file = $this->request->file('file');
        if (is_array($file) && isset($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true)) {
                throw new HttpException(400, '只支持 CSV 或 TXT 格式文件', 40000);
            }
            $content = @file_get_contents((string) $file['tmp_name']);
            return $content === false ? '' : $content;
        }

        return (string) $this->request->input('content', '');
    }

    /** 名称 id → name 映射 */
    private function nameMap(object $model): array
    {
        $map = [];
        foreach ($model->all('id ASC') as $row) {
            $map[(string) $row['id']] = (string) ($row['grade_name'] ?? $row['class_name'] ?? '');
        }
        return $map;
    }

    private function nameOf(object $model, string $id): string
    {
        if ($id === '') {
            return '';
        }
        $row = $model->find($id);
        if ($row === null) {
            return '';
        }
        return (string) ($row['grade_name'] ?? $row['class_name'] ?? '');
    }
}
