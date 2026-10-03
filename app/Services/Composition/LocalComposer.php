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
 *
 * 难度缺档的兜底（2026-10-03 修）：此前 draw() 严格按 quiz_diff 精确过滤，一旦某个
 * 难度档在题库里没有题，该档就返回 0，且**不会用别的难度补**。而真实数据里绝大多数
 * 题库的难度是「不是 Y 就是 Z 就是 N」的单一分布（本项目 994 道旧题全为 Z），
 * 于是教师要 10 题只拿到 3-4 题，前端还标「题库题量不足，已截断」——实际不是题量
 * 不足，而是难度只有一档。现在改为：该档抽不够时按**就近难度**逐级放宽补足
 * （易←中←难，中←易/难，难←中←易），补进来的题在 difficulty 上如实显示实际难度，
 * 教师看得见真实情况，不会被「凑数」误导。
 */
final class LocalComposer implements ComposerProvider
{
    /**
     * 每个难度档抽不够时的兜底顺序（从最贴近该档的难度开始试）。
     * 逐级放宽而不是直接放弃：既保证题量，又尽量贴近教师要的难度。
     */
    private const DIFF_FALLBACK = [
        'Y' => ['Y', 'Z', 'N'],
        'Z' => ['Z', 'Y', 'N'],
        'N' => ['N', 'Z', 'Y'],
    ];

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
        // 记录因难度缺档而「借用」其他难度的题数，供调用方如实告知教师
        $borrowed = 0;
        foreach ([['Y', $easy], ['Z', $mid], ['N', $hard]] as [$diff, $n]) {
            if ((int) $n <= 0) {
                continue;
            }
            $want = (int) $n;
            $got  = 0;
            foreach (self::DIFF_FALLBACK[$diff] as $tryDiff) {
                if ($got >= $want) {
                    break;
                }
                $need = $want - $got;
                // ⚠️ 必须把已抽中的 id 传进 SQL 排除：否则难度缺档时「借」回来的题会被
                // 下一档的同一条 LIMIT 查询重复取到，全部撞上 $seen 被跳过 ——
                // 表现为「题库明明有 4 道，却只出 3 道且仍报截断」。
                $rows = $this->draw($subjId, $tryDiff, $kps, $types, $need, array_keys($seen));
                foreach ($rows as $r) {
                    $id = (int) $r['id'];
                    // 同一题不重复出现（跨难度档也不重复）
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $questions[] = $this->mapRow($r);
                    $got++;
                    if ($tryDiff !== $diff) {
                        $borrowed++;
                    }
                }
            }
        }

        return [
            'questions'  => $questions,
            'source'     => 'local',
            // 库里题不够凑满分布 → 标记截断，让前端提示教师补充题库
            'truncated'  => count($questions) < $total,
            // 难度缺档而借用了别的难度（题量补齐了，但难度分布与教师要求不完全一致）
            'diff_borrowed' => $borrowed,
        ];
    }

    /* ------------------------------------------------------------------ */

    /** @return array<int,array> */
    /**
     * 从题库按难度抽题。
     *
     * @param array<int,int|string> $exclude 已抽中的题 id，在 SQL 层排除。
     *        必须在 SQL 里排除而不是只在 PHP 里跳过：LIMIT 取回的行若大部分已被抽走，
     *        实际补进来的会远少于 need，跨难度缺档时会一路空转。
     */
    private function draw(int $subjId, string $diff, array $kps, array $types, int $n, array $exclude = []): array
    {
        if ($n <= 0) {
            return [];
        }
        $where = ['q.subj_id = ?', 'q.quiz_diff = ?'];
        $params = [$subjId, $diff];
        if ($exclude !== []) {
            $ph = implode(',', array_fill(0, count($exclude), '?'));
            $where[] = "q.id NOT IN ({$ph})";
            $params = array_merge($params, array_map('intval', $exclude));
        }
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
