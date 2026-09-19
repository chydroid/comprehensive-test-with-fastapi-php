<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Exam;
use App\Models\Quiz;
use App\Models\Subject;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 题库管理 —— quizlib
 * 权限点：quiz.view / quiz.add / quiz.edit / quiz.delete / quiz.clean
 *
 * 重要约定（继承旧系统的权威题型映射，勿凭枚举名臆断）：
 *   radio1=判断题 / radio2=单选题 / checkbox=多选题 / text=填空题 / longtext=问答题
 *
 * 本控制器修复的旧系统缺陷：
 * - 旧 update() 判断 `!in_array($quizClass, ['radio1','text','longtext'])` 才写
 *   quiz_option，导致单选题/多选题换题型后选项丢失；此处按「是否客观选择题」判定。
 * - 旧 autoClean() 仅按题干去重，会误删「同题干不同选项」的题；此处按
 *   (科目 + 题干 + 选项) 三元组去重。
 * - 高级清理增加了「考试进行中禁止清理」的前置校验（保留旧系统该保护）。
 */
class QuizController extends BaseController
{
    /** 有选项的题型（判断/单选/多选） */
    private const OPTION_TYPES = ['radio1', 'radio2', 'checkbox'];

    private Quiz $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new Quiz();
    }

    /** GET /api/admin/quizzes */
    public function index(): Response
    {
        $p = $this->page();
        $filters = [
            'subj_id'    => (int) $this->request->query('subj_id', 0),
            'quiz_class' => (string) $this->request->query('quiz_class', ''),
            'quiz_diff'  => (string) $this->request->query('quiz_diff', ''),
            'keyword'    => $p['keyword'],
        ];
        $this->assertFilters($filters);

        $result = $this->model->adminList($filters, $p['offset'], $p['per_page']);

        return $this->ok([
            'list'        => $result['data'],
            'total'       => $result['total'],
            'page'        => $p['page'],
            'per_page'    => $p['per_page'],
            'total_pages' => (int) ceil($result['total'] / max(1, $p['per_page'])),
            'subjects'    => (new Subject())->all('id ASC'),
            'types'       => $this->typeOptions(),
            'diffs'       => $this->diffOptions(),
        ]);
    }

    /** GET /api/admin/quizzes/{id} */
    public function show(): Response
    {
        $row = $this->model->detail($this->idParam());
        if ($row === null) {
            throw new HttpException(404, '试题不存在', 40400);
        }
        $row['quiz_option_list'] = Quiz::parseOptions((string) ($row['quiz_option'] ?? ''));
        $row['quiz_type_label']  = Quiz::TYPE_LABELS[$row['quiz_class'] ?? ''] ?? '';
        return $this->ok($row);
    }

    /** POST /api/admin/quizzes */
    public function save(): Response
    {
        $sess = $this->authAdmin();
        $in = $this->validate([
            'subj_id'       => 'required|integer',
            'quiz_title'    => 'required|maxlen:65535',
            'quiz_class'    => 'required|in:' . implode(',', Quiz::TYPES),
            'quiz_diff'     => 'required|in:' . implode(',', Quiz::DIFFS),
            'quiz_option'   => 'maxlen:65535',
            'quiz_key'      => 'maxlen:255',
            'quiz_pic_name' => 'maxlen:255',
            'quiz_writer'   => 'maxlen:50',
            'quiz_time'     => 'maxlen:10',
            'quiz_kp'       => 'maxlen:120',
        ]);

        $subjId = (int) $in['subj_id'];
        if ((new Subject())->find($subjId) === null) {
            throw new HttpException(400, '所选科目不存在', 40001);
        }

        $data = $this->buildRow($in);
        // 尊重表单里的「录题人 / 日期」（题库弹窗有这两个输入框），留空才回落默认值。
        // 此前一律用当前账号与当天覆盖，用户填了也白填。
        $data['quiz_writer'] = self::pickWriter($in['quiz_writer'] ?? null, (string) ($sess['username'] ?? ''));
        $data['quiz_time']   = self::pickQuizDate($in['quiz_time'] ?? null);
        $data['quiz_hits']   = 0;
        $data['quiz_key_ok'] = 0;

        $id = $this->model->create($data);
        $this->audit('quiz.create', 'quiz:' . $id, ['quiz_class' => $in['quiz_class'], 'subj_id' => $subjId]);
        return $this->ok($this->model->find($id), '添加成功');
    }

    /** PUT /api/admin/quizzes/{id} */
    public function update(): Response
    {
        $id = $this->idParam();
        $row = $this->model->find($id);
        if ($row === null) {
            throw new HttpException(404, '试题不存在', 40400);
        }

        $in = $this->validate([
            'subj_id'       => 'required|integer',
            'quiz_title'    => 'required|maxlen:65535',
            'quiz_class'    => 'required|in:' . implode(',', Quiz::TYPES),
            'quiz_diff'     => 'required|in:' . implode(',', Quiz::DIFFS),
            'quiz_option'   => 'maxlen:65535',
            'quiz_key'      => 'maxlen:255',
            'quiz_pic_name' => 'maxlen:255',
            'quiz_writer'   => 'maxlen:50',
            'quiz_time'     => 'maxlen:10',
            'quiz_kp'       => 'maxlen:120',
        ]);

        $subjId = (int) $in['subj_id'];
        if ((new Subject())->find($subjId) === null) {
            throw new HttpException(400, '所选科目不存在', 40001);
        }

        $data = $this->buildRow($in);
        $writer = self::pickWriter($in['quiz_writer'] ?? null, '');
        if ($writer !== '') {
            $data['quiz_writer'] = $writer;
        }
        $qtime = self::pickQuizDate($in['quiz_time'] ?? null, '');
        if ($qtime !== '') {
            $data['quiz_time'] = $qtime;
        }

        $this->model->update($id, $data);
        $this->audit('quiz.update', 'quiz:' . $id, ['quiz_class' => $in['quiz_class'], 'subj_id' => $subjId]);
        return $this->ok($this->model->find($id), '修改成功');
    }

    /** DELETE /api/admin/quizzes/{id} */
    public function delete(): Response
    {
        $id = $this->idParam();
        if ($this->model->find($id) === null) {
            throw new HttpException(404, '试题不存在', 40400);
        }
        $this->model->delete($id);
        $this->audit('quiz.delete', 'quiz:' . $id, []);
        return $this->ok(null, '删除成功');
    }

    /** POST /api/admin/quizzes/batch-delete —— body: {ids:[1,2,3]} 或 {ids:"1,2,3"} */
    public function batchDelete(): Response
    {
        $ids = $this->rawIds('ids');
        if ($ids === []) {
            throw new HttpException(400, '请选择要删除的试题', 40000);
        }
        $deleted = $this->model->deleteMany($ids);
        $this->audit('quiz.batch-delete', 'quiz:batch', ['ids' => $ids, 'deleted' => $deleted]);
        return $this->ok(['deleted' => $deleted], "批量删除成功，共删除 {$deleted} 条试题");
    }

    /**
     * POST /api/admin/quizzes/import —— 题库批量导入（CSV / TXT）
     *
     * 输入：CSV 文本（body: content）或上传文件（multipart: file）
     * 列顺序：科目, 题型, 题干, 选项, 答案, 难度, 录题人, 知识点
     *   - 科目：科目名或科目 ID（Subject::resolveId 归一）
     *   - 题型：中文名（判断题/单选题/多选题/填空题/问答题）或代码（radio1/radio2/checkbox/text/longtext）
     *   - 选项：仅选择题需要，用 | 分隔（如 `对|错` 或 `A.甲|B.乙|C.丙`）；非选择题忽略该列
     *   - 答案：选择题为字母（多选可 `AC`/`CA`，自动去重排序）；判断题兼容 对/错、√/×、T/F；填空/问答保留原文
     *   - 难度：易/中/难 或 Y/Z/N（留空默认「中」）
     *   - 录题人：留空回落为当前登录账号
     *   - 知识点：可选，供「按知识点组卷」与学情分析使用（A3 维度，列宽 120）
     *
     * 逐行校验、逐行写入：单行失败只记错误不中断整批（与考生批量导入一致）。
     */
    public function import(): Response
    {
        $sess = $this->authAdmin();
        $content = $this->readImportContent();
        if (trim($content) === '') {
            throw new HttpException(400, '导入内容为空', 40000);
        }

        // 去 BOM + 统一换行
        $content = str_replace("\xEF\xBB\xBF", '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $subject = new Subject();
        $defaultWriter = mb_substr((string) ($sess['username'] ?? ''), 0, 50);
        $today = date('Y-m-d');

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

            // 跳过表头（仅首行，且必须「题型列不是合法题型」+ 命中多个表头特征词）
            if ($rowNo === 1 && self::looksLikeHeader($parts)) {
                continue;
            }

            $subjRaw = trim((string) ($parts[0] ?? ''));
            $typeRaw = trim((string) ($parts[1] ?? ''));
            $title   = trim((string) ($parts[2] ?? ''));
            if ($subjRaw === '' || $typeRaw === '' || $title === '') {
                $errors[] = "第 {$rowNo} 行：缺少科目、题型或题干";
                continue;
            }

            $subjId = Subject::resolveId($subjRaw);
            if ($subjId <= 0 || $subject->find($subjId) === null) {
                $errors[] = "第 {$rowNo} 行：科目「{$subjRaw}」不存在";
                continue;
            }

            $type = self::resolveType($typeRaw);
            if ($type === '') {
                $errors[] = "第 {$rowNo} 行：无法识别的题型「{$typeRaw}」";
                continue;
            }

            $isOptionType = in_array($type, self::OPTION_TYPES, true);
            $option = self::normalizeImportOptions($type, (string) ($parts[3] ?? ''));
            if ($isOptionType && $option === '') {
                $errors[] = "第 {$rowNo} 行：选择题缺少选项";
                continue;
            }

            $key = $this->normalizeKey($type, self::normalizeImportKey($type, (string) ($parts[4] ?? '')));
            if ($isOptionType && $key === '') {
                $errors[] = "第 {$rowNo} 行：选择题缺少答案";
                continue;
            }

            try {
                $this->model->create([
                    'subj_id'       => $subjId,
                    'quiz_title'    => $title,
                    'quiz_class'    => $type,
                    'quiz_option'   => $option,
                    'quiz_key'      => $key,
                    'quiz_diff'     => self::resolveDiff((string) ($parts[5] ?? '')),
                    'quiz_pic_name' => '',
                    'quiz_writer'   => self::pickWriter($parts[6] ?? null, $defaultWriter),
                    'quiz_time'     => $today,
                    'quiz_kp'       => mb_substr(trim((string) ($parts[7] ?? '')), 0, 120),
                    'quiz_hits'     => 0,
                    'quiz_key_ok'   => 0,
                ]);
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

        $this->audit('quiz.import', 'quiz:batch', ['imported' => $imported, 'failed' => count($errors)]);
        return $this->ok([
            'imported' => $imported,
            'failed'   => count($errors),
            'errors'   => array_slice($errors, 0, 50),
        ], "成功导入 {$imported} 道试题" . (count($errors) > 0 ? '，' . count($errors) . ' 条失败' : ''));
    }

    /** GET /api/admin/quizzes/clean/preview?subj_id=N —— 重复题预览（不删除） */
    public function cleanPreview(): Response
    {
        $subjId = (int) $this->request->query('subj_id', 0);
        $groups = $this->model->duplicates($subjId > 0 ? $subjId : null);

        $removeTotal = 0;
        foreach ($groups as &$g) {
            $ids = array_map('intval', explode(',', (string) $g['ids']));
            $g['keep_id'] = (int) $g['keepId'];
            $g['remove_ids'] = array_values(array_filter($ids, static fn (int $i): bool => $i !== (int) $g['keepId']));
            $g['remove_count'] = count($g['remove_ids']);
            $removeTotal += $g['remove_count'];
        }
        unset($g);

        return $this->ok([
            'subj_id'      => $subjId,
            'groups'       => $groups,
            'group_count'  => count($groups),
            'remove_count' => $removeTotal,
        ]);
    }

    /**
     * POST /api/admin/quizzes/clean —— 自动清理重复题（科目+题干+选项）
     * body: {subj_id?:int}（不传则全库清理）
     */
    public function autoClean(): Response
    {
        $subjId = (int) $this->request->input('subj_id', 0);
        $result = $this->model->deleteDuplicates($subjId > 0 ? $subjId : null);
        $this->audit('quiz.clean', 'quiz:auto', ['subj_id' => $subjId ?: null, 'deleted' => $result['deleted'], 'groups' => $result['groups']]);

        return $this->ok($result, "自动清理完成，清理 {$result['groups']} 组重复，共删除 {$result['deleted']} 条试题");
    }

    /**
     * POST /api/admin/quizzes/advanced-clean —— 高级清理
     * 1) 去重（科目+题干+选项） 2) 答案转大写 3) 多选答案排序 4) 单选实为多选 → 转多选
     */
    public function doAdvancedClean(): Response
    {
        // 考试进行中禁止清理（沿用旧系统保护）。
        // 必须排除模拟考试：它同样以 exam_status='testing' 落库，考生开一场模拟考试
        // 不交卷就会让所有维护操作永久 409，而管理端没有任何入口能清理它。
        $mock = \App\Models\Exam::MOCK_CLASS;
        $running = Database::fetch(
            "SELECT id FROM `examinfo` WHERE exam_status = 'testing' AND COALESCE(exam_class, '') != ? LIMIT 1",
            [$mock]
        );
        if ($running !== null) {
            throw new HttpException(409, '当前有正在进行的考试，不能执行高级清理', 40903);
        }

        $dup = $this->model->deleteDuplicates(null);
        $upper = $this->model->upperCaseKeys();
        $sorted = $this->model->sortMultiKeys();
        $fixed = $this->model->fixSingleAsMulti();

        return $this->ok([
            'duplicate_removed' => $dup['deleted'],
            'duplicate_groups'  => $dup['groups'],
            'upper_cased'       => $upper,
            'multi_sorted'      => $sorted,
            'single_to_multi'   => $fixed,
        ], '高级清理完成');
    }

    /* ------------------------------------------------------------------ */

    /** 组装写入字段（按题型决定是否保留选项） */
    private function buildRow(array $in): array
    {
        $type = (string) $in['quiz_class'];
        $option = trim((string) ($in['quiz_option'] ?? ''));
        // 判断题/单选/多选必须有选项；填空题/问答题不存选项
        $hasOptions = in_array($type, self::OPTION_TYPES, true);

        return [
            'subj_id'       => (int) $in['subj_id'],
            'quiz_title'    => trim((string) $in['quiz_title']),
            'quiz_class'    => $type,
            'quiz_option'   => $hasOptions ? $option : '',
            'quiz_key'      => $this->normalizeKey($type, (string) ($in['quiz_key'] ?? '')),
            'quiz_diff'     => (string) $in['quiz_diff'],
            'quiz_pic_name' => trim((string) ($in['quiz_pic_name'] ?? '')),
            // 知识点：A3 组卷/学情分析的分组维度，列宽 VARCHAR(120)
            'quiz_kp'       => mb_substr(trim((string) ($in['quiz_kp'] ?? '')), 0, 120),
        ];
    }

    /** 保存前归一化答案：大写 + 多选题按字母排序（预防脏数据进入题库） */
    private function normalizeKey(string $type, string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        return Quiz::normalizeAnswer($type, $key);
    }

    /* ---------------- 批量导入辅助 ---------------- */

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

    /** 题型：中文名或代码 → 代码；无法识别返回 '' */
    private static function resolveType(string $raw): string
    {
        $raw = trim($raw);
        if (in_array($raw, Quiz::TYPES, true)) {
            return $raw;
        }
        $map = array_flip(Quiz::TYPE_LABELS);
        return (string) ($map[$raw] ?? '');
    }

    /**
     * 首行是否为表头。
     *
     * 只判断「行内出现 科目/题型 字样」会把真实数据行误判成表头并整行丢弃
     * （例如科目名叫「科目一」时，首行数据会被静默吞掉，用户只看到「导入 0 条」）。
     * 因此改为两条同时成立才算表头：
     *   ① 第 2 列（题型列）不是任何可识别的题型 —— 真实数据行这里必然是合法题型；
     *   ② 行内至少命中 2 个表头特征词。
     */
    private static function looksLikeHeader(array $parts): bool
    {
        if (self::resolveType((string) ($parts[1] ?? '')) !== '') {
            return false;
        }
        $line = mb_strtolower(implode(',', array_map(static fn ($p): string => (string) $p, $parts)));
        $hits = 0;
        foreach (['科目', '题型', '题干', '选项', '答案', '难度', 'subj', 'type', 'title', 'question', 'option', 'answer'] as $kw) {
            if (str_contains($line, $kw)) {
                $hits++;
                if ($hits >= 2) {
                    return true;
                }
            }
        }
        return false;
    }

    /** 难度：易/中/难 或 Y/Z/N → 代码；无法识别回落 Z（中） */
    private static function resolveDiff(string $raw): string
    {
        $raw = trim($raw);
        $up = strtoupper($raw);
        if (in_array($up, Quiz::DIFFS, true)) {
            return $up;
        }
        $map = ['易' => 'Y', '中' => 'Z', '难' => 'N', '简单' => 'Y', '普通' => 'Z', '困难' => 'N'];
        return $map[$raw] ?? 'Z';
    }

    /** 选项归一：选择题统一为 `|` 分隔；非选择题一律清空（填空/问答不存选项） */
    private static function normalizeImportOptions(string $type, string $raw): string
    {
        if (!in_array($type, self::OPTION_TYPES, true)) {
            return '';
        }
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // 兼容换行 / 半角分号 / 全角分号 分隔的选项，统一为旧库主用法 `|`
        $parts = preg_split('/\s*[\r\n;；]+\s*/u', $raw) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
        return implode('|', $parts);
    }

    /**
     * 答案归一（导入入口专用）：兼容判断题的 对/错、√/×、T/F 等口语写法。
     * 填空/问答题保留原文（不做字母映射）。
     */
    private static function normalizeImportKey(string $type, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || !in_array($type, self::OPTION_TYPES, true)) {
            return $raw;
        }
        $map = [
            '对' => 'A', '正确' => 'A', '是' => 'A', '√' => 'A', '✓' => 'A',
            '错' => 'B', '错误' => 'B', '否' => 'B', '×' => 'B', '✗' => 'B',
        ];
        if (isset($map[$raw])) {
            return $map[$raw];
        }
        $up = strtoupper($raw);
        $upper = ['T' => 'A', 'TRUE' => 'A', 'F' => 'B', 'FALSE' => 'B'];
        return $upper[$up] ?? $raw;
    }

    /** 录题人：表单值优先，留空回落到传入的默认值（通常是当前登录账号） */
    private static function pickWriter(mixed $input, string $fallback): string
    {
        $v = trim((string) ($input ?? ''));
        return $v !== '' ? mb_substr($v, 0, 50) : $fallback;
    }

    /**
     * 录题日期：仅接受 YYYY-MM-DD，否则回落到 $fallback（默认当天）。
     * quiz_time 是 date 列，非法值写入会触发 MySQL 报错或存成 0000-00-00。
     */
    private static function pickQuizDate(mixed $input, ?string $fallback = null): string
    {
        $fallback ??= date('Y-m-d');
        $v = trim((string) ($input ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : $fallback;
    }

    /** 解析 ids 参数（数组或逗号分隔字符串） */
    private function rawIds(string $field): array
    {
        $raw = $this->request->input($field, $this->request->query($field, []));
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $i): bool => $i > 0
        )));
    }

    private function assertFilters(array $filters): void
    {
        if ($filters['quiz_class'] !== '' && !in_array($filters['quiz_class'], Quiz::TYPES, true)) {
            throw new HttpException(400, '无效的题型参数', 40000);
        }
        if ($filters['quiz_diff'] !== '' && !in_array($filters['quiz_diff'], Quiz::DIFFS, true)) {
            throw new HttpException(400, '无效的难度参数', 40000);
        }
    }

    private function typeOptions(): array
    {
        $out = [];
        foreach (Quiz::TYPES as $t) {
            $out[] = ['value' => $t, 'label' => Quiz::TYPE_LABELS[$t]];
        }
        return $out;
    }

    private function diffOptions(): array
    {
        $out = [];
        foreach (Quiz::DIFFS as $d) {
            $out[] = ['value' => $d, 'label' => Quiz::DIFF_LABELS[$d]];
        }
        return $out;
    }
}
