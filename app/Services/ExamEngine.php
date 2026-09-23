<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use App\Models\Quiz;
use App\Services\Setting;
use App\Services\WrongBook;
use Core\Database;

/**
 * 考试引擎：组卷、作答保存、自动判分。
 *
 * 从旧系统的 Paper / Exam / Question 三个模型抽取而成，行为保持一致但修正了以下缺陷：
 *
 * 1. **组卷可重入**：已有试卷时直接返回，不会重复排卷（旧系统同样如此，此处显式保留）。
 * 2. **组卷缺题可见**：旧系统若某题型题库不足，静默产生"少题"试卷，考生与教师都不知情。
 *    本实现返回 warnings，由调用方决定是否阻断。
 * 3. **判分归一化统一**：判断题 Y/N→A/B 的兼容逻辑收敛到 Quiz::normalizeAnswer，
 *    避免旧系统在 review() 中忘记归一化导致的对照答案误判。
 * 4. **随机抽题**：使用 ORDER BY RAND()（与旧系统一致）。题量千级时性能可接受；
 *    后续可换为「预生成随机 id 列表 + 主键 IN」以进一步优化。
 */
final class ExamEngine
{
    /** 抽题顺序与难度映射（与旧系统一致） */
    private const DIFF_ORDER = ['easy' => 'Y', 'mid' => 'Z', 'hard' => 'N'];

