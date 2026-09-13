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
        ]);

        $subjId = (int) $in['subj_id'];
        if ((new Subject())->find($subjId) === null) {
            throw new HttpException(400, '所选科目不存在', 40001);
        }

        $data = $this->buildRow($in);
        $data['quiz_writer'] = (string) ($sess['username'] ?? '');
        $data['quiz_time']   = date('Y-m-d');
        $data['quiz_hits']   = 0;
        $data['quiz_key_ok'] = 0;

        $id = $this->model->create($data);
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
        ]);

        $subjId = (int) $in['subj_id'];
        if ((new Subject())->find($subjId) === null) {
            throw new HttpException(400, '所选科目不存在', 40001);
        }

        $this->model->update($id, $this->buildRow($in));
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
        return $this->ok(['deleted' => $deleted], "批量删除成功，共删除 {$deleted} 条试题");
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

        return $this->ok($result, "自动清理完成，清理 {$result['groups']} 组重复，共删除 {$result['deleted']} 条试题");
    }

    /**
     * POST /api/admin/quizzes/advanced-clean —— 高级清理
     * 1) 去重（科目+题干+选项） 2) 答案转大写 3) 多选答案排序 4) 单选实为多选 → 转多选
     */
    public function doAdvancedClean(): Response
    {
        // 考试进行中禁止清理（沿用旧系统保护）
        $running = Database::fetch(
            "SELECT id FROM `examinfo` WHERE exam_status = 'testing' LIMIT 1"
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
