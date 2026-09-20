<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use App\Models\Quiz;
use App\Services\Composition\ComposerFactory;
use Core\Database;
use Core\HttpException;

/**
 * C4 AI 组卷（自动命题）。
 *
 * 与 C4 阅卷同一哲学：**AI 只给建议，绝不直接决定卷面**。
 *
 *   suggest()  —— 只读，调用 ComposerFactory 生成「建议题目列表」，不改考试、不写库。
 *   apply()    —— 教师勾选后「采用」，把选中的题落库（远程新题先入库）、设为 manual
 *                 组卷模式、回填满分。这一步才是真正成卷，必须由人确认。
 *
 * 为什么远程生成的题要先入库再引用：manual 组卷模式依赖 exam_manual_quiz 指向
 * quizlib 的 id，而远程题原本不在题库里。入库有两个好处——① 复用既有的 manual
 * 出题/判分链路，零额外代码；② 教师「采用」过的题自然沉淀进题库，下次可直接复用。
 *
 * 护栏：apply 只允许在考试**尚未开考**（exam_status 为 exam/paper）时进行；一旦
 * testing（已开考、已按 manual 给每个学生排卷）再改卷，会与已生成的 stupaper 串题。
 */
final class ExamComposer
{
    /** 组卷窗口：考试尚未开考才可改 paper_mode */
    public static function isComposable(array $exam): bool
    {
        $status = (string) ($exam['exam_status'] ?? '');
        if ($status === '') {
            return true;   // 新建未落状态，视为可组卷
        }
        if ($status === Exam::STATUS_TESTING) {
            return false;
        }
        if (str_starts_with($status, 'over')) {
            return false;
        }
        return true;   // exam / paper 允许
    }

    /**
     * 生成建议题目（只读，不改考试）。
     * @param array{count?:int,easy?:int,mid?:int,hard?:int,kps?:array,types?:array} $spec
     * @return array{provider:array,degraded:bool,degrade_reason:string,questions:array,truncated:bool,composable:bool,subject_id:int}
     */
    public static function suggest(int $examId, array $spec): array
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if ((string) ($exam['exam_class'] ?? '') === Exam::MOCK_CLASS) {
            throw new HttpException(404, '考试不存在', 40400);
        }

        $subjId = (int) ($exam['subj_id'] ?? 0);
        $merged = array_merge(
            ['count' => 10, 'easy' => 3, 'mid' => 4, 'hard' => 3, 'kps' => [], 'types' => []],
            $spec
        );
        $merged['subj_id'] = $subjId;
        $merged['subject_name'] = self::subjectName($subjId);
        $merged['count'] = max(1, min(200, (int) ($merged['count'] ?? 10)));

        $r = ComposerFactory::compose($merged);

        // 注意：provider 必须反映「本次实际使用的引擎」，而不是「配置上本该用的引擎」。
        // 远程模型不可用已降级为本地时，这里必须报 local，否则前端会把降级误显示为远程成功。
        $actualKey = (string) ($r['provider'] ?? 'local');
        $provider = $actualKey === 'remote'
            ? ['key' => 'remote', 'label' => '远程模型', 'enabled' => true]
            : ['key' => 'local', 'label' => '本地抽样', 'enabled' => true];

