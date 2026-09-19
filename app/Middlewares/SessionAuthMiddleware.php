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
     * 精确匹配规则（先于前缀规则判定）：路径 => 身份标识。
     *
     * 用于「会话探测接口」（各端 AuthController::me）。这类接口的正确语义是
     * 「未登录就如实回答 logged_in:false」——控制器里也确实这么实现了，返回 200。
     * 但它们的路径同时又落在受保护前缀之下：
     *     /api/student/me  ⊂  /api/student/
     *     /api/teacher/me  ⊂  /api/teacher/
     *     /api/admin/me    ⊂  /api/admin/
     * 若只按前缀匹配，请求会在中间件层就被拦成 401「登录已过期，请重新登录」，
     * 控制器里那段 anonymous 分支变成死代码。后果有两点：
     *   1. 公开页面（门户首页 / 注册页 / 英雄页）一打开，浏览器控制台就有一条
     *      红色 401 报错，看起来像故障；实际只是「访客尚未登录」这一正常事实。
     *   2. 前端 http.js 的 401 全局跳转虽已用路径白名单豁免了 me 接口，但登录态
     *      恢复只能靠抛异常走到 catch 分支，永远取不到 csrf_token。
     *
     * 这里用**精确路径**而不是再写一条前缀规则，是为了避免将来出现
     * /api/student/members 这类新路径被 '/api/student/me' 前缀误放行。
     *
     * 安全性：三者均为只读 GET，只会返回「调用者自己的会话」；未登录时返回
     * logged_in:false，不泄露任何数据；csrf_token 与 /api/health 同源下发，
     * 不新增暴露面。
     */
    private const EXACT_RULES = [
        '/api/student/me'   => 'public',
        '/api/teacher/me'   => 'public',
        '/api/admin/me'     => 'public',
        // 考场状态查询：与 /me 同构，未入场如实回答 phase:null（见 ExamController::status）。
        // 注意它同样落在受保护前缀 /api/exam/ 之下，必须显式放行才不会被拦成 401。
        '/api/exam/status'  => 'public',
    ];

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

        // 考生端登录/注册/登出（未登录可访问；/api/student/me 见 EXACT_RULES）
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
        ['/api/admin/settings',   'admin', 'system.config'],
        ['/api/admin/dashboard',  'admin', 'dashboard.view'],

        ['/api/admin/subjects',   'admin', 'subject.view'],
        ['/api/admin/exam-categories', 'admin', 'category.view'],
        ['/api/admin/exams',      'admin', 'exam.view'],
        ['/api/admin/quizzes',    'admin', 'quiz.view'],
        // A3 手动选题/知识点检索：只读题库，按题库读权限点授权。
        // 路径是 /api/admin/quiz-search（单数），不会命中上面的 quizzes 前缀，
        // 若不显式登记会落到 admin.access 兜底（任何管理员可读，语义过宽）。
        ['/api/admin/quiz-search', 'admin', 'quiz.view'],
        ['/api/admin/quiz-kps',    'admin', 'quiz.view'],
        ['/api/admin/students',   'admin', 'student.view'],
        ['/api/admin/teachers',   'admin', 'teacher.view'],
        ['/api/admin/grades',     'admin', 'grade.view'],
        ['/api/admin/classes',    'admin', 'class.view'],
        ['/api/admin/monitor',    'admin', 'monitor.view'],
        ['/api/admin/scores',     'admin', 'score.view'],
        ['/api/admin/news',       'admin', 'news.view'],
        ['/api/admin/upload',     'admin', 'quiz.add'],

        // 兜底：其余 /api/admin/** 要求管理员登录。
        // 必须带上最小权限点 admin.access（各内置角色均已授予）：此前这里没有权限点，
        // 中间件会跳过 can() 判断，任何新增却忘记登记规则的 /api/admin/xxx
        // 都会对全体已登录管理员无条件开放。
        ['/api/admin/',           'admin', 'admin.access'],
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
        // 批量导入是独立权限点（与 student.import 同构），便于「只让录题员从表格灌题」
        // 这类分工，而不必连带开放单题增删。不登记时 POST 会被推导成 quiz.add。
        'POST /api/admin/quizzes/import'        => 'quiz.import',
        'GET /api/admin/quizzes/clean/preview'  => 'quiz.clean',
        'POST /api/admin/exams/{id}/start'      => 'exam.start',
        'POST /api/admin/exams/{id}/generate'   => 'exam.generate',
        // 生成补考 = 新建一场正式考试（retake_of 指向源场次），权限语义是 exam.add。
        // 不显式登记时推导结果恰好也是 exam.add，但登记后语义自明，
        // 也避免日后把补考挪到别的模块时悄悄提权。
        'POST /api/admin/exams/{id}/retake'     => 'exam.add',
        // 「开放入场」只写 exam_pwd（生成考场口令，状态仍为未开考），是一次考试信息更新，
        // 而非新增考试。不显式登记时会被 writePoint() 推导成 exam.add，语义错位。
        'POST /api/admin/exams/{id}/open'       => 'exam.edit',
        'POST /api/admin/monitor/lock'          => 'monitor.control',
        'POST /api/admin/monitor/unlock'        => 'monitor.control',
        'POST /api/admin/monitor/submit'        => 'monitor.control',
        // 单个考生强制交卷与全员收卷同属监考控制动作，必须同样走 monitor.control。
        // 此前漏登记，被推导成 monitor.add（POST → 模块 .add）：testAdmin 只被授予
        // monitor.view + monitor.control，点行内「收卷」会 403，而「全部收卷」正常，
        // 形成「能收全场、收不了单人」的怪象。
        'POST /api/admin/monitor/submit-one'    => 'monitor.control',
        'POST /api/admin/monitor/lock-all'      => 'monitor.control',
        'POST /api/admin/monitor/unlock-all'    => 'monitor.control',
        'POST /api/admin/monitor/over-all'      => 'monitor.control',
        'POST /api/admin/scores/backup'         => 'score.backup',
        'GET /api/admin/scores/export'          => 'score.export',
        'POST /api/admin/students/import'       => 'student.import',
        'PUT /api/admin/config'                 => 'system.config',
        'PUT /api/admin/settings'               => 'system.config',
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
        // 精确规则优先：会话探测接口的路径是受保护前缀的子串，必须先判定
        if (isset(self::EXACT_RULES[$path])) {
            return [self::EXACT_RULES[$path], null];
        }
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
        $pattern = \Core\App::instance()->router()->match($method, $path) ?? $path;
        // 显式声明优先，且必须放在 SAFE_METHODS 短路**之前**：
        // 否则 GET /api/admin/scores/export 这类「读方法但属敏感操作」的
        // 显式权限点永远读不到，只被授予 score.view 的角色就能导出含考场口令的 CSV。
        $explicit = self::WRITE_POINTS[$method . ' ' . $pattern] ?? null;
        if ($explicit !== null) {
            return $explicit;
        }
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $readPoint;
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
