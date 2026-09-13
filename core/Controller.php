<?php
declare(strict_types=1);

namespace Core;

/**
 * 基础控制器：注入 Request / Response
 * 子类方法返回 Response 或数组（数组自动包装为成功 JSON）
 */
abstract class Controller
{
    public function __construct(
        protected readonly Request $request,
        protected readonly Response $response,
    ) {
    }

    /**
     * 声明式校验：validate(['name' => 'required|maxlen:50']) 失败抛 400，成功返回净化后的类型化值。
     *
     * 规则用 | 分隔，支持：required、email、numeric、integer、boolean、
     * min:N、max:N、minlen:N、maxlen:N、in:a,b,c、regex:/pattern/。
     * 非必填且未提供/为空的字段不参与校验、也不出现在返回数组中。
     *
     * 取值来源为 query + body 合并（body 优先），故同一套规则对 GET 查询串
     * 与 POST/PUT JSON 体均生效；显式传值为 null 时表示「不参与校验」，
     * 便于个别字段（如数组型 stu_key）绕开标量防护自行处理。
     */
    protected function validate(array $rules): array
    {
        $input = $this->request->all();
        $validated = [];
        foreach ($rules as $field => $rule) {
            $value = $input[$field] ?? null;
            $parts = array_values(array_filter(
                array_map('trim', explode('|', (string) $rule)),
                static fn (string $p): bool => $p !== ''
            ));

            // 必填：为空直接报错
            if (in_array('required', $parts, true) && ($value === null || $value === '')) {
                throw new HttpException(400, "参数 {$field} 不能为空", 40000);
            }
            // 非必填且未提供/为空 → 跳过，不纳入结果
            if ($value === null || $value === '') {
                continue;
            }
            // 标量防护：数组/对象等非标量值一律拒绝，避免 "Array to string conversion" 等 500
            if (is_array($value) || is_object($value)) {
                throw new HttpException(400, "参数 {$field} 必须是标量值", 40000);
            }

            foreach ($parts as $p) {
                if ($p === 'required') {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $p, 2), 2, null);
                $this->assertRule($field, $name, $arg, $value);
            }

            $validated[$field] = $this->normalize($parts, $value);
        }
        return $validated;
    }

    private function assertRule(string $field, string $name, ?string $arg, mixed $value): void
    {
        $fail = static fn (string $msg) => throw new HttpException(400, "参数 {$field} {$msg}", 40000);
        // 参数型规则缺少参数属于开发者配置错误，直接抛出
        $needArg = static function (?string $a, string $rule) use ($name): void {
            if ($a === null || $a === '') {
                throw new \LogicException("校验规则 {$name} 必须提供参数，如 {$rule}");
            }
        };
        switch ($name) {
            case 'email':
                if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $fail('必须是合法邮箱');
                }
                break;
            case 'numeric':
                if (!is_numeric($value)) {
                    $fail('必须是数字');
                }
                break;
            case 'integer':
                if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
                    $fail('必须是整数');
                }
                break;
            case 'boolean':
                if (!in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false', 'on', 'off'], true)) {
                    $fail('必须是布尔值');
                }
                break;
            case 'min':
                $needArg($arg, 'min:0');
                if (!is_numeric($value) || (float) $value < (float) $arg) {
                    $fail("不能小于 {$arg}");
                }
                break;
            case 'max':
                $needArg($arg, 'max:100');
                if (!is_numeric($value) || (float) $value > (float) $arg) {
                    $fail("不能大于 {$arg}");
                }
                break;
            case 'minlen':
                $needArg($arg, 'minlen:1');
                if ($this->countLength((string) $value) < (int) $arg) {
                    $fail("长度不能小于 {$arg}");
                }
                break;
            case 'maxlen':
                $needArg($arg, 'maxlen:50');
                if ($this->countLength((string) $value) > (int) $arg) {
                    $fail("长度不能大于 {$arg}");
                }
                break;
            case 'in':
                $needArg($arg, 'in:a,b,c');
                if (!in_array((string) $value, array_map('trim', explode(',', (string) $arg)), true)) {
                    $fail('取值非法');
                }
                break;
            case 'regex':
                $needArg($arg, 'regex:/^\d+$/');
                if (!is_string($value) || !preg_match((string) $arg, $value)) {
                    $fail('格式不正确');
                }
                break;
            default:
                throw new \LogicException("未知校验规则: {$name}");
        }
    }

    /** 字符串长度：优先按字符（mbstring），缺失时回退按字节，保证零依赖不 fatal */
    private function countLength(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    }

    /** 依据规则做类型归一化（integer→int，numeric→float，boolean→bool） */
    private function normalize(array $parts, mixed $value): mixed
    {
        if (in_array('integer', $parts, true) && is_numeric($value)) {
            return (int) $value;
        }
        if (in_array('numeric', $parts, true) && is_numeric($value)) {
            return (float) $value;
        }
        if (in_array('boolean', $parts, true)) {
            return in_array((string) $value, ['1', 'true', 'on'], true);
        }
        return $value;
    }
}
