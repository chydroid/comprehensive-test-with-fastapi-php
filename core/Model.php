<?php
declare(strict_types=1);

namespace Core;

/**
 * 基础模型：封装常见 CRUD 与分页
 * 子类声明 protected string $table（默认取类名小写复数）、$fillable 白名单
 * 表名/列名均来自代码常量并用反引号包裹，值全部走预处理，安全可控
 */
abstract class Model
{
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    /** 自动维护 created_at/updated_at（表存在对应列时生效） */
    protected bool $timestamps = true;

    public function table(): string
    {
        if ($this->table === '') {
            $this->table = strtolower((new \ReflectionClass($this))->getShortName()) . 's';
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException("非法表名: {$this->table}");
        }
        return $this->table;
    }

    /** 按主键查询单条 */
    public function find(int|string $id): ?array
    {
        return Database::fetch(
            "SELECT * FROM `{$this->table()}` WHERE `{$this->primaryKey}` = ? LIMIT 1",
            [$id]
        );
    }

    /** 全部记录 */
    public function all(string $orderBy = 'id ASC'): array
    {
        $order = $this->assertOrderBy($orderBy);
        return Database::fetchAll("SELECT * FROM `{$this->table()}` ORDER BY $order");
    }

    /** 等值条件查询：where(['status' => 1]) */
    public function where(array $conditions, string $orderBy = 'id DESC'): array
    {
        [$sql, $params] = $this->buildWhere($conditions);
        // 空条件时不输出 "WHERE "，避免生成非法 SQL
        $whereSql = $sql === '' ? '' : "WHERE $sql";
        $order = $this->assertOrderBy($orderBy);
        return Database::fetchAll(
            "SELECT * FROM `{$this->table()}` $whereSql ORDER BY $order",
            $params
        );
    }

    /** 等值条件取首行：firstWhere(['username' => $name])，无结果返回 null */
    public function firstWhere(array $conditions, string $orderBy = 'id DESC'): ?array
    {
        $rows = $this->where($conditions, $orderBy);
        return $rows[0] ?? null;
    }

    /** 分页：返回 list/total/page/per_page */
    public function paginate(int $page = 1, int $perPage = 20, array $conditions = []): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        [$where, $params] = $this->buildWhere($conditions);
        $whereSql = $where === '' ? '' : "WHERE $where";