        return [
            'provider'       => $provider,
            'degraded'       => !empty($r['degraded']),
            'degrade_reason' => $r['degrade_reason'] ?? '',
            'questions'      => $r['questions'],
            'truncated'      => !empty($r['truncated']),
            'composable'     => self::isComposable($exam),
            'subject_id'     => $subjId,
        ];
    }

    /**
     * 采用选中题目，落库为 manual 组卷。
     * @param array<int,array{id?:?int,type?:string,stem?:string,options?:?array,answer?:string,kp?:string,difficulty?:string}> $chosen
     * @return array{applied:int,skipped:int,new:int,total_score:int}
     */
    public static function apply(int $examId, array $chosen): array
    {
        $exam = (new Exam())->find($examId);
        if ($exam === null) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if ((string) ($exam['exam_class'] ?? '') === Exam::MOCK_CLASS) {
            throw new HttpException(404, '考试不存在', 40400);
        }
        if (!self::isComposable($exam)) {
            throw new HttpException(409, '考试已开始，无法修改组卷', 40900);
        }

        $items = [];
        $skipped = 0;
        $new = 0;
        foreach ($chosen as $c) {
            if (!is_array($c)) {
                continue;
            }
            $type = (string) ($c['type'] ?? '');
            if (!in_array($type, Quiz::TYPES, true)) {
                $skipped++;
                continue;
            }
            $stem = trim((string) ($c['stem'] ?? ''));
            if ($stem === '') {
                $skipped++;
                continue;
            }
            $answer = trim((string) ($c['answer'] ?? ''));
            $options = $c['options'] ?? null;
            if (in_array($type, Quiz::OBJECTIVE_TYPES, true)) {
                $opts = self::normOptions($options);
                if ($opts === [] || $answer === '') {
                    $skipped++;
                    continue;
                }
            } else {
                if ($answer === '') {
                    $skipped++;
                    continue;
                }
                $opts = [];
            }

            $id = isset($c['id']) ? (int) ($c['id']) : 0;
            if ($id > 0) {
                $row = (new Quiz())->find($id);
                if ($row !== null && (int) ($row['subj_id'] ?? 0) === (int) ($exam['subj_id'] ?? 0)) {
                    $items[] = $id;
                    continue;
                }
            }
            // 远程新题：先入库，再引用
            $quizId = self::insertQuiz($exam, $type, $stem, $opts, $answer, $c['difficulty'] ?? '', (string) ($c['kp'] ?? ''));
            if ($quizId > 0) {
                $items[] = $quizId;
                $new++;
            } else {
                $skipped++;
            }
        }

        if ($items === []) {
            throw new HttpException(400, '没有可采用的题目', 40000);
        }

        Database::beginTransaction();
        try {
            Database::query('DELETE FROM `exam_manual_quiz` WHERE exam_id = ?', [$examId]);
            foreach ($items as $i => $qid) {
                Database::query(
                    'INSERT INTO `exam_manual_quiz` (exam_id, quiz_id, sort) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE sort = VALUES(sort)',
                    [$examId, (int) $qid, $i]
                );
            }
            Database::query('UPDATE `examinfo` SET paper_mode = ? WHERE id = ?', ['manual', $examId]);

            // 按实际所选题型 × 每题分值回填满分（manual 模式口径，见 Exam::computedTotalScore）
            $e2 = $exam;
            $e2['paper_mode'] = 'manual';
            $e2['_manual_ids'] = $items;
            $score = Exam::computedTotalScore($e2);
            if ($score > 0) {
                Database::query('UPDATE `examinfo` SET exam_score = ? WHERE id = ?', [$score, $examId]);
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return ['applied' => count($items), 'skipped' => $skipped, 'new' => $new, 'total_score' => $score ?? 0];
    }

    /* ------------------------------------------------------------------ */

    /** @return array<int,array{key:string,text:string}> */
    private static function normOptions(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $o) {
            if (is_array($o)) {
                $text = trim((string) ($o['text'] ?? $o['value'] ?? ''));
            } else {
                $text = trim((string) $o);
            }
            if ($text === '') {
                continue;
            }
            // 去掉可能带上的 "A. " 前缀，parseOptions 入库时会重新按位置编号
            $text = preg_replace('/^[A-Z][.、:：\s]+/u', '', $text) ?? $text;
            $out[] = ['key' => chr(65 + count($out)), 'text' => $text];
        }
        return $out;
    }

    private static function insertQuiz(array $exam, string $type, string $stem, array $options, string $answer, string $diff, string $kp): int
    {
        $optStr = implode('|', array_map(static fn (array $o): string => $o['text'], $options));
        $normDiff = in_array($diff, Quiz::DIFFS, true) ? $diff : 'Z';
        $normAnswer = Quiz::normalizeAnswer($type, $answer);

        Database::query(
            'INSERT INTO `quizlib`
             (subj_id, quiz_title, quiz_class, quiz_option, quiz_key, quiz_diff, quiz_writer, quiz_time, quiz_kp, quiz_key_ok)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                (int) ($exam['subj_id'] ?? 0),
                mb_substr($stem, 0, 500),
                $type,
                $optStr,
                $normAnswer,
                $normDiff,
                'AI组卷',
                date('Y-m-d H:i:s'),
                trim($kp),
            ]
        );
        return Database::lastInsertId();
    }

    private static function subjectName(int $subjId): string
    {
        if ($subjId <= 0) {
            return '';
        }
        $row = Database::fetch('SELECT subj_name FROM `subject` WHERE id = ?', [$subjId]);
        return $row === null ? '' : (string) ($row['subj_name'] ?? '');
    }
}
