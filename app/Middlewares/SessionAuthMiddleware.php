<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\Admin;
use Core\Middleware;
use Core\Request;
use Core\Response;

/**
 * 会话鉴权中间件 —— 本项目最核心的安全组件（修复旧系统 P0-1 / P0-2）
 *
 * 旧系统问题：
 *   P0-1 管理后台 87 个写操作方法中仅 9 处调用了鉴权 → 越权可直接改题库/成绩/考生。
 *   P0-2 CSRF 校验在 token 为空时短路放行 → 等于没有防护。
 *
 * 本实现采用「默认拒绝」模型：
 *   1. 路由按前缀声明所需身份（见 IDENTITY_RULES），未命中规则一律拒绝。
 *   2. 管理后台路由额外声明「权限点」，命中即校验角色（config('rbac')）。
 *   3. 所有非幂等请求（POST/PUT/PATCH/DELETE）强制校验 CSRF token，
 *      且 token 为空视为失败（不再短路放行）。
 *
 * 三套登录态互不干扰，会话键前缀区分：
 *   admin   → $_SESSION['admin']   = ['id','username','admin_power']
 *   student → $_SESSION['student'] = ['id','stu_name']
 *   teacher → $_SESSION['teacher'] = ['id','tea_name']
 */
class SessionAuthMiddleware implements Middleware
{
    /**
     * 身份规则：[前缀, 身份标识, 权限点(可选)，'{' 表示从路径段提取条件]
     * 数组顺序即匹配顺序，先匹配到的生效。
     *
     * 身份标识：public(无需登录) / admin / student / teacher
     */
    private const IDENTITY_RULES = [
        // 公共：健康检查、前台门户、文档
        ['/api/health',          'public'],
        ['/api/public/',         'public'],
        ['/api/openapi.json',    'public'],
        ['/api/docs',            'public'],
        ['/api/metrics',         'public'],

        // 考生端登录/注册/登出（未登录可访问，但登出后 /api/student/me 需登录）
        ['/api/student/register', 'public'],
        ['/api/student/login',    'public'],
        ['/api/student/logout',   'public'],

        // 考场入口（考生在考场内自行以准考证号 + 考场口令登录）
        ['/api/exam/login',       'public'],
        ['/api/exam/logout',      'public'],

        // 考生端
        ['/api/student/',         'student'],
        // 考场内部接口：使用独立的「考试会话」（exam_id + stu_id），
        // 由 ExamController::login 建立，与个人中心登录态互不影响
        ['/api/exam/',            'exam'],
        ['/api/exercise/',        'student'],
        ['/api/exercise',         'student'],

        // 教师端登录
        ['/api/teacher/login',    'public'],
        ['/api/teacher/logout',   'public'],
        ['/api/teacher/',         'teacher'],

        // 管理后台登录
        ['/api/admin/login',      'public'],
        ['/api/admin/logout',     'public'],

        // 管理后台 —— 带权限点（更长的前缀优先匹配）
        ['/api/admin/admins',     'admin', 'admin.manage'],
        ['/api/admin/system',     'admin', 'system.manage'],
        ['/api/admin/config',     'admin', 'system.config'],
        ['/api/admin/dashboard',  'admin', 'dashboard.view'],

        ['/api/admin/subjects',   'admin', 'subject.view'],
        ['/api/admin/exam-categories', 'admin', 'category.view'],
        ['/api/admin/exams',      'admin', 'exam.view'],
        ['/api/admin/quizzes',    'admin', 'quiz.view'],
        ['/api/admin/students',   'admin', 'student.view'],
        ['/api/admin/teachers',   'admin', 'teacher.view'],
        ['/api/admin/grades',     'admin', 'grade.view'],
        ['/api/admin/classes',    'admin', 'class.view'],
        ['/api/admin/monitor',    'admin', 'monitor.view'],
        ['/api/admin/scores',     'admin', 'score.view'],
        ['/api/admin/news',       'admin', 'news.view'],
        ['/api/admin/upload',     'admin', 'quiz.add'],

        // 兜底：其余 /api/admin/** 一律要求管理员登录
        ['/api/admin/',           'admin'],
    ];