        $total = (int) (Database::fetch(
            "SELECT COUNT(*) AS c FROM `{$this->table()}` $whereSql",
            $params
        )['c'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $list = Database::fetchAll(
            "SELECT * FROM `{$this->table()}` $whereSql
             ORDER BY `{$this->primaryKey}` DESC LIMIT $perPage OFFSET $offset",
            $params
        );

        return [
            'list'       => $list,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages'=> $total > 0 ? (int) ceil($total / $perPage) : 0,
            'has_more'   => $page * $perPage < $total,
        ];
    }

    /**
     * 游标（keyset）分页：避免深分页 OFFSET 全表扫描
     * 按主键降序（最新优先），用 next_cursor（末行主键值）作为下次请求的 $after
     * 返回 list / next_cursor（无更多页时为 null）/ has_more
     */
    public function paginateKeyset(int|string|null $after = null, int $perPage = 20, array $conditions = []): array
    {
        $perPage = min(100, max(1, $perPage));
        [$where, $params] = $this->buildWhere($conditions);
        $whereSql = $where === '' ? '' : "WHERE $where";
        $cursorSql = '';
        $allParams = $params;
        if ($after !== null) {
            // 游标条件放在等值条件之后；无 where 时以 WHERE 开头，否则 AND 衔接
            $cursorSql = $where === ''
                ? " WHERE `{$this->primaryKey}` < ?"
                : " AND `{$this->primaryKey}` < ?";
            $allParams = [...$params, $after];
        }

        // 多取 1 条以判断是否还有下一页
        $rows = Database::fetchAll(
            "SELECT * FROM `{$this->table()}` $whereSql$cursorSql
             ORDER BY `{$this->primaryKey}` DESC LIMIT " . ($perPage + 1),
            $allParams
        );

        $hasMore = count($rows) > $perPage;
        $list = $hasMore ? array_slice($rows, 0, $perPage) : $rows;
        $last = $list === [] ? null : (end($list)[$this->primaryKey] ?? null);

        return [
            'list'        => array_values($list),
            'next_cursor' => $hasMore ? $last : null,
            'has_more'    => $hasMore,
        ];
    }

    /** 事务包裹：回调抛异常自动回滚并重新抛出 */
    public static function transaction(callable $callback): mixed
    {
        Database::beginTransaction();
        try {
            $result = $callback();
            Database::commit();
            return $result;
        } catch (\Throwable $e) {
            if (Database::inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    /** 新增，返回自增主键 */
    public function create(array $data): int
    {
        $data = $this->filterFillable($data);
        $data = $this->fillTimestamps($data, true);
        if ($data === []) {
            // 空数据或字段全被 fillable 过滤 → 生成非法 INSERT，应明确报错
            throw new HttpException(400, '没有可写入的字段', 40000);
        }
        $columns = implode(',', array_map(static fn (string $c): string => "`$c`", array_keys($data)));
        $holders = implode(',', array_fill(0, count($data), '?'));
        Database::query(
            "INSERT INTO `{$this->table()}` ($columns) VALUES ($holders)",
            array_values($data)
        );
        return Database::lastInsertId();
    }

    /** 按主键更新，返回是否影响行 */
    public function update(int|string $id, array $data): bool
    {
        $data = $this->filterFillable($data);
        // 无可写字段时先于时间戳自动填充返回 false，让空 PUT 返回 400 而非静默触碰行
        if ($data === []) {
            return false;
        }
        $data = $this->fillTimestamps($data, false);
        $set = implode(',', array_map(static fn (string $c): string => "`$c` = ?", array_keys($data)));
        $params = [...array_values($data), $id];
        return Database::query(
            "UPDATE `{$this->table()}` SET $set WHERE `{$this->primaryKey}` = ?",
            $params
        )->rowCount() > 0;
    }

    /** 按主键删除，返回是否影响行 */
    public function delete(int|string $id): bool
    {
        return Database::query(
            "DELETE FROM `{$this->table()}` WHERE `{$this->primaryKey}` = ?",
            [$id]
        )->rowCount() > 0;
    }

    /** 构建等值条件 SQL 与参数（列名经白名单校验，值走预处理） */
    private function buildWhere(array $conditions): array
    {
        $clauses = [];
        $params = [];
        foreach ($conditions as $column => $value) {
            $this->assertColumn($column);
            $clauses[] = "`$column` = ?";
            $params[] = $value;
        }
        return [implode(' AND ', $clauses), $params];
    }

    /** 校验列名：仅允许字母数字下划线，防止 SQL 注入 */
    private function assertColumn(string $column): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException("非法字段名: {$column}");
        }
    }

    /** 校验排序子句：允许 "col" 或 "col ASC/DESC"，返回规范化结果 */
    private function assertOrderBy(string $orderBy): string
    {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+(ASC|DESC))?$/i', trim($orderBy), $m)) {
            throw new \InvalidArgumentException("非法排序字段: {$orderBy}");
        }
        return '`' . $m[1] . '`' . (isset($m[2]) ? ' ' . strtoupper($m[2]) : '');
    }

    /** 仅保留 fillable 白名单字段；白名单为空表示全部接受；所有列名走白名单校验（纵深防御） */
    private function filterFillable(array $data): array
    {
        $columns = $this->fillable === [] ? $data : array_intersect_key($data, array_flip($this->fillable));
        foreach (array_keys($columns) as $column) {
            $this->assertColumn((string) $column);
        }
        return $columns;
    }

    /** 自动填充时间戳：表存在 created_at/updated_at 列且调用方未提供时补当前时间 */
    private function fillTimestamps(array $data, bool $isCreate): array
    {
        if (!$this->timestamps) {
            return $data;
        }
        $columns = Database::columns($this->table());
        $now = date('Y-m-d H:i:s');
        if ($isCreate && in_array('created_at', $columns, true) && !isset($data['created_at'])) {
            $data['created_at'] = $now;
        }
        if (in_array('updated_at', $columns, true) && !isset($data['updated_at'])) {
            $data['updated_at'] = $now;
        }
        return $data;
    }
}
