<?php

declare(strict_types=1);

namespace App\Services\Grading;

/**
 * 内置启发式阅卷建议（不联网、零成本、可解释）。
 *
 * 这是 C4 的**默认实现**：管理员不配置任何外部服务时，教师端「AI 建议」走的就是它。
 * 它也是远程模型失败时的降级目标 —— 因此它必须无条件可用、不依赖任何扩展。
 *
 * 评分思路（三个信号加权，全部 0–1）：
 *
 *   覆盖率 coverage   参考答案的特征有多大比例出现在作答里。
 *   要点命中 kwRate   参考答案按标点切出的要点被提及的比例（用于生成可读依据）。
 *   长度比 lenRatio   作答长度 / 参考答案长度（截断到 1），惩罚「只写一句话」。
 *
 *   ratio = 0.55·coverage + 0.30·kwRate + 0.15·lenRatio
 *
 * 「特征」分两类，这是中文场景必须分开处理的地方：
 *
 *   - **术语**（连续的 ASCII 字母/数字，如 SYN、ACK、TCP、HTTP200）：按**整词**匹配。
 *     把它们拆成单字或二字组是错的 —— 「SYN+ACK」拆开后与「SYN加ACK」几乎对不上，
 *     换一种说法就被判成没答，这对计算机类科目（本项目的主要场景）是致命的。
 *   - **中文**：按**相邻二字组（bigram）**匹配。项目零依赖、没有分词库，而 bigram
 *     对「换语序、改措辞」的容忍度恰好合适。
 *
 *   两者都有时各占一半权重；只有其一时以它为准。
 *
 * 两个刻意加上的判罚（真实批改中一定会遇到）：
 *  - **抄题干**：作答与题干高度重合、却又明显没踩到参考答案要点时直接判 0 分。
 *    这是最常见的「混分」手法，纯相似度算法会给它不低的分。
 *  - **无参考答案**：score 返回 null。题库里大量问答题的 quiz_key 是空的，
 *    此时任何分数都是编的，宁可不给。
 */
final class HeuristicGrader implements GraderProvider
{
    /** 权重之和为 1 */
    private const W_COVERAGE = 0.55;
    private const W_KEYWORD  = 0.30;
    private const W_LENGTH   = 0.15;

    /** 判定「疑似抄题干」的题干覆盖率阈值 */
    private const COPY_TITLE_THRESHOLD = 0.80;

    public function key(): string
    {
        return 'heuristic';
    }

    public function label(): string
    {
        return '本地启发式';
    }

    public function isAvailable(): bool
    {
        return true;   // 无外部依赖，永远可用
    }

