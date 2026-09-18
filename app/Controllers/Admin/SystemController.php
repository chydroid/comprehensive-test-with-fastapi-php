<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Audit;
use Core\Database;
use Core\HttpException;
use Core\Response;

/**
 * 系统管理（危险操作）
 * 权限点：system.manage（仅 systemAdmin）
 *
 * ⚠️ 这两个接口会物理删除数据，且不可恢复：
 *   initialize  → 清空 考场 + 答卷 + 成绩 + 考生
 *   clearExams  → 清空 考场 + 答卷 + 成绩（保留考生）
 *
 * 安全加固（相对旧系统）：
 * - 必须显式提供确认口令（body: {confirm:"INITIALIZE"} / {confirm:"CLEAR-EXAMS"}）
 * - 执行前统计将被删除的行数并随响应返回，操作可审计
 * - 禁止在有考试进行中（testing）时执行，避免破坏正在答题的考生
 */
class SystemController extends BaseController
{
    // 清库必须带上 stuscorebak：它通过 stu_id / exam_id 关联考试与考生，
    // 若不清理会留下孤儿备份行（而 bak 列表是 INNER JOIN examinfo，孤儿行不可见），
    // 表现为「数据消失但没删」—— 持续占空间且无法审计。
    private const TABLES_EXAM = ['examinfo', 'stupaper', 'stuscore', 'stuscorebak'];

    /** GET /api/admin/system —— 当前数据量 + 操作前置状态 */
    public function index(): Response
    {
        return $this->ok([
            'counts'       => $this->counts(),
            'exam_running' => $this->runningExamCount(),
            'confirm_words'=> [
                'initialize'  => 'INITIALIZE',
                'clear_exams' => 'CLEAR-EXAMS',
            ],
        ]);
    }

    /** POST /api/admin/system/initialize —— 完全初始化（含考生） */
    public function initialize(): Response
    {
        $this->guard('INITIALIZE');

        $tables = array_merge(self::TABLES_EXAM, ['stuinfo']);
        $deleted = $this->truncateAll($tables);

        $this->audit('system.initialize', 'system', ['deleted' => $deleted]);
        return $this->ok(['deleted' => $deleted], '系统初始化完成：已清空考场、答卷、成绩与考生信息');
    }

    /** POST /api/admin/system/clear-exams —— 仅清空考试相关数据 */
    public function clearExams(): Response
    {
        $this->guard('CLEAR-EXAMS');

        $deleted = $this->truncateAll(self::TABLES_EXAM);

        $this->audit('system.clear-exams', 'system', ['deleted' => $deleted]);
        return $this->ok(['deleted' => $deleted], '考试信息清除完成：已清空考场、答卷与成绩，考生信息已保留');
    }

    /* ------------------------------------------------------------------ */

    /** 确认口令 + 考试进行中检查 */
    private function guard(string $word): void
    {
        $confirm = strtoupper(trim((string) $this->request->input('confirm', '')));
        if ($confirm !== $word) {
            throw new HttpException(
                400,
                "该操作不可恢复，请在确认框中输入 {$word} 后再提交",
                40000
            );
        }

        $running = $this->runningExamCount();
        if ($running > 0) {
            throw new HttpException(409, "当前有 {$running} 场考试正在进行，禁止执行此操作", 40901);
        }
    }

    /** 事务内清空多张表，返回各表删除行数 */
    private function truncateAll(array $tables): array
    {
        $deleted = [];
        Database::beginTransaction();
        try {
            foreach ($tables as $table) {
                $before = $this->count($table);
                Database::query("DELETE FROM `{$table}`");
                $deleted[$table] = $before;
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
        return $deleted;
    }

    private function counts(): array
    {
        $tables = array_merge(self::TABLES_EXAM, ['stuinfo', 'quizlib', 'subject']);
        $out = [];
        foreach ($tables as $t) {
            $out[$t] = $this->count($t);
        }
        return $out;
    }

    private function count(string $table): int
    {
        return (int) (Database::fetch("SELECT COUNT(*) AS c FROM `{$table}`")['c'] ?? 0);
    }

    /**
     * 进行中的**正式**考试数。
     * 必须排除模拟考试（exam_class='模拟考试'）：它同样以 exam_status='testing'
     * 落库，若不排除，考生开一场模拟考试就会永久阻塞系统初始化 / 清空考试。
     */
    private function runningExamCount(): int
    {
        return (int) (Database::fetch(
            "SELECT COUNT(*) AS c FROM `examinfo`
             WHERE exam_status = 'testing' AND COALESCE(exam_class, '') != ?",
            [\App\Models\Exam::MOCK_CLASS]
        )['c'] ?? 0);
    }

    /**
     * GET /api/admin/logs —— 审计日志查询（超级管理员可见）
     * 支持过滤：action(前缀) / actor_type / actor_id / keyword / since / until / limit
     */
    public function logs(): Response
    {
        $this->authAdmin();

        $limit = min(200, max(1, (int) $this->request->query('limit', 50)));
        $filters = [
            'action'     => trim((string) $this->request->query('action', '')),
            'actor_type' => trim((string) $this->request->query('actor_type', '')),
            'actor_id'   => trim((string) $this->request->query('actor_id', '')),
            'keyword'    => trim((string) $this->request->query('keyword', '')),
            'since'      => trim((string) $this->request->query('since', '')),
            'until'      => trim((string) $this->request->query('until', '')),
        ];
        $rows = Audit::recent($limit, array_filter($filters, static fn ($v) => $v !== ''));

        foreach ($rows as &$r) {
            if (!empty($r['detail'])) {
                $decoded = json_decode((string) $r['detail'], true);
                $r['detail'] = is_array($decoded) ? $decoded : [];
            } else {
                $r['detail'] = [];
            }
        }
        unset($r);

        return $this->ok(['list' => $rows, 'total' => count($rows)]);
    }
}
