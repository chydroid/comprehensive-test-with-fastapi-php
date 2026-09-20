<?php

declare(strict_types=1);

namespace App\Services\Composition;

use App\Models\Quiz;
use App\Services\Setting;

/**
 * 远程大模型组卷（OpenAI 兼容 chat/completions）。
 *
 * 与阅卷的 RemoteGrader 同构：不引第三方 SDK，换地址即用。适配「OpenAI 兼容」
 * 而不是某家 SDK，是为了让国内各家（通义 / DeepSeek / 智谱 / moonshot）与自建
 * 网关、vLLM / Ollama / One-API 都能直接替换 endpoint。
 *
 * 失败策略（关键）：任何环节失败都抛 ComposerFailureException，由 ComposerFactory
 * 捕获后降级为本地抽样。组卷页永远不会因为「模型服务挂了」而打不开。
 *
 * 隐私边界：发送给模型的内容只有**题型要求、知识点、难度、题目数量**——不含任何
 * 考生信息，也不含现有题目的答案键（避免把题库答案泄露给外部服务）。模型生成的
 * 是「新题」，落库前仍由教师人工确认。
 */
final class LlmComposer implements ComposerProvider
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

    public function compose(array $spec): array
    {
        $subjId = (int) ($spec['subj_id'] ?? 0);
        if ($subjId <= 0) {
            return ['questions' => [], 'source' => 'remote', 'truncated' => false];
        }

        $payload = [
            'model'       => self::model(),
            'temperature' => 0.7,
            'messages'    => [
                ['role' => 'system', 'content' => self::systemPrompt()],
                ['role' => 'user',   'content' => self::userPrompt($spec)],
            ],
        ];

        $raw = self::post(self::endpoint(), $payload);
        $questions = self::parse($raw, $spec);

        return ['questions' => $questions, 'source' => 'remote', 'truncated' => false];
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
        return '你是一名严谨的题库命题助手。你会按教师要求生成若干道选择题/填空题/问答题。'
            . '请只输出一个 JSON 数组，不要任何解释、不要 markdown 代码块，数组每个元素格式：'
            . '{"type":"题型","stem":"题干","options":["选项A文本","选项B文本",...],'
            . '"answer":"正确答案","kp":"知识点","difficulty":"难度","analysis":"解析"}。'
            . '题型 type 只取以下之一：radio1(判断题)、radio2(单选题)、checkbox(多选题)、text(填空题)、longtext(问答题)。'
            . '判断题 options 为 ["对","错"]，answer 为 "A" 或 "B"。'
            . '单选题/多选题 options 为各选项文本，answer 为正确选项的字母（多选题多个字母连写，如 "AB"）。'
            . '填空题 answer 为填空答案，问答题 answer 为参考答案要点。'
            . '难度 difficulty 只取：Y(易)、Z(中)、N(难)。';
    }

    private static function userPrompt(array $spec): string
    {
        $subjName = trim((string) ($spec['subject_name'] ?? ''));
        $easy = max(0, (int) ($spec['easy'] ?? 0));
        $mid  = max(0, (int) ($spec['mid'] ?? 0));
        $hard = max(0, (int) ($spec['hard'] ?? 0));
        $total = $easy + $mid + $hard;
        if ($total <= 0) {
            $total = max(1, (int) ($spec['count'] ?? 10));
        }
        $kps = array_values(array_filter(array_map('trim', (array) ($spec['kps'] ?? [])), static fn (string $k): bool => $k !== ''));
        $types = array_values(array_filter(array_map('trim', (array) ($spec['types'] ?? [])), static fn (string $t): bool => $t !== ''));

        $lines = [];
        $lines[] = '请为科目「' . ($subjName !== '' ? $subjName : '通用') . '」生成 ' . $total . ' 道题目。';
        $lines[] = '难度分布：易 ' . $easy . ' 道、中 ' . $mid . ' 道、难 ' . $hard . ' 道。';
        if ($kps !== []) {
            $lines[] = '建议覆盖知识点：' . implode('、', $kps) . '。';
        }
        if ($types !== []) {
            $lines[] = '限定题型：' . implode('、', $types) . '。';
        }
        $lines[] = '请直接输出 JSON 数组。';
        return implode("\n", $lines);
    }

    /** @return array<string,mixed> */
    private static function post(string $url, array $payload): array
    {
        $timeout = Setting::int('ai_grading_timeout', 20);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new ComposerFailureException('组卷请求序列化失败');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ComposerFailureException('无法初始化 HTTP 客户端');
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new ComposerFailureException('模型服务请求失败: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new ComposerFailureException('模型服务返回 HTTP ' . $status);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new ComposerFailureException('模型服务返回内容不是合法 JSON');
        }
        return $data;
    }

    /** @return array<int,array{id:null,type:string,stem:string,options:?array,answer:string,kp:string,difficulty:string,analysis:?string,new:bool}> */
    private static function parse(array $data, array $spec): array
    {
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            throw new ComposerFailureException('模型返回内容为空');
        }

        $json = self::extractJson($content);
        if (!is_array($json) || !array_is_list($json)) {
            throw new ComposerFailureException('模型返回内容中未找到题目数组');
        }

        $subjectName = trim((string) ($spec['subject_name'] ?? ''));
        $out = [];
        foreach ($json as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = self::normType((string) ($item['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $stem = trim((string) ($item['stem'] ?? ''));
            if ($stem === '') {
                continue;
            }
            $diff = self::normDiff((string) ($item['difficulty'] ?? ''));
            $options = self::normOptions($item['options'] ?? null);
            // 客观题必须有选项且答案非空；主观题答案（要点）必须非空
            if (in_array($type, Quiz::OBJECTIVE_TYPES, true)) {
                if ($options === [] || trim((string) ($item['answer'] ?? '')) === '') {
                    continue;
                }
            } else {
                if (trim((string) ($item['answer'] ?? '')) === '') {
                    continue;
                }
            }
            $out[] = [
                'id'         => null,
                'type'       => $type,
                'stem'       => mb_substr($stem, 0, 500),
                'options'    => $options,
                'answer'     => trim((string) ($item['answer'] ?? '')),
                'kp'         => trim((string) ($item['kp'] ?? '')),
                'difficulty' => $diff === '' ? 'Z' : $diff,
                'analysis'   => trim((string) ($item['analysis'] ?? '')) !== '' ? mb_substr(trim((string) ($item['analysis'])), 0, 500) : null,
                'new'        => true,
                // 仅前端展示用，不入库
                'subject_name' => $subjectName,
            ];
        }
        if ($out === []) {
            throw new ComposerFailureException('模型返回的题目均不可用（题型/答案缺失）');
        }
        return $out;
    }

    private static function normType(string $t): string
    {
        $t = trim($t);
        $map = [
            'radio1' => 'radio1', '判断题' => 'radio1', '判断' => 'radio1', 'truefalse' => 'radio1',
            'radio2' => 'radio2', '单选题' => 'radio2', '单选' => 'radio2', 'single' => 'radio2',
            'checkbox' => 'checkbox', '多选题' => 'checkbox', '多选' => 'checkbox', 'multiple' => 'checkbox',
            'text' => 'text', '填空题' => 'text', '填空' => 'text', 'fill' => 'text',
            'longtext' => 'longtext', '问答题' => 'longtext', '问答' => 'longtext', 'essay' => 'longtext',
        ];
        return $map[mb_strtolower($t)] ?? (in_array($t, Quiz::TYPES, true) ? $t : '');
    }

    private static function normDiff(string $d): string
    {
        $d = trim($d);
        $map = [
            'Y' => 'Y', '易' => 'Y', '简单' => 'Y', 'easy' => 'Y',
            'Z' => 'Z', '中' => 'Z', '中等' => 'Z', 'medium' => 'Z',
            'N' => 'N', '难' => 'N', '困难' => 'N', 'hard' => 'N',
        ];
        return $map[mb_strtolower($d)] ?? '';
    }

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
            $out[] = ['key' => chr(65 + count($out)), 'text' => $text];
        }
        return $out;
    }

    /** 从可能被 ```json 包裹的回复里抠出第一个 JSON 数组 */
    private static function extractJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text) ?? $text;
        $text = preg_replace('/```$/', '', trim($text)) ?? $text;

        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $arr = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($arr) ? $arr : null;
    }
}