    public function suggest(array $item): array
    {
        $full = max(0, (int) ($item['full_score'] ?? 0));
        $titleRaw = (string) ($item['title'] ?? '');
        $refRaw   = (string) ($item['reference'] ?? '');
        $ansRaw   = (string) ($item['answer'] ?? '');
        $ans = self::normalize($ansRaw);

        if (self::normalize($refRaw) === '') {
            return self::result(null, 0.0, '本题未设置参考答案，无法给出建议分，请人工批阅。', [], []);
        }
        if ($ans === '') {
            return self::result(0, 1.0, '考生未作答。', [], []);
        }
        if ($ans === self::normalize($refRaw)) {
            return self::result($full, 1.0, '作答与参考答案一致。', [], []);
        }

        $coverage = self::similarity($refRaw, $ansRaw);

        // 抄题干：作答与题干几乎重合，但对参考答案的覆盖明显更低
        if (self::normalize($titleRaw) !== '') {
            $titleCover = self::similarity($titleRaw, $ansRaw);
            if ($titleCover >= self::COPY_TITLE_THRESHOLD && $coverage < $titleCover - 0.15) {
                return self::result(
                    0,
                    0.9,
                    sprintf(
                        '作答与题干重合度 %.0f%%，对参考答案的覆盖仅 %.0f%%，疑似照抄题目，建议 0 分。',
                        $titleCover * 100,
                        $coverage * 100
                    ),
                    [],
                    []
                );
            }
        }

        $kw = self::keywordAnalysis($refRaw, $ansRaw);
        $refLen = mb_strlen(self::normalize($refRaw));
        $ansLen = mb_strlen($ans);
        $lenRatio = $refLen > 0 ? min(1.0, $ansLen / $refLen) : 1.0;

        // 没有可用要点时（参考答案本身很短），用覆盖率兜底，避免 kwRate 恒为 0 拉低分数
        $kwRate = $kw['total'] > 0 ? $kw['hits'] / $kw['total'] : $coverage;

        $ratio = self::W_COVERAGE * $coverage
               + self::W_KEYWORD * $kwRate
               + self::W_LENGTH * $lenRatio;
        $score = (int) round(min(1.0, max(0.0, $ratio)) * $full);

        $confidence = round(min(1.0, 0.35 + 0.5 * min($coverage, $kwRate) + 0.15 * $lenRatio), 2);

        $reason = sprintf(
            '参考答案要点覆盖 %.0f%%，命中 %d/%d 个要点；作答长度约为参考答案的 %.0f%%。',
            $coverage * 100,
            $kw['hits'],
            $kw['total'],
            $lenRatio * 100
        );
        if ($kw['hit_list'] !== []) {
            $reason .= '已提及：' . implode('、', array_slice($kw['hit_list'], 0, 5)) . '。';
        }
        if ($kw['miss_list'] !== []) {
            $reason .= '未提及：' . implode('、', array_slice($kw['miss_list'], 0, 5)) . '。';
        }

        return self::result($score, $confidence, $reason, $kw['hit_list'], $kw['miss_list']);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{score:?int,confidence:float,reason:string,hits:string[],missing:string[],provider:string,provider_label:string} */
    private static function result(?int $score, float $confidence, string $reason, array $hits, array $missing): array
    {
        return [
            'score'          => $score,
            'confidence'     => $score === null ? 0.0 : $confidence,
            'reason'         => $reason,
            'hits'           => $hits,
            'missing'        => $missing,
            'provider'       => 'heuristic',
            'provider_label' => '本地启发式',
        ];
    }

    /** 归一化：去空白与常见标点，统一小写 */
    private static function normalize(string $s): string
    {
        $s = preg_replace('/\s+/u', '', $s) ?? '';
        $s = preg_replace('/[，,。.；;：:、！!？?（）()\[\]【】「」“”"\'’‘—\-_·\/\\\\|+*=<>~`@#$%^&]+/u', '', $s) ?? '';
        return mb_strtolower($s);
    }

    /**
     * 两段文本的相似度 0–1：术语命中率与中文 bigram 覆盖率各占一半。
     * 传入**原始**文本（含标点）：术语提取需要靠标点/空格断词。
     */
    private static function similarity(string $refRaw, string $ansRaw): float
    {
        $refTerms = self::terms($refRaw);
        $refGrams = self::cjkBigrams($refRaw);
        $ans = self::normalize($ansRaw);

        $rates = [];
        if ($refTerms !== []) {
            $hit = 0;
            foreach ($refTerms as $t) {
                if (str_contains($ans, $t)) {
                    $hit++;
                }
            }
            $rates[] = $hit / count($refTerms);
        }
        if ($refGrams !== []) {
            $hit = 0;
            foreach ($refGrams as $g) {
                if (str_contains($ans, $g)) {
                    $hit++;
                }
            }
            $rates[] = $hit / count($refGrams);
        }
        if ($rates === []) {
            return str_contains($ans, self::normalize($refRaw)) ? 1.0 : 0.0;
        }
        return array_sum($rates) / count($rates);
    }

    /**
     * ASCII 术语（连续字母/数字，长度 ≥2），去重、小写。
     * 长度门槛取 2 是为了让「C」「B」这类单字母级别代号不参与评分（噪声太大）。
     * @return string[]
     */
    private static function terms(string $s): array
    {
        preg_match_all('/[a-z0-9]{2,}/i', $s, $m);
        $out = [];
        foreach ($m[0] as $t) {
            $out[mb_strtolower($t)] = true;
        }
        return array_keys($out);
    }

    /** 中文部分（剔除 ASCII）的相邻二字组，去重 */
    private static function cjkBigrams(string $s): array
    {
        $s = preg_replace('/[^\p{Han}\p{Katakana}\p{Hiragana}]/u', '', $s) ?? '';
        $len = mb_strlen($s);
        if ($len < 2) {
            return $len === 1 ? [$s] : [];
        }
        $out = [];
        for ($i = 0; $i < $len - 1; $i++) {
            $out[mb_substr($s, $i, 2)] = true;
        }
        return array_keys($out);
    }

    /**
     * 参考答案要点命中分析。
     *
     * 切分发生在**去标点之前**：参考答案的要点本就靠「，；。」分隔，先归一化会把
     * 它们连成一整段，之后只能靠滑窗硬切，切出来的「要点」毫无语义（曾有此 bug）。
     *
     * @return array{hits:int,total:int,hit_list:string[],miss_list:string[]}
     */
    private static function keywordAnalysis(string $refRaw, string $ansRaw): array
    {
        $parts = preg_split('/[，,。.；;、：:！!？?\/\|\n\r]+/u', $refRaw) ?: [];
        $kw = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (mb_strlen($p) < 2) {
                continue;          // 单字噪声太大，不当要点
            }
            if (mb_strlen($p) > 20) {
                // 超长片段按 10 字滑窗再切，否则「整段当一个要点」永远命中不了
                $len = mb_strlen($p);
                for ($i = 0; $i < $len; $i += 10) {
                    $chunk = mb_substr($p, $i, 10);
                    if (mb_strlen($chunk) >= 2) {
                        $kw[] = $chunk;
                    }
                }
                continue;
            }
            $kw[] = $p;
        }
        if ($kw === []) {
            return ['hits' => 0, 'total' => 0, 'hit_list' => [], 'miss_list' => []];
        }
        if (count($kw) > 12) {
            $kw = array_slice($kw, 0, 12);   // 依据只展示前 12 个，避免理由文本过长
        }

        $hitList = [];
        $missList = [];
        foreach ($kw as $k) {
            if (self::similarity($k, $ansRaw) >= 0.6) {
                $hitList[] = $k;
            } else {
                $missList[] = $k;
            }
        }

        return [
            'hits'      => count($hitList),
            'total'     => count($kw),
            'hit_list'  => $hitList,
            'miss_list' => $missList,
        ];
    }
}