    /**
     * 写操作 → 所需权限点（仅对管理后台生效）。
     * 键为 "METHOD 路由模式"，值可用 '*' 表示继承前缀只读权限点。
     * 未列出的写操作按前缀权限点的「写变体」推导（见 writePoint()）。
     */
    private const WRITE_POINTS = [
        'POST /api/admin/quizzes/batch-delete'  => 'quiz.delete',
        'POST /api/admin/quizzes/clean'         => 'quiz.clean',
        'POST /api/admin/quizzes/advanced-clean'=> 'quiz.clean',
        'GET /api/admin/quizzes/clean/preview'  => 'quiz.clean',
        'POST /api/admin/exams/{id}/start'      => 'exam.start',
        'POST /api/admin/exams/{id}/generate'   => 'exam.generate',
        'POST /api/admin/monitor/lock'          => 'monitor.control',
        'POST /api/admin/monitor/unlock'        => 'monitor.control',
        'POST /api/admin/monitor/submit'        => 'monitor.control',
        'POST /api/admin/monitor/lock-all'      => 'monitor.control',
        'POST /api/admin/monitor/unlock-all'    => 'monitor.control',
        'POST /api/admin/monitor/over-all'      => 'monitor.control',
        'POST /api/admin/scores/backup'         => 'score.backup',
        'GET /api/admin/scores/export'          => 'score.export',
        'POST /api/admin/students/import'       => 'student.import',
    ];

    /** 只读权限点 → 写入权限点前缀映射（quiz.view → quiz） */
    private const POINT_MODULE = [
        'dashboard.view' => 'dashboard',
        'subject.view'   => 'subject',
        'category.view'  => 'category',
        'exam.view'      => 'exam',
        'quiz.view'      => 'quiz',
        'student.view'   => 'student',
        'teacher.view'   => 'teacher',
        'grade.view'     => 'grade',
        'class.view'     => 'class',
        'monitor.view'   => 'monitor',
        'score.view'     => 'score',
        'news.view'      => 'news',
        'admin.manage'   => 'admin',
        'system.manage'  => 'system',
        'system.config'  => 'system',
    ];

