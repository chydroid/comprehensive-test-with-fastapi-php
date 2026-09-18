<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\HttpException;
use Core\Response;

/**
 * 业务控制器基类：在框架 Controller 之上补充本项目通用能力
 *
 * - identity()/authAdmin()/authStudent()/authTeacher()：读取当前登录态
 * - page()：统一解析分页/sort/关键词参数
 * - ok()/created()/noContent()：统一响应速记
 * - idParam()：安全解析路径参数为整数
 */
abstract class BaseController extends \Core\Controller
{
    /** 当前身份：admin / student / teacher / null */
    protected function identity(): ?string
    {
        $v = $GLOBALS['__identity'] ?? null;
        return is_string($v) ? $v : null;
    }

    /** 当前登录的管理员会话（未登录抛 401） */
    protected function authAdmin(): array
    {
        $s = sess_get('admin');
        if (!is_array($s) || !isset($s['id'])) {
            throw new HttpException(401, '登录已过期，请重新登录', 40100);
        }
        return $s;
    }

    protected function authStudent(): array
    {
        $s = sess_get('student');
        if (!is_array($s) || !isset($s['id'])) {
            throw new HttpException(401, '登录已过期，请重新登录', 40100);
        }
        return $s;
    }

    protected function authTeacher(): array
    {
        $s = sess_get('teacher');
        if (!is_array($s) || !isset($s['id'])) {
            throw new HttpException(401, '登录已过期，请重新登录', 40100);
        }
        return $s;
    }

    /**
     * 统一分页参数解析
     * @return array{page:int, per_page:int, offset:int, keyword:string}
     */
    /**
     * 解析分页参数。
     *
     * @param int|null $defaultPerPage 未指定时取后台「系统设置 → 列表默认每页条数」
     * @param int      $maxPerPage     单页上限，防止客户端请求过大分页拖垮数据库
     */
    protected function page(?int $defaultPerPage = null, int $maxPerPage = 100): array
    {
        $defaultPerPage ??= \App\Services\Setting::int('page_size_default', 20);
        $defaultPerPage = min($maxPerPage, max(1, $defaultPerPage));
        // page 必须有上限：此前只做 max(1, …)，传 1e23 时 (int) 得到 PHP_INT_MAX，
        // 再乘 perPage 会溢出成浮点数，SQL 变成 `OFFSET 9.22E+20` 直接语法错误 500；
        // 合法的大值也会造成深分页全表扫描。
        $page = min(1_000_000, max(1, (int) $this->request->query('page', 1)));
        $perPage = (int) $this->request->query('per_page', $defaultPerPage);
        $perPage = min($maxPerPage, max(1, $perPage));
        return [
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => (int) (($page - 1) * $perPage),
            'keyword'  => trim((string) $this->request->query('keyword', '')),
        ];
    }

    /** 安全整数 id 参数（路由 {id}） */
    protected function idParam(string $key = 'id'): int
    {
        $raw = (string) $this->request->param($key, '');
        if ($raw === '' || !ctype_digit($raw)) {
            throw new HttpException(400, '参数 id 必须是正整数', 40000);
        }
        return (int) $raw;
    }

    /**
     * 构造 LIKE 的匹配串，并转义通配符。
     *
     * 直接把用户输入拼成 '%kw%' 时，输入 `%` 或 `_` 会被当成通配符，
     * 使筛选退化为全表匹配（可绕过筛选、放大深分页成本）。
     * MySQL 默认转义符为 `\`，故对 % _ \ 三个字符加反斜杠。
     */
    protected static function like(string $keyword): string
    {
        return '%' . addcslashes($keyword, '%_\\') . '%';
    }

    /**
     * 校验并归一化「准考证号」。
     *
     * stuinfo.id 是 INT(11) 主键，准考证号即该主键。仅用 `regex:/^\d{1,20}$/`
     * 放行会把 11 位以上的数字交给 MySQL，触发 22003 Out of range → 500
     * （自助注册的旧实现还会因非事务性残留一条孤儿考生行）。
     * 这里统一收敛为 1..2147483647。
     */
    protected function normalizeStuId(mixed $raw): string
    {
        $id = trim((string) $raw);
        if ($id === '' || !ctype_digit($id)) {
            throw new HttpException(400, '准考证号必须是纯数字', 40000);
        }
        if ($id[0] === '0' || (int) $id > 2147483647) {
            throw new HttpException(400, '准考证号需在 1–2147483647 之间', 40002);
        }
        return $id;
    }

    /** 分页结果包装（与前端约定的统一结构） */
    protected function ok(mixed $data = null, string $message = 'ok'): Response
    {
        return $this->response->success($data, $message);
    }

    /**
     * 记录一条审计日志（旁路，永不打断业务）。
     * 详见 App\Services\Audit。控制器在「关键写操作成功后」调用即可。
     * @param string $action 动作类型，如 'exam.create'
     * @param string $target 操作对象，如 'exam:123'
     * @param array  $detail 结构化详情
     */
    protected function audit(string $action, string $target = '', array $detail = []): void
    {
        \App\Services\Audit::log($action, $target, $detail);
    }
}
