<?php
declare(strict_types=1);

/**
 * 共享测试基座（零依赖）
 *
 * 解决三件事，让所有套件行为一致、绝不中途崩溃：
 *  1. 结果收集与统一汇报（register / report / exitCode）
 *  2. 数据库可用性探测（dbAvailable），供套件决定是否跳过 DB 用例
 *  3. 安全执行包裹（guard）：把「套件级」代码包进 try/catch，
 *     任何未预期异常都被记录为一条 FAIL，而不是让整个套件以原始 500 中止
 *
 * 用法：
 *   require __DIR__ . '/lib/harness.php';
 *   $t = new TestHarness('套件名');
 *   $t->register('用例名', $ok, '可选上下文');
 *   $t->guard('DB 相关用例', function () use ($t) { ... });
 *   exit($t->finish());
 */

final class TestHarness
{
    /** @var array<string, array{0: bool, 1: string}> */
    private array $results = [];
    private string $name;
    private int $skipped = 0;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /** 记录一条用例结果 */
    public function register(string $case, bool $ok, string $info = ''): void
    {
        // 同名用例重复注册时追加序号，避免互相覆盖掩盖失败
        if (isset($this->results[$case])) {
            $i = 2;
            while (isset($this->results[$case . ' #' . $i])) {
                $i++;
            }
            $case .= ' #' . $i;
        }
        $this->results[$case] = [$ok, $info];
    }

    /** 标记一条跳过（不计入失败） */
    public function skip(string $case, string $reason = ''): void
    {
        $this->skipped++;
        $this->results[$case] = [true, 'SKIP' . ($reason !== '' ? ': ' . $reason : '')];
    }

    /**
     * 安全执行一段套件级代码：其中抛出的任何 Throwable 都会被记录为一条 FAIL，
     * 而不是让整个测试进程带着未捕获异常退出。
     */
    public function guard(string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->register(
                $label . '（未预期异常）',
                false,
                get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
            );
        }
    }

    /** 数据库是否可用（失败原因一并返回，便于排查） */
    public static function dbAvailable(): bool
    {
        try {
            \Core\Database::query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 打印结果并返回退出码（0 = 全通过） */
    public function finish(): int
    {
        $failed = 0;
        echo "=== {$this->name} ===\n";
        foreach ($this->results as $case => [$ok, $info]) {
            if (!$ok) {
                $failed++;
            }
            printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $case, $info !== '' ? " | {$info}" : '');
        }
        $total = count($this->results);
        $pass = $total - $failed;
        echo "=== 完成 ({$pass}/{$total} 通过" . ($this->skipped > 0 ? ", {$this->skipped} 跳过" : '') . ", {$failed} 失败) ===\n";
        return $failed > 0 ? 1 : 0;
    }
}