    /** 免 CSRF 校验的方法（幂等/只读） */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, callable $next): Response
    {
        $path = $request->path();
        $method = strtoupper($request->method());

        [$identity, $point] = $this->resolveRule($path);

        // ---- 1. 公开端点：直接放行（但登录接口仍需 CSRF 保护） ----
        if ($identity === 'public') {
            $csrf = $this->checkCsrf($request, $method, $path);
            return $csrf ?? $next($request);
        }

        // ---- 2. 未命中任何规则 → 默认拒绝（防止新增路由漏配鉴权） ----
        if ($identity === null) {
            if (str_starts_with($path, '/api/')) {
                return $this->deny(401, '接口未开放或鉴权未配置');
            }
            return $next($request); // 非 API 路径（静态资源等）交给后续处理
        }

        // ---- 3. 登录态校验 ----
        $session = $this->currentSession($identity);
        if ($session === null) {
            return $this->deny(401, '登录已过期，请重新登录');
        }

        // ---- 4. CSRF 校验（非幂等方法） ----
        if (($csrf = $this->checkCsrf($request, $method, $path)) !== null) {
            return $csrf;
        }

        // ---- 5. 权限点校验（仅管理后台） ----
        if ($identity === 'admin') {
            $required = $point !== null
                ? $this->writePoint($method, $path, $point)
                : null;
            if ($required !== null && !$this->can($session['admin_power'] ?? '', $required)) {
                return $this->deny(403, '无权限执行该操作');
            }
        }

        // 将当前身份挂到全局，供控制器读取（避免控制器各自解析会话）
        $GLOBALS['__identity'] = $identity;
        $GLOBALS['__auth'] = $session;

        return $next($request);
    }

    /* ------------------------------------------------------------------ */
    /* 规则解析                                                            */
    /* ------------------------------------------------------------------ */

    /** 返回 [identity|null, point|null] */
    private function resolveRule(string $path): array
    {
        foreach (self::IDENTITY_RULES as $rule) {
            if (str_starts_with($path, $rule[0])) {
                return [$rule[1], $rule[2] ?? null];
            }
        }
        return [null, null];
    }

    /**
     * 推导写操作所需权限点：
     *   显式配置优先 → 否则由读权限点模块推导写变体（如 exam.view + POST → exam.add）
     */
    private function writePoint(string $method, string $path, string $readPoint): ?string
    {
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $readPoint;
        }
        $pattern = \Core\App::instance()->router()->match($method, $path) ?? $path;
        $explicit = self::WRITE_POINTS[$method . ' ' . $pattern] ?? null;
        if ($explicit !== null) {
            return $explicit;
        }
        $module = self::POINT_MODULE[$readPoint] ?? null;
        if ($module === null) {
            return $readPoint;
        }
        return match ($method) {
            'POST'          => $module . '.add',
            'PUT', 'PATCH'  => $module . '.edit',
            'DELETE'        => $module . '.delete',
            default         => $readPoint,
        };
    }

    /** 角色是否拥有权限点（'*' 为超级权限） */
    private function can(string $role, string $point): bool
    {
        $granted = (array) (config('rbac.' . $role) ?? []);
        if ($granted === []) {
            return false; // 未知角色一律无权限
        }
        if (in_array('*', $granted, true)) {
            return true;
        }
        if (in_array($point, $granted, true)) {
            return true;
        }
        // 模块级通配授权：'exam.*' 覆盖 'exam.add'
        $module = explode('.', $point)[0];
        return in_array($module . '.*', $granted, true);
    }

    /* ------------------------------------------------------------------ */
    /* 会话与 CSRF                                                         */
    /* ------------------------------------------------------------------ */

    /** 读取当前身份会话；未登录返回 null */
    private function currentSession(string $identity): ?array
    {
        // [会话键, 必须存在的字段]
        $map = match ($identity) {
            'admin'   => ['admin', 'id'],
            'student' => ['student', 'id'],
            'teacher' => ['teacher', 'id'],
            // 考场会话独立存储，由 ExamController 维护
            'exam'    => ['exam_session', 'exam_id'],
            default   => null,
        };
        if ($map === null) {
            return null;
        }
        [$key, $idField] = $map;
        $sess = sess_get($key);
        return is_array($sess) && isset($sess[$idField]) ? $sess : null;
    }

    /**
     * CSRF 校验：只对「已登录会话」的非幂等方法生效。
     * 修复 P0-2：token 缺失或为空一律拒绝，不再短路放行。
     * 采用双提交 Cookie 模式：登录时下发 csrf_token（非 HttpOnly），
     * 前端读 Cookie 后放入 X-CSRF-Token 请求头。
     */
    private function checkCsrf(Request $request, string $method, string $path): ?Response
    {
        if (in_array($method, self::SAFE_METHODS, true)) {
            return null;
        }
        // 登录接口本身不需要 token（此时尚无会话），改由限流 + 凭据校验防护
        if (str_ends_with($path, '/login')) {
            return null;
        }
        // 无会话时不做 CSRF 校验，交给后续 401（避免把未登录请求报成 CSRF 失败）
        if ($this->anySession() === null) {
            return null;
        }

        $expected = (string) sess_get('csrf_token', '');
        if ($expected === '') {
            return $this->deny(419, '会话缺少安全令牌，请重新登录');
        }
        $provided = (string) ($request->header('X-CSRF-Token') ?? $request->input('_csrf', ''));
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return $this->deny(419, '安全令牌校验失败，请刷新页面后重试');
        }
        return null;
    }

    /** 是否存在任一登录态 */
    private function anySession(): ?array
    {
        foreach ([['admin', 'id'], ['student', 'id'], ['teacher', 'id'], ['exam_session', 'exam_id']] as [$key, $idField]) {
            $sess = sess_get($key);
            if (is_array($sess) && isset($sess[$idField])) {
                return $sess;
            }
        }
        return null;
    }

    private function deny(int $status, string $message): Response
    {
        $codes = [401 => 40100, 403 => 40300, 419 => 41900];
        return (new Response())->error($codes[$status] ?? 40000, $message, $status);
    }
}
