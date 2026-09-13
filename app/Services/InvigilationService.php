<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use App\Models\StuScore;
use App\Models\StuScoreBak;
use Core\Database;

/**
 * 监考与成绩服务
 *
 * 管理端（Admin\MonitorController / Admin\ScoreController）与教师端
 * （TeacherMonitorController / TeacherExamController）功能几乎一致，
 * 差异仅在权限点与可见范围。为避免旧系统「两端各写一份、行为逐渐分叉」的问题，
 * 此处的业务逻辑只保留一份实现。
 */
final class InvigilationService
{
    private StuScore $scores;
    private StuScoreBak $backups;
    private Exam $exams;

    public function __construct()
    {
        $this->scores  = new StuScore();
        $this->backups = new StuScoreBak();
        $this->exams   = new Exam();
    }

    /** 考场考生名单（含进度统计） */
    public function roster(int $examId, string $orderBy = 'stuid', string $order = 'asc'): array
    {
        return [
            'exam'    => $this->exams->detail($examId),
            'summary' => $this->exams->statusSummary($examId),
            'list'    => $this->scores->byExam($examId, $orderBy, $order),
        ];
    }

    /** 锁定单个考生 */
    public function lock(int $examId, string $stuId): int
    {
        return $this->scores->updateStatus($examId, $stuId, 'locked');
    }

    /** 解锁单个考生 */
    public function unlock(int $examId, string $stuId): int
    {
        return $this->scores->updateStatus($examId, $stuId, 'online');
    }

    /** 锁定全部（跳过已交卷的） */
    public function lockAll(int $examId): int
    {
        return $this->scores->lockAll($examId);
    }

    /** 解锁全部（仅锁定态） */
    public function unlockAll(int $examId): int
    {
        return $this->scores->unlockAll($examId);
    }

    /**
     * 为全场未交卷考生强制交卷并判分（考试继续）。
     * @return array{graded:int, skipped:int, total:int}
     */
    public function submitAll(int $examId): array
    {
        $rows = Database::fetchAll('SELECT stu_id, stu_status FROM `stuscore` WHERE exam_id = ?', [$examId]);
        $graded = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            if (str_starts_with((string) ($r['stu_status'] ?? ''), 'over')) {
                $skipped++;
                continue;
            }
            ExamEngine::autoGrade($examId, (string) $r['stu_id']);
            $graded++;
        }
        return ['graded' => $graded, 'skipped' => $skipped, 'total' => count($rows)];
    }

    /** 结束整场考试（强制判分 + 状态流转 over） */
    public function endAll(int $examId): array
    {
        return ExamEngine::endExam($examId);
    }

    /** 某场考试成绩名单（成绩模块） */
    public function scoreList(int $examId, string $orderBy = 'stuid', string $order = 'asc'): array
    {
        return [
            'exam' => $this->exams->detail($examId),
            'list' => $this->scores->byExam($examId, $orderBy, $order),
        ];
    }

    /** 备份成绩（幂等：已备份则拒绝） */
    public function backup(int $examId): int
    {
        if ($this->scores->isBackedUp($examId)) {
            throw new \RuntimeException('此场考试成绩已经备份过了');
        }
        return $this->scores->backup($examId);
    }

    /** 备份记录列表 */
    public function backupList(): array
    {
        return $this->backups->listWithExam();
    }

    /**
     * 生成成绩 CSV 内容（带 BOM，Excel 可直接识别 UTF-8）。
     * 返回字符串而非直接输出，便于 CLI 测试与统一响应处理。
     */
    public function csv(int $examId, bool $withPwd = true): string
    {
        $exam = $this->exams->find($examId);
        if ($exam === null) {
            throw new \RuntimeException('考试不存在');
        }
        $list = $this->scores->byExam($examId, 'stuid', 'asc');

        $statusMap = [
            'waiting' => '等待', 'online' => '在线', 'locked' => '锁定', 'over' => '已交卷',
        ];

        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            throw new \RuntimeException('无法创建导出缓冲');
        }

        fwrite($buffer, "\xEF\xBB\xBF");
        $header = ['准考证号', '姓名', '性别', '单位', '班级', '成绩', '状态'];
        if ($withPwd) {
            $header[] = '考场口令';
        }
        fputcsv($buffer, $header);

        foreach ($list as $row) {
            $status = (string) ($row['stu_status'] ?? '');
            $baseStatus = explode(':', $status)[0];
            $line = [
                (string) ($row['stu_id'] ?? ''),
                (string) ($row['stu_name'] ?? ''),
                (string) ($row['stu_sex'] ?? ''),
                (string) ($row['grade_id'] ?? ''),
                (string) ($row['class_id'] ?? ''),
                (int) ($row['stu_score'] ?? 0),
                $statusMap[$baseStatus] ?? $status,
            ];
            if ($withPwd) {
                $line[] = (string) ($row['stu_pwd'] ?? '');
            }
            fputcsv($buffer, $line);
        }

        rewind($buffer);
        $content = stream_get_contents($buffer);
        fclose($buffer);

        return $content === false ? '' : $content;
    }

    /** 成绩 CSV 文件名 */
    public function csvFilename(int $examId): string
    {
        $exam = $this->exams->find($examId);
        $name = (string) ($exam['exam_name'] ?? ('exam_' . $examId));
        // 文件名安全化：去掉路径分隔符与非法字符
        $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $name) ?? $name;
        return 'scores_' . $examId . '_' . trim($name) . '.csv';
    }
}
