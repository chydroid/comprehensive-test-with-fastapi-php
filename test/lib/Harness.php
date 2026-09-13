<?php

declare(strict_types=1);

namespace Test;

/**
 * 极简测试基座：注册用例、统计通过/失败、支持 SKIP 与异常守卫。
 *
 * 设计约束：
 * - guard() 捕获 Throwable 并记为 FAIL，避免一个用例崩溃拖垮整个套件
 * - skip() 用于环境缺失（如数据库不可用）时诚实标注，而非伪装通过
 * - finish() 以退出码返回失败数，供 CI 判定
 */
class Harness
{
    /** @var array<int, array{case:string, ok:bool, info:string}> */
    private array $results = [];
    /** @var array<int, array{case:string, reason:string}> */
    private array $skips = [];
    private int $passed = 0;
    private int $failed = 0;

    /** 记录一个断言结果 */
    public function register(string $case, bool $ok, string $info = ''): void
    {
        $this->results[] = ['case' => $case, 'ok' => $ok, 'info' => $info];
        if ($ok) {
            $this->passed++;
            echo "  PASS  {$case}\n";
        } else {
            $this->failed++;
            echo "  FAIL  {$case}" . ($info !== '' ? "  -> {$info}" : '') . "\n";
        }
    }

    /** 记录跳过 */
    public function skip(string $case, string $reason): void
    {
        $this->skips[] = ['case' => $case, 'reason' => $reason];
        echo "  SKIP  {$case}  ({$reason})\n";
    }

    /** 执行回调；抛出的 Throwable 记为 FAIL 而非中断整个套件 */
    public function guard(string $case, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->register($case, false, get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** 断言严格相等 */
    public function assertSame(string $case, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        $this->register(
            $case,
            $ok,
            $ok ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }

    /** 断言为真 */
    public function assertTrue(string $case, bool $cond, string $info = ''): void
    {
        $this->register($case, $cond, $info);
    }

    /** 数据库是否可用（不抛异常） */
    public static function dbAvailable(): bool
    {
        try {
            \Core\Database::fetch('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 输出汇总并返回退出码 */
    public function finish(): int
    {
        echo "\n";
        echo sprintf(
            "==== %d PASS / %d FAIL / %d SKIP ====\n",
            $this->passed,
            $this->failed,
            count($this->skips)
        );
        return $this->failed > 0 ? 1 : 0;
    }
}
