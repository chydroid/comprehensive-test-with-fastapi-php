<?php
declare(strict_types=1);

namespace Core;

use PDO;

/**
 * 数据库连接（PDO 单例）
 * 全部查询使用预处理语句，杜绝 SQL 注入
 *
 * 读写分离：配置只读库（config('database.read')）后，
 * 纯 SELECT（且非事务内、非 FOR UPDATE）自动走只读连接，其余（INSERT/UPDATE/DELETE/DDL）走主库。
 */
class Database
{
    private static ?PDO $pdo = null;
    private static ?PDO $readPdo = null;
    private static ?ConnectionPool $pool = null;
    private static ?ConnectionPool $readPool = null;
    /** @var array<string, list<string>> 表列名缓存 */
    private static array $columnCache = [];
    /** 嵌套事务 savepoint 深度（0 = 无嵌套） */
    private static int $savepointDepth = 0;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $db = config('database');
            if (self::poolEnabled()) {
                self::$pdo = self::pool()->acquire();
            } else {
                self::$pdo = self::newPdo($db['host'], (int) $db['port'], $db['name'], $db['user'], $db['pass'], (bool) ($db['persistent'] ?? false));
            }
        }
        return self::$pdo;
    }

    /** 只读连接；未配置只读库时回退主库（保证向后兼容） */
    public static function readConnection(): PDO
    {
        $read = config('database')['read'] ?? [];
        if (!($read['enabled'] ?? false) || empty($read['host'])) {
            return self::connection();
        }
        if (self::$readPdo === null) {
            if (self::poolEnabled()) {
                self::$readPdo = self::readPool()->acquire();
            } else {
                self::$readPdo = self::newPdo($read['host'], (int) ($read['port'] ?? 3306), $read['name'] ?? '', $read['user'] ?? '', $read['pass'] ?? '', (bool) ($read['persistent'] ?? false));
            }
        }
        return self::$readPdo;
    }

    /** 归还当前请求占用的连接到池（Swoole 每个请求结束调用）；池未启用时为空操作 */
    public static function release(): void
    {
        if (!self::poolEnabled()) {
            return;
        }
        // 归还前重置进程级事务深度，防止异常路径残留的 savepoint 计数串扰下一个请求
        self::$savepointDepth = 0;
        if (self::$pdo !== null) {
            // 若连接仍处于未提交事务中（异常路径遗漏回滚），先回滚再归还，避免脏数据串扰
            try {
                if (self::$pdo->inTransaction()) {
                    self::$pdo->rollBack();
                }
            } catch (\Throwable $e) {
                // 连接已失效，直接丢弃重建即可
            }
            self::pool()->release(self::$pdo);
            self::$pdo = null;
        }
        if (self::$readPdo !== null && self::$readPool !== null) {
            try {
                if (self::$readPdo->inTransaction()) {
                    self::$readPdo->rollBack();
                }
            } catch (\Throwable $e) {
                // 忽略
            }
            self::readPool()->release(self::$readPdo);
            self::$readPdo = null;
        }
    }

    private static function poolEnabled(): bool
    {
        return (bool) (config('database')['pool']['enabled'] ?? false);
    }

    private static function pool(): ConnectionPool
    {
        $db = config('database');
        $size = (int) ($db['pool']['size'] ?? 8);
        return self::$pool ??= new ConnectionPool(
            static fn (): PDO => self::newPdo($db['host'], (int) $db['port'], $db['name'], $db['user'], $db['pass'], (bool) ($db['persistent'] ?? false)),
            $size
        );
    }

    private static function readPool(): ConnectionPool
    {
        $read = config('database')['read'] ?? [];
        $size = (int) (config('database')['pool']['size'] ?? 8);
        return self::$readPool ??= new ConnectionPool(
            static fn (): PDO => self::newPdo($read['host'], (int) ($read['port'] ?? 3306), $read['name'] ?? '', $read['user'] ?? '', $read['pass'] ?? '', (bool) ($read['persistent'] ?? false)),
            $size
        );
    }

    private static function newPdo(string $host, int $port, string $name, string $user, string $pass, bool $persistent): PDO
    {
        $charset = config('database')['charset'] ?? 'utf8mb4';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        // PHP >= 8.5 用新命名空间常量；旧版本用 PDO::MYSQL_ATTR_FOUND_ROWS（否则 8.1-8.4 下类不存在）
        $foundRowsAttr = defined('Pdo\\Mysql::ATTR_FOUND_ROWS')
            ? \Pdo\Mysql::ATTR_FOUND_ROWS
            : \PDO::MYSQL_ATTR_FOUND_ROWS;
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // 持久连接：PHP-FPM 下跨请求复用底层连接，减少握手/认证开销（提升读多 API 性能）
            PDO::ATTR_PERSISTENT         => $persistent,
            // rowCount 按"命中的行"而非"实际改变的行"统计，
            // 使幂等 PUT（内容不变）也返回 true，避免误报"内容未变化"
            $foundRowsAttr => true,
        ]);
    }

    /** 是否纯读 SQL：仅 SELECT 且非行锁（FOR UPDATE / LOCK IN SHARE MODE），其余（含 DDL/DML）走主库 */
    public static function isReadSql(string $sql): bool
    {
        $trimmed = ltrim($sql);
        if (stripos($trimmed, 'SELECT') !== 0) {
            return false;
        }
        return stripos($trimmed, 'FOR UPDATE') === false
            && stripos($trimmed, 'LOCK IN SHARE MODE') === false;
    }

    /** 是否应走只读库：纯读 + 未在事务 + 已配置只读库 */
    private static function shouldRead(string $sql): bool
    {
        $read = config('database')['read'] ?? [];
        if (!($read['enabled'] ?? false) || empty($read['host'])) {
            return false;
        }
        if (!self::isReadSql($sql)) {
            return false;
        }
        // 事务内必须走主库，保证读到本事务的未提交写入
        return !(self::$pdo !== null && self::$pdo->inTransaction());
    }

    /** 执行预处理语句，返回 PDOStatement */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        return self::prepareAndExecute($sql, $params);
    }

    /**
     * 预处理并执行；按读写分离选择连接；MySQL 连接被服务端断开（wait_timeout/重启，SQLSTATE 2006/2013）
     * 时自动重连并重试一次，避免长驻进程偶发 500
     */
    private static function prepareAndExecute(string $sql, array $params = []): \PDOStatement
    {
        $useRead = self::shouldRead($sql);
        try {
            $pdo = $useRead ? self::readConnection() : self::connection();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            // 仅在不处于事务时自动重连：事务中断线重连会让重试语句跑在事务外，破坏原子性
            $inTransaction = self::$pdo !== null && self::$pdo->inTransaction();
            if (in_array((string) $e->getCode(), ['2006', '2013'], true) && !$inTransaction) {
                if ($useRead) {
                    // 池模式下丢弃死连接需回收其槽位，防止池槽位永久泄漏后耗尽
                    if (self::poolEnabled()) {
                        self::readPool()->discard();
                    }
                    self::$readPdo = null; // 丢弃失效只读连接
                } else {
                    if (self::poolEnabled()) {
                        self::pool()->discard();
                    }
                    self::$pdo = null;     // 丢弃失效主库连接
                }
                $pdo = $useRead ? self::readConnection() : self::connection();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    /** 获取表的所有列名（进程内缓存，供 Model 时间戳自动维护等使用） */
    public static function columns(string $table): array
    {
        // 表名必须是合法标识符，防止拼入 SHOW COLUMNS 的注入
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }
        if (!isset(self::$columnCache[$table])) {
            $rows = self::query("SHOW COLUMNS FROM `$table`")->fetchAll();
            self::$columnCache[$table] = array_map(
                static fn (array $r): string => (string) ($r['Field'] ?? ''),
                $rows
            );
        }
        return self::$columnCache[$table];
    }

    /** 查询单行，无结果返回 null */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** 查询多行 */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** 带缓存的多行查询：ttl<=0 时直接查库；适合读多写少的查询，缓存键=SQL+参数 */
    public static function fetchAllCached(string $sql, array $params = [], int $ttl = 0): array
    {
        if ($ttl <= 0) {
            return self::fetchAll($sql, $params);
        }
        $key = 'db:' . md5($sql . '|' . serialize($params));
        if (($data = Cache::get($key)) !== null) {
            return $data;
        }
        $rows = self::fetchAll($sql, $params);
        Cache::set($key, $rows, $ttl);
        return $rows;
    }

    /** 带缓存的单行查询：ttl<=0 时直接查库；命中 null（无行）时不缓存 */
    public static function fetchCached(string $sql, array $params = [], int $ttl = 0): ?array
    {
        if ($ttl <= 0) {
            return self::fetch($sql, $params);
        }
        $key = 'db:' . md5($sql . '|' . serialize($params));
        if (($data = Cache::get($key)) !== null) {
            return $data;
        }
        $row = self::fetch($sql, $params);
        if ($row !== null) {
            Cache::set($key, $row, $ttl);
        }
        return $row;
    }

    public static function lastInsertId(): int
    {
        return (int) self::connection()->lastInsertId();
    }

    /* ---- 事务 ---- */

    public static function beginTransaction(): void
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            // 嵌套事务：改用 savepoint，避免 PDO 抛 "already active transaction"
            self::$savepointDepth++;
            $pdo->exec('SAVEPOINT sp' . self::$savepointDepth);
        } else {
            $pdo->beginTransaction();
        }
    }

    public static function commit(): void
    {
        $pdo = self::connection();
        if (self::$savepointDepth > 0) {
            $pdo->exec('RELEASE SAVEPOINT sp' . self::$savepointDepth);
            self::$savepointDepth--;
        } else {
            $pdo->commit();
        }
    }

    public static function rollBack(): void
    {
        $pdo = self::connection();
        if (self::$savepointDepth > 0) {
            $pdo->exec('ROLLBACK TO SAVEPOINT sp' . self::$savepointDepth);
            $pdo->exec('RELEASE SAVEPOINT sp' . self::$savepointDepth);
            self::$savepointDepth--;
        } else {
            $pdo->rollBack();
        }
    }

    public static function inTransaction(): bool
    {
        return self::connection()->inTransaction();
    }
}
