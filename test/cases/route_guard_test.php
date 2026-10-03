<?php

declare(strict_types=1);

/**
 * 「归属校验 / 权限点登记」静态回归防线。
 *
 * 背景：本项目多轮审计发现的缺陷里，**最高频的一类根因是「守卫只打在某一个入口」**
 * —— 同一份业务约束在 A 方法有、B 方法漏，逐个 review 迟早还会漏：
 *
 *   BUG-258 教师端 update() 可把考试过户给任意教师
 *           BUG-241 已在 create()/retake() 强制 exam_tea=本人，唯独 update() 漏掉。
 *   BUG-259 GET /api/admin/logs 落 admin.access 兜底
 *           新增 GET 路由未登记 IDENTITY_RULES，兜底点四角色全有，审计日志全员可读。
 *
 * 与其依赖人 review，不如把它变成一条**会失败的断言**：
 *   1. 归属校验覆盖：教师端每条带 {id} 的路由，其处理方法体（或其调用的私有方法）
 *      必须出现归属校验调用；确属例外的必须登记在下方白名单并写明理由。
 *   2. 权限点登记覆盖：后台写操作必须显式登记 WRITE_POINTS，
 *      不允许靠 writePoint() 的推导分支蒙对。
 *
 * 判定用「反射读方法源码 + 是否含指定调用」而非运行时行为：
 * 后者需要真实登录态与数据，且越权常常「能通过」只是因为数据不凑巧。
 *
 * 注意：用闭包而非具名函数，避免与其它用例文件在同进程内重名冲突。
 */

require __DIR__ . '/../../core/helpers.php';

use Test\Harness;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';

bootstrap();

$t = new Harness();
echo "== 归属校验与权限点登记静态回归测试 ==\n";

/* ------------------------------------------------------------------ */
/* 0. 基础设施：读方法源码 / 递归展开方法体内的方法调用                */
/* ------------------------------------------------------------------ */

/**
 * 读一个类方法（含继承链上的 protected/private）的完整源码。
 * 用 ReflectionMethod 拿到文件与起止行，再回源文件取该段文本。
 */
$methodSource = static function (string $class, string $method): string {
    if (!class_exists($class) || !method_exists($class, $method)) {
        return '';
    }
    $ref = new \ReflectionMethod($class, $method);
    $file = $ref->getFileName();
    if ($file === false || !is_file($file)) {
        return '';
    }
    $lines = file($file) ?: [];
    // getStartLine()/getEndLine() 是 1-based，file() 返回 0-based
    $slice = array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1);
    return implode('', $slice);
};

/**
 * 收集源码中出现的 `$this->xxx(` 调用名（去重）。
 * 用于判断该方法是否把校验「委托」给了别的私有方法。
 */
$calledOnThis = static function (string $src): array {
    preg_match_all('/\$this->(\w+)\s*\(/', $src, $m);
    return array_values(array_unique($m[1] ?? []));
};

/**
 * 递归展开：给定「允许出现的校验方法名」集合，判断方法体（含其调用的本类私有方法）
 * 是否触达其中任意一个。
 *
 * 为什么要递归：TeacherMonitorController 的 9 个方法并不直接调 assertOwnExam()，
 * 而是统一先调 ownExamId()，由它在内部完成 assertOwnExam()。
 * 只看第一层会把这 9 条全部误判为「缺校验」→ 假失败。
 */
$reachesGuard = static function (string $class, string $method, array $guards, int $depth = 0) use (&$reachesGuard, $methodSource, $calledOnThis): bool {
    if ($depth > 3) {                       // 防意外自递归
        return false;
    }
    $src = $methodSource($class, $method);
    if ($src === '') {
        return false;
    }
    foreach ($calledOnThis($src) as $callee) {
        if (in_array($callee, $guards, true)) {
            return true;
        }
    }
    // 本层没直接命中：下钻一层，考察它委托的私有方法
    foreach ($calledOnThis($src) as $callee) {
        if (!method_exists($class, $callee)) {
            continue;
        }
        $ref = new \ReflectionMethod($class, $callee);
        if (!$ref->isPrivate() && !$ref->isProtected()) {
            continue;                       // 只下钻本类私有/受保护方法
        }
        if ($reachesGuard($class, $callee, $guards, $depth + 1)) {
            return true;
        }
    }
    return false;
};

/* ------------------------------------------------------------------ */
/* 1. 教师端 {id} 路由：必须做归属校验                                  */
/* ------------------------------------------------------------------ */

/** 教师端各控制器的归属校验方法名（不同控制器方法名不同，均列出） */
$guardNames = ['assertOwnExam', 'ownExamId'];

