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
    protected function page(int $defaultPerPage = 20, int $maxPerPage = 100): array
    {
        $page = max(1, (int) $this->request->query('page', 1));
        $perPage = (int) $this->request->query('per_page', $defaultPerPage);
        $perPage = min($maxPerPage, max(1, $perPage));
        return [
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
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

    /** 分页结果包装（与前端约定的统一结构） */
    protected function ok(mixed $data = null, string $message = 'ok'): Response
    {
        return $this->response->success($data, $message);
    }
}