    /**
     * 为考生组卷。
     *
     * @return array{generated:bool, warnings:array<int,array{type:string,diff:string,need:int,have:int}>}
     */
    public static function generatePaper(int $examId, string $stuId): array
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            return ['generated' => false, 'warnings' => []];
        }

        // 已有试卷 → 幂等返回，不重复排卷。
        // 注意：存在性检查必须与插入处于同一事务内。此前检查在事务之外，
        // 两个并发请求会双双读到 c=0，各插一整套题，且 paper_id 都从 1 开始，
        // 造成题量翻倍、saveAnswer 串写、autoGrade 重复计分（总分可超满分）。
        $warnings = [];

        Database::beginTransaction();
        try {
            // 对考试行加排他锁，串行化同一场考试的并发组卷
            Database::fetch('SELECT id FROM `examinfo` WHERE id = ? FOR UPDATE', [$examId]);

            $existing = Database::fetch(
                'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ? AND stu_id = ?',
                [$examId, $stuId]
            );
            if ((int) ($existing['c'] ?? 0) > 0) {
                Database::commit();
                return ['generated' => false, 'warnings' => []];
            }

            // 确保成绩记录存在（考场口令/状态管理依赖它）
            $hasScore = Database::fetch(
                'SELECT id FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
                [$examId, $stuId]
            );
            if ($hasScore === null) {
                self::createScore($examId, $stuId, '');
            }

            $paperId = 1;
            $subjId = (int) ($exam['subj_id'] ?? 0);
            $mode = (string) ($exam['paper_mode'] ?? 'random');

            if ($mode === 'manual') {
                // 手动选题：使用考试绑定的具体题目（exam_manual_quiz），所有考生同卷
                $rows = Database::fetchAll(
                    'SELECT m.quiz_id, q.quiz_class
                     FROM `exam_manual_quiz` m INNER JOIN `quizlib` q ON q.id = m.quiz_id
                     WHERE m.exam_id = ? ORDER BY m.sort, m.quiz_id',
                    [$examId]
                );
                foreach ($rows as $q) {
                    self::insertPaperRow($examId, $stuId, $paperId, (int) $q['quiz_id'], (string) $q['quiz_class'], self::shuffledOrder((string) $q['quiz_class'], (int) $q['quiz_id']));
                    $paperId++;
                }
                if ($rows === []) {
                    $warnings[] = ['type' => 'manual', 'diff' => '', 'need' => 1, 'have' => 0];
                }
            } elseif ($mode === 'by_kp') {
                // 按知识点比例：每个知识点按配置数量随机抽题（可限定难度）
                $plan = Database::fetchAll(
                    'SELECT kp, diff, cnt FROM `exam_kp_plan` WHERE exam_id = ? ORDER BY sort, kp, diff',
                    [$examId]
                );
                foreach ($plan as $p) {
                    $cnt = (int) $p['cnt'];
                    if ($cnt <= 0) {
                        continue;
                    }
                    $qs = self::drawQuestionsByKp($subjId, (string) $p['kp'], (string) $p['diff'], $cnt);
                    if (count($qs) < $cnt) {
                        $warnings[] = [
                            'type' => (string) $p['kp'],
                            'diff' => (string) $p['diff'],
                            'need' => $cnt,
                            'have' => count($qs),
                        ];
                    }
                    foreach ($qs as $q) {
                        self::insertPaperRow($examId, $stuId, $paperId, (int) $q['id'], (string) $q['quiz_class'], self::shuffledOrder((string) $q['quiz_class'], (int) $q['id']));
                        $paperId++;
                    }
                }
            } else {
                // random：按题型 × 难度计数列随机抽题（原有行为）
                foreach (Exam::TYPE_PREFIXES as $type) {
                    foreach (self::DIFF_ORDER as $field => $diffCode) {
                        $need = (int) ($exam["{$type}_{$field}_sum"] ?? 0);
                        if ($need <= 0) {
                            continue;
                        }
                        $questions = self::drawQuestions($subjId, $type, $diffCode, $need);
                        if (count($questions) < $need) {
                            // 缺题：记录但不中断，保证已抽到的题仍可用（行为与旧系统一致，但不再静默）
                            $warnings[] = [
                                'type' => $type,
                                'diff' => $diffCode,
                                'need' => $need,
                                'have' => count($questions),
                            ];
                        }
                        foreach ($questions as $q) {
                            self::insertPaperRow($examId, $stuId, $paperId, (int) $q['id'], $type, self::shuffledOrder($type, (int) $q['id']));
                            $paperId++;
                        }
                    }
                }
            }

            // 校正满分：按实际卷面题目题型 × 每题分值求和；
            // 仅当 exam_score 未设置时回填（by_kp 首次出题时才能确定真实分值）。
            // 这保证 autoGrade 的总分封顶永远基于真实卷面，不会因 by_kp 难度分布不同而误封顶。
            $paperScore = self::scoreOfPaper($examId, $stuId);
            if ($paperScore > 0) {
                Database::query(
                    'UPDATE `examinfo` SET exam_score = ? WHERE id = ? AND (exam_score IS NULL OR exam_score = 0)',
                    [$paperScore, $examId]
                );
            }

            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return ['generated' => true, 'warnings' => $warnings];
    }

    /** 插入一条试卷题目（stupaper），optionOrder 为选项乱序展示序列（字母序列，可为空） */
    private static function insertPaperRow(int $examId, string $stuId, int $paperId, int $quizId, string $quizClass, string $optionOrder = ''): void
    {
        Database::query(
            'INSERT INTO `stupaper` (exam_id, stu_id, paper_id, quiz_id, quiz_class, stu_key, quiz_status, option_order)
             VALUES (?, ?, ?, ?, ?, \'\', 0, ?)',
            [$examId, $stuId, $paperId, $quizId, $quizClass, $optionOrder]
        );
    }

    /** 选项键缓存（按 quiz_id），避免同一场考试多考生重复查库 */
    private static array $optionKeyCache = [];

    /** 读取某题的选项键序列 [A,B,...]（带请求内缓存） */
    private static function optionKeys(int $quizId): array
    {
        if (!isset(self::$optionKeyCache[$quizId])) {
            $row = Database::fetch('SELECT quiz_option FROM `quizlib` WHERE id = ?', [$quizId]);
            $list = Quiz::parseOptions((string) ($row['quiz_option'] ?? ''));
            self::$optionKeyCache[$quizId] = array_map(static fn ($o) => (string) $o['key'], $list);
        }
        return self::$optionKeyCache[$quizId];
    }

    /**
     * 计算选项乱序序列（仅单选/多选，且仅当启用防作弊时）。
     * 返回字母序列（如 "CABD"），前端据此重排选项展示顺序；
     * 由于答案键仍是原始字母，判分不受影响。
     */
    private static function shuffledOrder(string $quizClass, int $quizId): string
    {
        if (!Setting::bool('enable_cheat_guard')) {
            return '';
        }
        if (!in_array($quizClass, ['radio2', 'checkbox'], true)) {
            return ''; // 判断题/填空/问答不参与乱序
        }
        $keys = self::optionKeys($quizId);
        if (count($keys) < 2) {
            return '';
        }
        $k = $keys;
        shuffle($k);
        return implode('', $k);
    }

    /**
     * 每题分值映射 [quiz_class => val]，由 examinfo 的 <type>_val 列驱动。
     * 收敛在一处，日后新增题型只改 Exam::TYPE_PREFIXES 即可，
     * 不会再出现「某处漏改导致该题型恒 0 分」的静默错分。
     */
    private static function valueMap(array $exam): array
    {
        $map = [];
        foreach (Exam::TYPE_PREFIXES as $t) {
            $map[$t] = (int) ($exam["{$t}_val"] ?? 0);
        }
        return $map;
    }

    /** 计算某考生某场试卷的实际分值（按卷面题型 × examinfo 每题分值） */
    private static function scoreOfPaper(int $examId, string $stuId): int
    {
        static $valCache = [];
        if (!isset($valCache[$examId])) {
            $valCache[$examId] = self::valueMap((new Exam())->find($examId) ?? []);
        }
        $valMap = $valCache[$examId];
        $rows = Database::fetchAll(
            'SELECT q.quiz_class FROM `stupaper` sp INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ?',
            [$examId, $stuId]
        );
        $score = 0;
        foreach ($rows as $r) {
            $score += $valMap[(string) $r['quiz_class']] ?? 0;
        }
        return $score;
    }

    /** 随机抽题：按科目 + 题型 + 难度 */
    private static function drawQuestions(int $subjId, string $type, string $diffCode, int $limit): array
    {
        $limit = max(1, $limit);
        return Database::fetchAll(
            'SELECT id FROM `quizlib`
             WHERE subj_id = ? AND quiz_class = ? AND quiz_diff = ?
             ORDER BY RAND() LIMIT ' . $limit,
            [$subjId, $type, $diffCode]
        );
    }

    /** 按知识点(章节)随机抽题：可限定难度（diff 为空表示不限） */
    private static function drawQuestionsByKp(int $subjId, string $kp, string $diff, int $limit): array
    {
        $limit = max(1, $limit);
        $sql = 'SELECT id, quiz_class FROM `quizlib` WHERE subj_id = ? AND quiz_kp = ?';
        $params = [$subjId, $kp];
        if ($diff !== '' && $diff !== null) {
            $sql .= ' AND quiz_diff = ?';
            $params[] = $diff;
        }
        $sql .= ' ORDER BY RAND() LIMIT ' . $limit;
        return Database::fetchAll($sql, $params);
    }

    /**
     * 出题：为本场「已进入考场」的考生批量生成随机试卷，并把考试状态推进为「已排卷」。
     * 供监考/管理员「出题」动作调用（管理端与教师端共用一份实现）。
     *
     * 只给已进入考场的考生出卷（stu_status ∈ {online, locked}）：
     *   - 考生入场（凭口令进入）后才被标记为 online，未入场的考生不分配试卷；
     *   - 若整个考场无人入场（entered = 0），由控制器提示「无考生无须出卷」。
     * 这样契合入场规则：考试前 10 分钟可入场、开考后不可入场；出题发生在入场窗口
     * 之后/开考前，此时仍在场外的考生按规则本就不应再入场，也不需要试卷。
     *
     * 状态推进内聚在此处，避免各控制器各写一份而逐渐分叉。
     *
     * @param array $exam examinfo 行（用于取 stu_class 与组卷参数）
     * @return array{students:int, entered:int, generated:int, skipped:int, warnings:array<int,array{type:string,diff:string,need:int,have:int}>}
     */
    public static function generateForClass(int $examId, array $exam): array
    {
        // 只给已进入考场的考生出卷：stu_status ∈ {online, locked}。
        // 名单来源（班级/补考名单）仅用于「有无参考对象」的防守校验（见控制器），
        // 真正的出卷范围由本方法按「实际入场状态」决定。
        $entered = self::enteredStudentIds($examId);

        $generated = 0;
        $skipped = 0;
        $warnings = [];
        foreach ($entered as $stuId) {
            $r = self::generatePaper($examId, (string) $stuId);
            if ($r['generated']) {
                $generated++;
            } else {
                $skipped++;
            }
            foreach ($r['warnings'] as $w) {
                $warnings[$w['type'] . '_' . $w['diff']] = $w;
            }
        }

        // 只要该考试已存在试卷就视为「已出题」，推进为「已排卷」。
        // 此前条件为 $generated > 0：若考生此前已排过卷（本次全部 skipped），
        // 状态会停在 exam，而惰性开考 autoStartIfDue() 只在 paper 状态才推进，
        // 结果是「编辑过一次的考试到点也不会自动开考」，只能手动启动。
        $hasPaper = (int) (Database::fetch(
            'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ?',
            [$examId]
        )['c'] ?? 0);
        if ($hasPaper > 0 && (string) ($exam['exam_status'] ?? '') === Exam::STATUS_EXAM) {
            Database::query(
                "UPDATE `examinfo` SET exam_status = 'paper' WHERE id = ? AND exam_status = 'exam'",
                [$examId]
            );
        }

        return [
            'students'  => count($entered),
            'entered'   => count($entered),
            'generated' => $generated,
            'skipped'   => $skipped,
            'warnings'  => array_values($warnings),
        ];
    }

    /**
     * 标记本场「监考已就绪」：即便此刻无人入场，也把 exam → paper。
     *
     * 为什么必须推进：惰性开考 autoStartIfDue() 只在 paper 状态推进。出题时若因无人
     * 入场而不动状态，考试会停在 exam —— 此后入场的考生既拿不到预生成试卷，
     * 整场也无法到点自动开考，只能永久卡在「未开考」。此时试卷由
     * ExamController::paper() 的惰性补卷兜底生成。
     *
     * @param int $examId
     */
    public static function markReady(int $examId): void
    {
        Database::query(
            "UPDATE `examinfo` SET exam_status = 'paper' WHERE id = ? AND exam_status = 'exam'",
            [$examId]
        );
    }

    /**
     * 已进入考场的考生（stu_status ∈ {online, locked}）—— 出题的唯一范围。
     * @return string[] 准考证号列表
     */
    private static function enteredStudentIds(int $examId): array
    {
        $rows = Database::fetchAll(
            "SELECT stu_id FROM `stuscore` WHERE exam_id = ? AND stu_status IN ('online', 'locked')",
            [$examId]
        );
        return array_values(array_map(static fn (array $r): string => (string) $r['stu_id'], $rows));
    }

    /**
     * 创建模拟考试记录（exam_class = '模拟考试'，exam_status = 'testing'）。
     * 与正式考试的区分完全依赖 exam_class，因此监控/成绩等模块可据此排除模拟考试。
     */
    public static function createPracticeExam(array $config): int
    {
        Database::query(
            "INSERT INTO `examinfo`
                (exam_name, subj_id, exam_start, exam_end, exam_tea, stu_class, exam_status, exam_class)
             VALUES (?, ?, ?, ?, '', '', 'testing', ?)",
            [
                (string) ($config['exam_name'] ?? '模拟考试'),
                (int) ($config['subj_id'] ?? 0),
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', time() + 3600),
                '模拟考试',
            ]
        );
        return Database::lastInsertId();
    }

    /**
     * 结束整场考试：为未交卷考生自动判分，并把考试状态置为 over。
     * @return array{graded:int, total:int}
     */
    public static function endExam(int $examId): array
    {
        $rows = Database::fetchAll(
            "SELECT stu_id, stu_status FROM `stuscore` WHERE exam_id = ?",
            [$examId]
        );
        $graded = 0;
        // 全员判分与状态推进必须原子：此前 UPDATE examinfo 在循环之后且无事务，
        // 中途异常会留下「前 N 人已判分、其余仍是 online、考试却还显示进行中」的中间态。
        Database::beginTransaction();
        try {
            foreach ($rows as $r) {
                // 已交卷（over 前缀）的跳过，避免重复判分
                if (str_starts_with((string) ($r['stu_status'] ?? ''), 'over')) {
                    continue;
                }
                self::autoGrade($examId, (string) $r['stu_id']);
                $graded++;
            }
            Database::query(
                "UPDATE `examinfo` SET exam_status = 'over' WHERE id = ? AND exam_status <> 'over'",
                [$examId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
        return ['graded' => $graded, 'total' => count($rows)];
    }

    /** 创建成绩记录（stu_pwd 为考场口令快照） */
    public static function createScore(int $examId, string $stuId, string $examPwd): void
    {
        Database::query(
            "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
             VALUES (?, ?, 0, 'waiting', ?)",
            [$examId, $stuId, $examPwd]
        );
    }

    /**
     * 自动判分（客观题）。返回本次得分。
     * longtext（问答题）不计分，留待人工批阅。
     */
    public static function autoGrade(int $examId, string $stuId): int
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            return 0;
        }
        // 每题分值随题型自动扩展（A4 起含 longtext 问答题）
        $valMap = self::valueMap($exam);

        $rows = Database::fetchAll(
            'SELECT sp.paper_id, sp.quiz_id, sp.quiz_class, sp.stu_key, q.quiz_key
             FROM `stupaper` sp
             INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ?
             ORDER BY sp.paper_id ASC',
            [$examId, $stuId]
        );

        $score = 0;
        $paperFull = 0;
        $wrong = []; // 本次答错的题目（quiz_id + paper_id），用于沉淀错题本
        Database::beginTransaction();
        try {
            // 一次性标记全部已批阅（此前循环内逐条 UPDATE，百题试卷会产生上百次往返
            // 并长时间持锁，也与 saveAnswer 的加锁顺序不一致，存在死锁风险）
            Database::query(
                'UPDATE `stupaper` SET quiz_status = 1 WHERE exam_id = ? AND stu_id = ?',
                [$examId, $stuId]
            );

            foreach ($rows as $r) {
                $type = (string) $r['quiz_class'];
                // 卷面满分按该生实际题目求和：by_kp 模式下不同学生的卷面满分可能不同
                // （知识点组合不同），不能用全局 exam_score 统一封顶。
                $paperFull += $valMap[$type] ?? 0;
                if ($type === 'longtext') {
                    continue; // 问答题不自动判分
                }
                $answer = (string) ($r['stu_key'] ?? '');
                $correct = (string) ($r['quiz_key'] ?? '');
                if ($answer === '' || $correct === '') {
                    continue;
                }
                if (Quiz::isCorrect($type, $correct, $answer)) {
                    $score += $valMap[$type] ?? 0;
                } else {
                    // 答错：收集进错题本（旁路，绝不打断判分）
                    $wrong[] = ['quiz_id' => (int) $r['quiz_id'], 'paper_id' => (int) $r['paper_id']];
                }
            }

            // 总分封顶：若因异常（如并发重复排卷）出现重复计分行，避免成绩超过试卷满分。
            // by_kp 模式下不同学生的卷面满分可能不同，用全局 exam_score 统一会把
            // 卷面更高的学生错误压低到第一人的满分（exam_score 只按第一人回填）。
            $cap = $paperFull > 0 ? $paperFull : (int) ($exam['exam_score'] ?? 0);
            if ($cap > 0 && $score > $cap) {
                $score = $cap;
            }

            // 幂等门禁：已交卷的记录不再覆盖。此前无条件 UPDATE，手动交卷与超时
            // 自动交卷并发时会互相覆盖，且事后无法区分哪次才是真实交卷。
            $affected = Database::query(
                "UPDATE `stuscore` SET stu_score = ?, stu_status = ?
                 WHERE exam_id = ? AND stu_id = ? AND LEFT(stu_status, 4) != 'over'",
                [$score, 'over', $examId, $stuId]
            )->rowCount();

            if ($affected === 0) {
                // 已判过分：直接返回库中既有成绩，保证重复交卷幂等
                Database::commit();
                $existing = Database::fetch(
                    'SELECT stu_score FROM `stuscore` WHERE exam_id = ? AND stu_id = ?',
                    [$examId, $stuId]
                );
                return (int) ($existing['stu_score'] ?? $score);
            }

            // 仅在「本次真正落分」时沉淀错题本（幂等门禁已挡住重复交卷）
            WrongBook::collectMany($stuId, $wrong, 'formal', $examId);

            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $score;
    }

    /**
     * 只读：某考生当前的分值明细（客观题自动判分 + 主观题已批阅得分）。
     * 不改库，供教师端展示「客观题 X 分 + 主观题 Y 分（还有 N 题待批）」。
     *
     * @return array{score:int, objective:int, subjective:int, pending:int, full:int}
     */
    public static function scoreBreakdown(int $examId, string $stuId): array
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            return ['score' => 0, 'objective' => 0, 'subjective' => 0, 'pending' => 0, 'full' => 0];
        }
        $valMap = self::valueMap($exam);

        $rows = Database::fetchAll(
            'SELECT sp.quiz_class, sp.stu_key, sp.quiz_score, q.quiz_key
             FROM `stupaper` sp
             INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ?
             ORDER BY sp.paper_id ASC',
            [$examId, $stuId]
        );

        $objective = 0;
        $subjective = 0;
        $pending = 0;
        $paperFull = 0;
        foreach ($rows as $r) {
            $type = (string) $r['quiz_class'];
            // 卷面满分按该生实际题目求和：by_kp 模式下不同学生卷面满分可能不同
            $paperFull += $valMap[$type] ?? 0;
            if (in_array($type, Exam::SUBJECTIVE_TYPES, true)) {
                if ($r['quiz_score'] === null) {
                    $pending++;          // 尚未批阅：不计分，也不当作答错（不拉低正确率口径）
                    continue;
                }
                $subjective += (int) $r['quiz_score'];
                continue;
            }
            $answer = (string) ($r['stu_key'] ?? '');
            $correct = (string) ($r['quiz_key'] ?? '');
            if ($answer === '' || $correct === '') {
                continue;
            }
            if (Quiz::isCorrect($type, $correct, $answer)) {
                $objective += $valMap[$type] ?? 0;
            }
        }

        // 卷面满分按该生实际题目求和：by_kp 模式下不同学生的卷面满分可能不同，
        // 用全局 exam_score 统一会把卷面更高的学生压低到第一人的满分。
        $full = $paperFull > 0 ? $paperFull : (int) ($exam['exam_score'] ?? 0);
        $score = $objective + $subjective;
        // 封顶：批阅不会让总分超过卷面满分（教师误给高分时兜底）
        if ($full > 0 && $score > $full) {
            $score = $full;
        }

        return [
            'score'      => $score,
            'objective'  => $objective,
            'subjective' => $subjective,
            'pending'    => $pending,
            'full'       => $full,
        ];
    }

    /**
     * 重算并写回某考生的总分：客观题（自动判分）+ 主观题（人工批阅得分）。
     *
     * 与 autoGrade 的关键差异 —— 这里**刻意不加幂等门禁**：
     * autoGrade 在交卷那一刻只跑一次（重复交卷靠 LEFT(stu_status,4)!='over' 挡住），
     * 而人工批阅必然发生在交卷之后，此时成绩已是 over。若沿用同一门禁，
     * 批阅给分将永远写不进去。因此本方法每次调用都按「当前卷面应得总分」重写，
     * 天然幂等（同样的批阅结果调用多次得到同一个分数）。
     *
     * @return array{score:int, objective:int, subjective:int, pending:int, full:int}
     */
    public static function recomputeScore(int $examId, string $stuId): array
    {
        $b = self::scoreBreakdown($examId, $stuId);
        Database::query(
            'UPDATE `stuscore` SET stu_score = ? WHERE exam_id = ? AND stu_id = ?',
            [$b['score'], $examId, $stuId]
        );
        return $b;
    }

    /**
     * 答卷列表 + 题目详情（含正确答案），供「查看答案」与教师批阅使用。
     * 注意：**此方法会返回 quiz_key**，仅可用于交卷后或管理端。
     */
    public static function paperWithAnswers(int $examId, string $stuId): array
    {
        $rows = Database::fetchAll(
            'SELECT sp.paper_id, sp.quiz_id, sp.quiz_class, sp.stu_key, sp.quiz_status,
                    sp.quiz_score, sp.quiz_comment, sp.grader_name, sp.graded_at,
                    q.quiz_title, q.quiz_option, q.quiz_key, q.quiz_pic_name, q.quiz_diff
             FROM `stupaper` sp
             INNER JOIN `quizlib` q ON q.id = sp.quiz_id
             WHERE sp.exam_id = ? AND sp.stu_id = ?
             ORDER BY sp.paper_id ASC',
            [$examId, $stuId]
        );

        foreach ($rows as &$r) {
            $type = (string) $r['quiz_class'];
            $r['quiz_type_label'] = Quiz::TYPE_LABELS[$type] ?? '未知';
            $r['quiz_diff_label'] = Quiz::DIFF_LABELS[$r['quiz_diff']] ?? '';
            $r['quiz_option_list'] = Quiz::parseOptions((string) ($r['quiz_option'] ?? ''));
            $r['is_subjective'] = in_array($type, Exam::SUBJECTIVE_TYPES, true);
            // 主观题无「对错」，只有「已批阅/未批阅」；quiz_score 为 NULL 即尚未批阅
            $r['graded'] = $r['quiz_score'] !== null;
            if ($r['is_subjective']) {
                $r['quiz_score'] = $r['quiz_score'] === null ? null : (int) $r['quiz_score'];
            }
            $r['is_correct'] = $type === 'longtext'
                ? null
                : Quiz::isCorrect($type, (string) ($r['quiz_key'] ?? ''), (string) ($r['stu_key'] ?? ''));
        }
        unset($r);

        return $rows;
    }

    /**
     * 组卷结构（答题卡导航）：按题型分组，含每题作答状态。
     * 不下发正确答案，可安全用于考试进行中。
     */
    public static function navigation(int $examId, string $stuId): array
    {
        $rows = Database::fetchAll(
            'SELECT paper_id, quiz_class, quiz_status
             FROM `stupaper`
             WHERE exam_id = ? AND stu_id = ?
             ORDER BY paper_id ASC',
            [$examId, $stuId]
        );

        $groups = [];
        $done = 0;
        foreach ($rows as $r) {
            $type = (string) $r['quiz_class'];
            $answered = (int) $r['quiz_status'] !== 0;
            if ($answered) {
                $done++;
            }
            $groups[$type][] = [
                'paper_id' => (int) $r['paper_id'],
                'answered' => $answered,
            ];
        }

        return [
            'total'  => count($rows),
            'done'   => $done,
            'groups' => array_map(
                static fn (string $type, array $items): array => [
                    'type'       => $type,
                    'type_label' => Quiz::TYPE_LABELS[$type] ?? '未知',
                    'items'      => $items,
                ],
                array_keys($groups),
                array_values($groups)
            ),
        ];
    }

    /** 保存单题作答 */
    public static function saveAnswer(int $examId, string $stuId, int $paperId, string $answer): bool
    {
        return Database::query(
            'UPDATE `stupaper` SET stu_key = ?, quiz_status = -1
             WHERE exam_id = ? AND stu_id = ? AND paper_id = ?',
            [$answer, $examId, $stuId, $paperId]
        )->rowCount() > 0;
    }

    /**
     * 归一化前端提交的答案：
     * 多选题前端可能传数组，需合并为有序字符串；判断题 Y/N 兼容为 A/B。
     *
     * ⚠️ 归一化只对**客观题**（判断/单选/多选）生效：
     *   - 去空白、转大写、多选去重升序，都是为了「判分比对」两侧同口径；
     *   - 填空(text)/问答(longtext) 的**原文必须原样落库**，否则教师端
     *     批阅时看到的是大写无空格的乱码（作文 "My answer" → "MYANSWER"）。
     * 客观题判分不受影响：autoGrade 里 isCorrect() 对两侧做同样的归一化。
     */
    public static function normalizeSubmission(string $type, mixed $raw): string
    {
        if (is_array($raw)) {
            $raw = implode('', array_map('strval', $raw));
        }
        $answer = (string) $raw;
        // 仅客观题需要归一化；填空/问答原样保留（含大小写与空白）
        if (in_array($type, Quiz::OBJECTIVE_TYPES, true)) {
            $answer = Quiz::normalizeAnswer($type, $answer);
            // 判断题：前端可能提交 Y/N（对/错）
            if ($type === 'radio1') {
                $answer = str_replace(['Y', 'N'], ['A', 'B'], $answer);
            }
        }
        return $answer;
    }
}