/**
 * 显式白名单：确属「不需要按 exam 归属」的路由，必须写明理由。
 * 新增豁免时强制要求填 reason —— 没有理由的豁免不允许存在。
 */
$ownershipExempt = [
    // 题库容量统计：只读 Exam::checkStock()，与具体考试无关；
    // 且同样的信息教师已能通过 quiz-search 间接获得，不构成越权。
    'TeacherExamController::checkQuizCount' => '只读题库容量统计，与具体考试无关，不泄露考试数据',
];

/** 从 config/routes.php 里取出教师端全部带 {id} 的路由 */
$teacherIdRoutes = static function (): array {
    $src = file_get_contents(dirname(__DIR__, 2) . '/config/routes.php') ?: '';
    $out = [];
    // 形如： ['GET',  '/api/teacher/exams/{id}/survey', [TeacherSurveyController::class, 'save']],
    preg_match_all(
        "/\['(GET|POST|PUT|DELETE|PATCH)',\s*'(\/api\/teacher\/[^']*\{id\}[^']*)',\s*\[(\w+)::class,\s*'(\w+)'\]/i",
        $src,
        $m,
        PREG_SET_ORDER
    );
    foreach ($m as $hit) {
        $out[] = [
            'method' => strtoupper($hit[1]),
            'path'   => $hit[2],
            'class'  => 'App\\Controllers\\' . $hit[3],
            'action' => $hit[4],
            'key'    => $hit[3] . '::' . $hit[4],
        ];
    }
    return $out;
};

$routes = $teacherIdRoutes();

$t->guard('路由表可解析出教师端 {id} 路由', function () use ($t, $routes) {
    // 少于 15 条说明正则与路由表脱节了（当前实际 21 条），宁可报错也不要静默放过
    $t->assertTrue(
        '教师端 {id} 路由全部识别（>=15）',
        count($routes) >= 15,
        '实际识别 ' . count($routes) . ' 条，若为 0 说明正则与 routes.php 格式脱节'
    );
});

/**
 * 【第 2 层守卫：强制归属回本人】—— 与上面的 assertOwnExam 是**两件不同的事**。
 *
 *   assertOwnExam()  回答「这条考试是不是我的」——读操作与越权拦截靠它。
 *   强制 exam_tea    回答「我会不会把考试送给别人」——写操作靠它。
 *
 * BUG-258 正是漏了后者：update() 已有 assertOwnExam（所以上面的断言照样通过），
 * 但没覆盖 exam_tea，而 exam_tea 在 Exam::$fillable 内、
 * collectParams() 又会原样带出该字段，于是 PUT 真的改写了归属。
 *
 * 因此这一层必须**单独**断言，否则第一层断言会给出「已覆盖」的假安全感。
 */
$teacherWriteMethods = static function (): array {
    $src = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/TeacherExamController.php') ?: '';
    // 仅这 3 个入口会创建/改写考试实体（其余方法只读或操作子表）
    $entry = ['save', 'update', 'retake'];
    $out = [];
    foreach ($entry as $m) {
        if (!method_exists(\App\Controllers\TeacherExamController::class, $m)) {
            continue;
        }
        $ref = new \ReflectionMethod(\App\Controllers\TeacherExamController::class, $m);
        $lines = file($ref->getFileName() ?: '') ?: [];
        $body = implode('', array_slice(
            $lines,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        ));
        $out[$m] = $body;
    }
    return $out;
};

$t->guard('教师端写入口强制归属回本人（第 2 层守卫）', function () use ($t, $teacherWriteMethods, $methodSource) {
    $bodies = $teacherWriteMethods();
    $t->assertSame('3 个写入口均已定位到源码', 3, count($bodies));

    foreach ($bodies as $m => $body) {
        // 判据：exam_tea 的值必须来自「本人 tea_name」。
        // 实测三种写法都要认（对应 save / update / retake 三个入口）：
        //   $data['exam_tea'] = (string)($sess['tea_name'] ?? '');
        //   $data['exam_tea'] = (string)($this->authTeacher()['tea_name'] ?? '');
        //   'exam_tea' => (string)($sess['tea_name'] ?? ''),        （数组传参给 ExamRetake::create）
        // 而 collectParams() 里那种「原样透传前端值」的写法必须不匹配：
        //   $data['exam_tea'] = trim((string) $ip('exam_tea', ''));
        // 用宽松通配而非逐字符匹配：键名带引号、=> 与 = 两种赋值、等号两侧空格都不固定。
        $re = '/exam_tea.{0,14}?(?:=>|=)\s*\(string\)\s*\(\s*[^;]{0,80}?tea_name/s';
        $hasForce = preg_match($re, $body) === 1;
        $t->assertTrue(
            '强制归属 exam_tea: TeacherExamController::' . $m . '()',
            $hasForce,
            '未找到「exam_tea 由本人 tea_name 赋值」的语句。'
            . '若该入口确实不应改归属（例如未来新增的只读方法），请勿放入本清单；'
            . '若确需豁免，必须在方法上方写明理由注释。参考 BUG-258。'
        );
    }
});

