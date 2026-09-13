<?php

declare(strict_types=1);

namespace Core;

/**
 * 极简连接池（PHP-FPM 下用 PDO::ATTR_PERSISTENT 已足够；主要用于 Swoole 常驻进程）
 *
 * - 惰性创建连接，acquire 优先复用空闲，超过上限时抛出明确异常
 * - 不校验连接健康度（由上层 2006 重连兜底）
 * - 单线程使用场景下 acquire/release 成对即可；Swoole 每个请求结束调用 release 归还
 */
final class ConnectionPool
{
    /** @var list<mixed> 空闲连接 */
    private array $idle = [];
    private int $size = 0;

    public function __construct(private readonly \Closure $factory, private readonly int $max = 8)
    {
    }

    /** 取一个连接：优先复用空闲；否则新建（未超上限）；已达上限则抛出异常 */
    public function acquire(): mixed
    {
        if ($this->idle !== []) {
            return array_pop($this->idle);
        }
        if ($this->size >= $this->max) {
            throw new \RuntimeException('连接池已达上限 (' . $this->max . ')，无空闲连接');
        }
        $this->size++;
        return ($this->factory)();
    }

    /** 归还连接到空闲列表 */
    public function release(mixed $conn): void
    {
        $this->idle[] = $conn;
    }

    /** 丢弃一个在用的失效连接：回收其占用的槽位（供 MySQL 2006/2013 重连丢弃死连接时调用，防止池槽位泄漏耗尽） */
    public function discard(int $count = 1): void
    {
        $this->size = max(0, $this->size - $count);
    }

    /** 空闲连接数 */
    public function idle(): int
    {
        return count($this->idle);
    }

    /** 已创建连接总数（空闲 + 在用） */
    public function size(): int
    {
        return $this->size;
    }

    /** 清理全部空闲连接（调用方可关闭底层资源） */
    public function clear(callable $destroy): void
    {
        while ($this->idle !== []) {
            $destroy(array_pop($this->idle));
        }
        $this->size = 0;
    }
}
