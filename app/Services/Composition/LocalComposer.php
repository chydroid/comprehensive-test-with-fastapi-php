<?php

declare(strict_types=1);

namespace App\Services\Composition;

use App\Models\Quiz;
use Core\Database;

/**
 * 本地智能抽样组卷（不联网、零成本、永远可用）。
 *
 * 这是 C4 组卷的**默认实现**：管理员不配置任何外部模型时，教师端「AI 智能组卷」
 * 走的就是它。它也是远程生成失败时的降级目标——因此必须无条件可用、不依赖任何扩展。
 *
 * 它不是「随机抽」，而是按教师给的**难度分布 + 可选知识点 + 可选题型**从题库里
 * 精确地捞：易 N1 道、中 N2 道、难 N3 道，可进一步限定知识点与题型。这比纯随
 * 机更有用——教师想「凑一套含网络层知识点的中等难度卷」时不用自己翻题库。
 *
 * 边界：题库题量不足时有多少返回多少，并标 truncated=true（前端提示「题库不足」），
 * 绝不重复出题、绝不编造题目。
 */
final class LocalComposer implements ComposerProvider
{
    public function key(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return '本地抽样';
    }

    public function isAvailable(): bool
    {
        return true;   // 无外部依赖，永远可用
    }

    public function compose(array $spec): array
    {
        $subjId = (int) ($spec['subj_id'] ?? 0);
        if ($subjId <= 0) {
            return ['questions' => [], 'source' => 'local', 'truncated' => false];
        }

        $kps   = array_values(array_filter(array_map('trim', (array) ($spec['kps'] ?? [])), static fn (string $k): bool => $k !== ''));
        $types = array_values(array_filter(
            array_map('trim', (array) ($spec['types'] ?? [])),
            static fn (string $t): bool => in_array($t, Quiz::TYPES, true)
        ));

        $easy = max(0, (int) ($spec['easy'] ?? 0));
        $mid  = max(0, (int) ($spec['mid'] ?? 0));
        $hard = max(0, (int) ($spec['hard'] ?? 0));
        $total = $easy + $mid + $hard;

        // 只给了总数没给分布：平均摊到三档（余量给难题，因为通常最缺）
        if ($total <= 0) {
            $total = max(1, (int) ($spec['count'] ?? 10));
            $base = intdiv($total, 3);
            $rem = $total - $base * 3;
            $easy = $base;
            $mid  = $base;
            $hard = $base + $rem;
        }

        $questions = [];
        $seen = [];
        foreach ([['Y', $easy], ['Z', $mid], ['N', $hard]] as [$diff, $n]) {
            if ((int) $n <= 0) {
                continue;
            }
            foreach ($this->draw($subjId, $diff, $kps, $types, (int) $n) as $r) {
                $id = (int) $r['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $questions[] = $this->mapRow($r);
            }
        }

        return [
            'questions'  => $questions,
            'source'     => 'local',
            // 库里题不够凑满分布 → 标记截断，让前端提示教师补充题库
            'truncated'  => count($questions) < $total,
        ];
    }

    /* ------------------------------------------------------------------ */

    /** @return array<int,array> */
    private function draw(int $subjId, string $diff, array $kps, array $types, int $n): array
    {
        if ($n <= 0) {
            return [];
        }
        $where = ['q.subj_id = ?', 'q.quiz_diff = ?'];
        $params = [$subjId, $diff];
        if ($kps !== []) {
            $ph = implode(',', array_fill(0, count($kps), '?'));
            $where[] = "q.quiz_kp IN ({$ph})";
            $params = array_merge($params, $kps);
        }
        if ($types !== []) {
            $ph = implode(',', array_fill(0, count($types), '?'));
            $where[] = "q.quiz_class IN ({$ph})";
            $params = array_merge($params, $types);
        }
        $sql = 'SELECT q.id, q.quiz_title, q.quiz_class, q.quiz_option, q.quiz_key, q.quiz_diff, q.quiz_kp'
             . ' FROM `quizlib` q WHERE ' . implode(' AND ', $where)
             . ' ORDER BY RAND() LIMIT ' . (int) $n;
        return Database::fetchAll($sql, $params);
    }

    /** @return array{id:int,type:string,stem:string,options:array,answer:string,kp:string,difficulty:string,analysis:?string,new:bool} */
    private function mapRow(array $r): array
    {
        return [
            'id'        => (int) $r['id'],
            'type'      => (string) $r['quiz_class'],
            'stem'      => (string) $r['quiz_title'],
            'options'   => Quiz::parseOptions((string) ($r['quiz_option'] ?? '')),
            'answer'    => (string) $r['quiz_key'],
            'kp'        => (string) ($r['quiz_kp'] ?? ''),
            'difficulty' => (string) $r['quiz_diff'],
            'analysis'  => null,
            'new'       => false,
        ];
    }
}