$t->guard('教师端 {id} 路由均做归属校验', function () use ($t, $routes, $guardNames, $ownershipExempt, $reachesGuard) {
    foreach ($routes as $r) {
        $key = $r['key'];
        if (isset($ownershipExempt[$key])) {
            // 豁免必须写理由（数据完整性自检）
            $t->assertTrue(
                '豁免项有理由: ' . $key,
                trim($ownershipExempt[$key]) !== '',
                '白名单条目不得为空理由'
            );
            continue;
        }
        $ok = $reachesGuard($r['class'], $r['action'], $guardNames);
        $t->assertTrue(
            '归属校验 ' . $r['method'] . ' ' . $r['path'] . ' -> ' . $r['action'] . '()',
            $ok,
            $ok ? '' : $r['class'] . '::' . $r['action'] . '() 内未出现 ' . implode('/', $guardNames)
                . '() 调用，且未委托给含这些调用的私有方法。'
                . '若确属例外，请登记到 $ownershipExempt 并写明理由（参考 BUG-258 教训）'
        );
    }
});

/* ------------------------------------------------------------------ */
/* 2. 后台 GET 路由：必须登记 IDENTITY_RULES                            */
/* ------------------------------------------------------------------ */

/**
 * 取中间件 IDENTITY_RULES 里已登记的**具体**前缀。
 * 未登记的 GET 路由会落到 ['/api/admin/', 'admin', 'admin.access'] 兜底，
 * 而该点四个内置角色全部持有 —— 等于新接口对所有管理员开放（BUG-259）。
 *
 * ⚠️ 必须把兜底前缀 '/api/admin/' 自身排除掉：它匹配一切 /api/admin/* 路径，
 * 若算作「已登记」，本断言会对**任何**未登记路由误报通过（假防线）。
 * 这里只认比兜底更具体的前缀（如 /api/admin/system、/api/admin/logs）。
 */
$identityPrefixes = static function (): array {
    $src = file_get_contents(dirname(__DIR__, 2) . '/app/Middlewares/SessionAuthMiddleware.php') ?: '';
    preg_match_all("/\['(\/api\/admin\/[^']*)',\s*'admin',\s*'([\w.]+)'\]/", $src, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $hit) {
        $pfx = $hit[1];
        // 排除兜底前缀：它只说明「未登记时的默认权限点」，不代表有显式规则
        if ($pfx === '/api/admin/' || $pfx === '/api/admin') {
            continue;
        }
        $out[$pfx] = $hit[2];
    }
    return $out;
};

/**
 * 后台 GET 路由的豁免白名单，**必须写明理由**。
 *
 * 仅列「刻意不走 IDENTITY_RULES」的路由；「已在 EXACT_RULES 另行处理」的那些由代码自动识别，
 * 不必在这里重复登记（重复登记反而会掩盖 EXACT_RULES 被误删的情况）。
 */
$getExempt = [
    // 仪表盘等聚合读由 /api/admin/dashboard 前缀覆盖，无需在此登记。
];

$adminGetRoutes = static function (): array {
    $src = file_get_contents(dirname(__DIR__, 2) . '/config/routes.php') ?: '';
    $out = [];
    preg_match_all(
        "/\['GET',\s*'(\/api\/admin\/[^']*)',\s*\[(\w+)::class,\s*'(\w+)'\]/i",
        $src,
        $m,
        PREG_SET_ORDER
    );
    foreach ($m as $hit) {
        $out[] = ['path' => $hit[1], 'class' => 'App\\Controllers\\Admin\\' . $hit[2], 'action' => $hit[3]];
    }
    return $out;
};

$prefixes = $identityPrefixes();

/**
 * EXACT_RULES 里显式登记的路径（优先于 IDENTITY_RULES 匹配）。
 * 例：/api/admin/me => 'public'（会话探测接口，未登录可调用、无副作用）。
 * 这类路由由 EXACT_RULES 承担鉴权，不要求再登记 IDENTITY_RULES 权限点。
 */
$exactRules = static function (): array {
    $src = file_get_contents(dirname(__DIR__, 2) . '/app/Middlewares/SessionAuthMiddleware.php') ?: '';
    // 形如：'/api/admin/me'     => 'public',
    preg_match_all("/'(\/api\/[^']*)'\s*=>\s*'([\w.]+)'/", $src, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $hit) {
        $out[$hit[1]] = $hit[2];
    }
    return $out;
};

