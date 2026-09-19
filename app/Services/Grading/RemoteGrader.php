<?php

declare(strict_types=1);

namespace App\Services\Grading;

use App\Services\Setting;

/**
 * 远程大模型阅卷建议（OpenAI 兼容 chat/completions）。
 *
 * 适配「OpenAI 兼容」而不是某一家的 SDK，原因与项目整体取向一致：不引第三方依赖，
 * vendor 目录里不留东西。国内各家（通义、DeepSeek、智谱、 moonshot）与自建的
 * vLLM / Ollama / One-API 网关都提供这一套接口，换个地址就能用。
 *
 * 失败策略（关键）：任何环节失败都抛 GraderFailureException，由 GraderFactory
 * 捕获后降级为本地启发式。批阅页永远不会因为「模型服务挂了」而打不开。
 *
 * 隐私边界：发送给模型的内容只有题干、参考答案、考生作答和满分——**不含姓名、
 * 准考证号、班级**。这也是提示词里刻意不提供任何身份信息的原因：模型只需要
 * 判断「这段作答答到了没有」，不需要知道是谁写的。
 */
final class RemoteGrader implements GraderProvider
{
    public function key(): string
    {
        return 'remote';
    }

    public function label(): string
    {
        return '远程模型';
    }

    public function isAvailable(): bool
    {
        if (!Setting::bool('ai_grading_enabled', false)) {
            return false;
        }
        if (!function_exists('curl_init')) {
            return false;
        }
        return self::endpoint() !== '' && self::model() !== '' && self::apiKey() !== '';
    }

    public function suggest(array $item): array
    {
        $full = max(0, (int) ($item['full_score'] ?? 0));
        $ref = trim((string) ($item['reference'] ?? ''));
        $ans = trim((string) ($item['answer'] ?? ''));

        if ($ref === '') {
            throw new GraderFailureException('本题未设置参考答案');
        }
        if ($ans === '') {
            // 不必浪费一次外部调用
            return [
                'score'          => 0,
                'confidence'     => 1.0,
                'reason'         => '考生未作答。',
                'hits'           => [],
                'missing'        => [],
                'provider'       => $this->key(),
                'provider_label' => $this->label(),
            ];
        }

        $payload = [
            'model'       => self::model(),
            'temperature' => 0,
            'messages'    => [
                ['role' => 'system', 'content' => self::systemPrompt()],
                ['role' => 'user',   'content' => self::userPrompt($item, $full)],
            ],
        ];

        $raw = self::post(self::endpoint(), $payload);
        return self::parse($raw, $full);
    }

    /* ------------------------------------------------------------------ */

    private static function endpoint(): string
    {
        return (string) Setting::get('ai_grading_endpoint', '');
    }

    private static function model(): string
    {
        return (string) Setting::get('ai_grading_model', '');
    }

    private static function apiKey(): string
    {
        return (string) Setting::get('ai_grading_api_key', '');
    }

    private static function systemPrompt(): string
    {
        return '你是一名严谨的考试阅卷助手。你会拿到一道主观题的题干、参考答案和考生作答。'
            . '请只依据「作答内容是否达到参考答案的要点」打分，做到：'
            . '1) 意思正确但表述不同，应得分；2) 要点缺失按比例扣分；'
            . '3) 照抄题干、答非所问、空白作答均记 0 分；'
            . '4) 不因字数多而给高分，也不因表述简略而扣分，只看要点。'
            . '只输出一行 JSON，不要任何解释、不要 markdown 代码块，格式：'
            . '{"score": 整数, "confidence": 0到1的小数, "reason": "不超过80字的中文理由", '
            . '"hits": ["命中的要点"], "missing": ["缺失的要点"]}';
    }

    private static function userPrompt(array $item, int $full): string
    {
        return "【题干】\n" . trim((string) ($item['title'] ?? ''))
            . "\n\n【参考答案】\n" . trim((string) ($item['reference'] ?? ''))
            . "\n\n【考生作答】\n" . trim((string) ($item['answer'] ?? ''))
            . "\n\n【本题满分】" . $full . ' 分'
            . "\n请给出 JSON 评分结果。";
    }

    /** @return array<string,mixed> */
    private static function post(string $url, array $payload): array
    {
        $timeout = Setting::int('ai_grading_timeout', 20);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new GraderFailureException('评分请求序列化失败');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new GraderFailureException('无法初始化 HTTP 客户端');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::apiKey(),
            ],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            // 证书校验保持开启：关闭 verify 会让 API Key 在中间人面前裸奔
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new GraderFailureException('模型服务请求失败: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new GraderFailureException('模型服务返回 HTTP ' . $status);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new GraderFailureException('模型服务返回内容不是合法 JSON');
        }
        return $data;
    }

    /** @return array{score:?int,confidence:float,reason:string,hits:string[],missing:string[],provider:string,provider_label:string} */
    private static function parse(array $data, int $full): array
    {
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            throw new GraderFailureException('模型返回内容为空');
        }

        $json = self::extractJson($content);
        if ($json === null) {
            throw new GraderFailureException('模型返回内容中未找到 JSON');
        }

        $score = $json['score'] ?? null;
        if (!is_numeric($score)) {
            throw new GraderFailureException('模型未给出可用的分数');
        }
        $score = (int) round((float) $score);
        $score = max(0, min($full, $score));   // 越界一律夹取，绝不写出超过满分的成绩

        $conf = is_numeric($json['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $json['confidence']))
            : 0.6;

        return [
            'score'          => $score,
            'confidence'     => round($conf, 2),
            'reason'         => mb_substr(trim((string) ($json['reason'] ?? '')), 0, 300),
            'hits'           => self::strList($json['hits'] ?? []),
            'missing'        => self::strList($json['missing'] ?? []),
            'provider'       => 'remote',
            'provider_label' => '远程模型',
        ];
    }

    /** 从可能被 ```json 包裹的回复里抠出第一个 JSON 对象 */
    private static function extractJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text) ?? $text;
        $text = preg_replace('/```$/', '', trim($text)) ?? $text;

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $obj = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($obj) ? $obj : null;
    }

    /** @return string[] */
    private static function strList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            if (is_string($x) && trim($x) !== '') {
                $out[] = mb_substr(trim($x), 0, 60);
            }
            if (count($out) >= 10) {
                break;
            }
        }
        return $out;
    }
}