$t->guard('后台 GET 路由均已登记 IDENTITY_RULES', function () use ($t, $adminGetRoutes, $prefixes, $getExempt, $exactRules) {
    $adminGetRoutes = $adminGetRoutes();
    $exact = $exactRules();
    $unregistered = [];
    foreach ($adminGetRoutes as $r) {
        $path = $r['path'];
        foreach ($getExempt as $ex) {
            if (str_starts_with($path, $ex)) {
                continue 2;
            }
        }
        // EXACT_RULES 优先：已显式登记（如会话探测 /api/admin/me => public）
        if (isset($exact[$path])) {
            continue;
        }
        // 命中任一已登记前缀（最长匹配语义与中间件一致：str_starts_with 逐条，取首条命中）
        $matched = false;
        foreach ($prefixes as $pfx => $point) {
            if (str_starts_with($path, $pfx)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $unregistered[] = $path . '  ->  ' . $r['class'] . '::' . $r['action'] . '()';
        }
    }
    $t->assertSame(
        '后台 GET 路由无「未登记 IDENTITY_RULES」项',
        '',
        implode("\n     ", $unregistered)
    );
});

/* ------------------------------------------------------------------ */
/* 3. 后台写操作：必须显式登记 WRITE_POINTS                             */
/* ------------------------------------------------------------------ */

/**
 * 「按兜底权限点走」正当豁免白名单，**必须写明理由**。
 *
 * 背景：writePoint() 对未显式登记的写操作会推导成 <模块>.add / .edit / .delete。
 * 下列接口按设计就该走 admin.access 或已在更早的 EXACT_RULES 里另行处理，
 * 推导出的权限点会**误拒**，故须显式豁免并留下依据。
 */
$writeExempt = [
    'POST /api/admin/login' =>
        '已在 EXACT_RULES 标为 public（登录本身不需权限点），走不到 writePoint 分支',
    'POST /api/admin/logout' =>
        '同上；登出对所有已登录角色开放',
    'POST /api/admin/profile/avatar' =>
        '本人改自己的头像，操作对象就是当前登录者，不涉及他人数据，无需业务权限点',
    'PUT /api/admin/profile/password' =>
        '本人改自己的密码，已校验旧密码；同 avatar',
];

$t->guard('后台写操作不靠 writePoint() 推导蒙对', function () use ($t, $prefixes, $writeExempt) {
    $src = file_get_contents(dirname(__DIR__, 2) . '/app/Middlewares/SessionAuthMiddleware.php') ?: '';

    // 已显式登记的 "METHOD /path" 键
    preg_match_all("/'((?:POST|PUT|DELETE|PATCH))\s+(\/api\/admin\/[^']*)'\s*=>/i", $src, $m, PREG_SET_ORDER);
    $registered = [];
    foreach ($m as $hit) {
        $registered[strtoupper($hit[1]) . ' ' . $hit[2]] = true;
    }

    // 落在 IDENTITY_RULES 显式前缀内的写操作按显式权限点走，不算推导蒙对
    $prefixCovered = static function (string $path) use ($prefixes): bool {
        foreach ($prefixes as $pfx => $point) {
            if (str_starts_with($path, $pfx)) {
                return true;
            }
        }
        return false;
    };

    $routesSrc = file_get_contents(dirname(__DIR__, 2) . '/config/routes.php') ?: '';
    preg_match_all(
        "/\['(POST|PUT|DELETE|PATCH)',\s*'(\/api\/admin\/[^']*)',\s*\[(\w+)::class,\s*'(\w+)'\]/i",
        $routesSrc,
        $w,
        PREG_SET_ORDER
    );

    $trulyDerived = [];
    foreach ($w as $hit) {
        $method = strtoupper($hit[1]);
        $path = $hit[2];
        $key = $method . ' ' . $path;
        // 中间件的键把 {id} 等占位符原样保留，故此处无需替换
        if (isset($registered[$key])) {
            continue;
        }
        if ($prefixCovered($path)) {
            continue;
        }
        if (isset($writeExempt[$key])) {
            // 豁免须有理由，防止「什么都往白名单塞」导致防线失效
            $t->assertTrue(
                '豁免项有理由: ' . $key,
                trim($writeExempt[$key]) !== '',
                '白名单条目不得为空理由'
            );
            continue;
        }
        $trulyDerived[] = $method . ' ' . $path . '  ->  Admin\\' . $hit[3] . '::' . $hit[4] . '()';
    }

    $t->assertSame(
        '后台写操作无「仅靠推导」的项',
        '',
        implode("\n     ", $trulyDerived)
    );
});

exit($t->finish());
