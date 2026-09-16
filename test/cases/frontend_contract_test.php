<?php

declare(strict_types=1);

/**
 * 前端契约测试
 *
 * 目的：前端视图依赖的每一个接口，都在此以真实请求验证一次，
 * 并断言关键字段确实存在。这样后端改动若破坏了前端契约，
 * 测试会立刻失败，而不是等到浏览器里白屏才发现。
 *
 * 覆盖两类回归：
 *  1) GET 查询串参数必须能被 validate() 读到（曾只读 body，导致
 *     GET 接口的筛选参数全部失效、静默返回空数据）；
 *  2) 前端声明的路由必须真实存在（曾经 /api/student/options、
 *     /api/exercise/answer 只在控制器里，忘了注册）。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;

if (ob_get_level() === 0) {
    ob_start();
}

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';

bootstrap();

$t = new Harness();
echo "== 前端契约测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('前端契约', '数据库不可用');
    exit($t->finish());
}

/* ============================================================
 * 一、路由存在性：前端 API 层声明的路径必须全部命中
 * ============================================================ */
$declared = [
    ['GET',  '/api/public/site'],
    ['GET',  '/api/public/help'],
    ['GET',  '/api/public/hero'],
    ['GET',  '/api/public/subjects'],
    ['GET',  '/api/public/categories'],
    ['GET',  '/api/public/news'],
    ['POST', '/api/student/register'],
    ['GET',  '/api/student/register/options'],
    ['POST', '/api/student/login'],
    ['POST', '/api/student/logout'],
    ['GET',  '/api/student/me'],
    ['GET',  '/api/student/info'],
    ['PUT',  '/api/student/info'],
    ['PUT',  '/api/student/password'],
    ['GET',  '/api/student/options'],
    ['GET',  '/api/student/scores'],
    ['GET',  '/api/student/exams'],
    ['POST', '/api/exam/login'],
    ['POST', '/api/exam/logout'],
    ['GET',  '/api/exam/paper'],
    ['POST', '/api/exam/paper/save'],
    ['POST', '/api/exam/paper/submit'],
    ['GET',  '/api/exam/over'],
    ['GET',  '/api/exam/answer'],
    ['GET',  '/api/exercise'],
    ['POST', '/api/exercise/answer'],
    ['GET',  '/api/exercise/mock/config'],
    ['POST', '/api/exercise/mock/start'],
    ['GET',  '/api/exercise/mock/counts'],
    ['GET',  '/api/exercise/mock/paper'],
    ['POST', '/api/exercise/mock/save'],
    ['POST', '/api/exercise/mock/submit'],
    ['GET',  '/api/exercise/mock/over'],
    ['GET',  '/api/exercise/mock/review'],
    ['POST', '/api/exercise/mock/logout'],
    ['POST', '/api/teacher/login'],
    ['POST', '/api/teacher/logout'],
    ['GET',  '/api/teacher/me'],
    ['GET',  '/api/teacher/monitor'],
    ['POST', '/api/teacher/monitor/lock'],
    ['POST', '/api/teacher/monitor/unlock'],
    ['POST', '/api/teacher/monitor/submit'],
    ['POST', '/api/teacher/monitor/lock-all'],
    ['POST', '/api/teacher/monitor/unlock-all'],
    ['POST', '/api/teacher/monitor/over-all'],
    ['GET',  '/api/teacher/exams'],
    ['GET',  '/api/teacher/exams/{id}'],
    ['POST', '/api/teacher/exams'],
    ['PUT',  '/api/teacher/exams/{id}'],
    ['POST', '/api/teacher/exams/{id}/start'],
    ['DELETE', '/api/teacher/exams/{id}'],
    ['GET',  '/api/teacher/exams/{id}/students'],
    ['GET',  '/api/teacher/exams/{id}/quiz-count'],
    ['GET',  '/api/teacher/scores'],
    ['GET',  '/api/teacher/scores/export'],
    // SPA 页面外壳
    ['GET',  '/'],
    ['GET',  '/portal'],
    ['GET',  '/student'],
    ['GET',  '/exam'],
    ['GET',  '/exercise'],
    ['GET',  '/teacher'],
    ['GET',  '/admin'],
];

$routes = require $base . '/config/routes.php';
$index = [];
foreach ($routes as [$m, $p]) {
    $index[strtoupper((string) $m) . ' ' . $p] = true;
}
// 另建一份「模式→是否已注册」的宽松表，用于带 {id} 的路径
$patterns = array_keys($index);

$missing = [];
foreach ($declared as [$m, $p]) {
    if (!isset($index["{$m} {$p}"])) {
        $missing[] = "{$m} {$p}";
    }
}
$t->assertTrue(
    '前端声明的接口全部已注册 (' . count($declared) . ' 条)',
    $missing === [],
    $missing === [] ? '' : '缺失: ' . implode(', ', $missing)
);

/* ============================================================
 * 二、回归：GET 查询串必须被 validate() 读取
 * ============================================================ */
$t->guard('GET 查询串参数可被 validate 读取', function () use ($t, $base) {
    // 用一个明确的 GET 接口验证：/api/public/news 的 per_page 生效
    $r = Http::get('/api/public/news?page=1&per_page=3');
    $perPage = $r['body']['data']['per_page'] ?? null;
    $t->assertSame('  per_page 被正确解析', 3, $perPage);
});

$t->guard('带筛选的 GET 接口不会静默返回空', function () use ($t, $base) {
    // /api/exercise 需要登录，这里改用公开的 subjects 过滤能力无关；
    // 直接验证 validate() 内部取值来源已合并 query（通过 mock 请求）
    $r = Http::get('/api/public/news?per_page=1');
    $total = $r['body']['data']['total'] ?? null;
    $perPage = $r['body']['data']['per_page'] ?? null;
    $t->assertTrue('  total 为整数', is_int($total), 'total=' . var_export($total, true));
    $t->assertSame('  per_page=1 生效', 1, $perPage);
});

/* ============================================================
 * 三、公开接口的响应结构契约（前端直接消费的字段）
 * ============================================================ */
$t->guard('GET /api/public/site 结构', function () use ($t) {
    $r = Http::get('/api/public/site');
    $d = Http::data($r) ?? [];
    $t->assertTrue('  quiz_stats.total 存在', isset($d['quiz_stats']['total']), json_encode(array_keys($d)));
    $t->assertTrue('  exam_count 存在', array_key_exists('exam_count', $d));
    $t->assertTrue('  student_count 存在', array_key_exists('student_count', $d));
    $t->assertTrue('  config 存在', isset($d['config']));
});

$t->guard('GET /api/public/help 结构', function () use ($t) {
    $r = Http::get('/api/public/help');
    $d = Http::data($r) ?? [];
    $t->assertTrue('  title 存在', isset($d['title']));
    $t->assertTrue('  blocks 为数组', is_array($d['blocks'] ?? null));
    if (!empty($d['blocks'])) {
        $t->assertTrue('  block.heading 存在', isset($d['blocks'][0]['heading']));
        $t->assertTrue('  block.items 为数组', is_array($d['blocks'][0]['items'] ?? null));
    }
});

$t->guard('GET /api/public/news 分页结构', function () use ($t) {
    $r = Http::get('/api/public/news?page=1&per_page=5');
    $d = Http::data($r) ?? [];
    foreach (['list', 'total', 'page', 'per_page', 'total_pages', 'has_more'] as $k) {
        $t->assertTrue("  字段 {$k} 存在", array_key_exists($k, $d), json_encode(array_keys($d)));
    }
    $t->assertTrue('  list 为数组', is_array($d['list'] ?? null));
});

/* ============================================================
 * 四、门户页面外壳（HTML）关键元素
 * ============================================================ */
foreach (['portal', 'student', 'exam', 'exercise', 'teacher', 'admin'] as $page) {
    $t->guard("页面外壳 /{$page}", function () use ($t, $page, $base) {
        // 直接调用控制器，避免依赖 HTTP 层（这些是 GET HTML 路由）
        $cls = \App\Controllers\PageController::class;
        $paths = $page === 'portal' ? ['/', '/portal'] : ["/{$page}"];
        foreach ($paths as $p) {
            $req = new \Core\Request();
            $res = new \Core\Response();
            $ctrl = new $cls($req, $res);
            $html = null;
            try {
                $resp = $ctrl->{$page}();
                $html = $resp->body();
            } catch (\Throwable $e) {
                $t->assertTrue("  {$p} 渲染无异常", false, $e->getMessage());
                return;
            }
            $t->assertTrue("  {$p} 含 #app 容器", str_contains($html, 'id="app"'));
            $t->assertTrue("  {$p} 引用了入口脚本", (bool) preg_match('#/assets/js/apps/[a-z]+\.js#', $html));
            $t->assertTrue("  {$p} 引用 favicon", str_contains($html, '/assets/img/favicon.svg'));
            $t->assertTrue("  {$p} data-page 正确", str_contains($html, "data-page=\"{$page}\""));

            // 入口脚本必须真实存在
            if (preg_match('#/assets/js/apps/([a-z]+)\.js#', $html, $m)) {
                $entry = $base . '/public/assets/js/apps/' . $m[1] . '.js';
                $t->assertTrue("  {$p} 入口脚本文件存在", is_file($entry), $entry);
            }
        }
    });
}

/* ============================================================
 * 五、会话探测接口契约：未登录必须 200 + logged_in:false
 *
 * 背景（回归）：三端 me 接口的路径天然落在受保护前缀之下
 * （/api/student/me ⊂ /api/student/、/api/teacher/me ⊂ /api/teacher/、
 *  /api/admin/me ⊂ /api/admin/），若鉴权中间件只按前缀匹配，就会在控制器
 * 之前把它们拦成 401「登录已过期，请重新登录」。后果有两处：
 *   1. 门户首页 / 注册页等公开页面每次打开都有一条控制台 401 报错；
 *   2. 前端 boot 的登录态恢复只能走异常分支，csrf_token 永远取不到
 *      （bootstrapSession 里 setCsrfToken(data.csrf_token) 成为死代码）。
 * 修复方式：SessionAuthMiddleware::EXACT_RULES 精确放行。
 * 这里锁死契约，防止被改回前缀匹配。
 * ============================================================ */
foreach (['/api/student/me', '/api/teacher/me', '/api/admin/me'] as $mePath) {
    $t->guard("未登录 {$mePath} 返回 200 而非 401", function () use ($t, $mePath) {
        $res = Http::get($mePath);
        $t->assertSame("  {$mePath} 状态码为 200", 200, $res['status']);
        $data = Http::data($res) ?? [];
        $t->assertSame("  {$mePath} logged_in 为 false", false, $data['logged_in'] ?? null);
        $t->assertTrue(
            "  {$mePath} 下发 csrf_token",
            is_string($data['csrf_token'] ?? null) && $data['csrf_token'] !== '',
            json_encode(array_keys($data))
        );
    });
}

/* 考场状态查询同属「会话探测」：未入场必须 200 + phase 为空（不得 401）。
 * 否则公开的考场入口页 /exam 首屏就会在控制台报 401，并被该页的
 * onUnauthorized(router.navigate('/')) 放大成自激请求风暴。 */
$t->guard('未入场 GET /api/exam/status 返回 200 且 phase 为空', function () use ($t) {
    $res = Http::get('/api/exam/status');
    $t->assertSame('  状态码为 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertSame('  phase 为空', null, $data['phase'] ?? null);
    $t->assertTrue('  exam 为空', array_key_exists('exam', $data) && $data['exam'] === null);
    $t->assertTrue(
        '  下发 csrf_token',
        is_string($data['csrf_token'] ?? null) && $data['csrf_token'] !== '',
        json_encode(array_keys($data))
    );
});

/* 反向：同前缀下的真实受保护接口必须仍然 401（防止精确规则被写成前缀规则） */
$guardedPaths = ['/api/student/info', '/api/teacher/monitor', '/api/admin/dashboard', '/api/exam/paper', '/api/exam/answer'];
foreach ($guardedPaths as $guardedPath) {
    $t->guard("未登录 {$guardedPath} 仍为 401", function () use ($t, $guardedPath) {
        $t->assertSame("  {$guardedPath} 受保护", 401, Http::get($guardedPath)['status']);
    });
}

/* ============================================================
 * 六、品牌标识（LOGO）契约
 *
 * 背景：全站 LOGO 已统一为 DEEPBLUE 矢量标识（几何取自
 * public/uploads/logo.png 的矢量化结果）。这里锁死四点，防止回退：
 *
 *  1) 资产存在且是矢量路径 —— 位图在 16px favicon 与高分屏上都会糊；
 *  2) 墨色（锚体 + 文字）走 var(--logo-ink, currentColor)。
 *     这正是「主形象与文字颜色随背景色变化」的实现方式：内联后跟随所在
 *     上下文的文字色，亮底变深、暗底变浅。一旦被改成硬编码色，
 *     暗色主题下 LOGO 会直接看不见 —— 而这是纯视觉问题，静态检查抓不到；
 *  3) 旧的 .brand-mark「方块字号牌」已全面退役，避免两套品牌视觉并存；
 *  4) 各端首屏（启动闪屏）已经在服务端内联 LOGO，JS 执行前就可见。
 * ============================================================ */
$t->guard('LOGO 资产与取色契约', function () use ($t, $base) {
    $img = $base . '/public/assets/img';

    foreach (['logo.svg', 'logo-mark.svg', 'favicon.svg'] as $f) {
        $path = "{$img}/{$f}";
        $t->assertTrue("  {$f} 存在", is_file($path), $path);
        if (!is_file($path)) {
            continue;
        }
        $svg = (string) file_get_contents($path);
        $t->assertTrue("  {$f} 含 viewBox", str_contains($svg, 'viewBox='));
        $t->assertTrue("  {$f} 为矢量路径", str_contains($svg, '<path'));
    }

    foreach (['logo.svg', 'logo-mark.svg'] as $f) {
        if (!is_file("{$img}/{$f}")) {
            continue;
        }
        $svg = (string) file_get_contents("{$img}/{$f}");
        $t->assertTrue(
            "  {$f} 墨色取 --logo-ink 且以 currentColor 兜底",
            str_contains($svg, 'var(--logo-ink, currentColor)')
        );
        $t->assertTrue("  {$f} 青绿环取 --logo-accent", str_contains($svg, 'var(--logo-accent'));
    }

    $logoJs = $base . '/public/assets/js/core/logo.js';
    $t->assertTrue('  core/logo.js 存在（内联 SVG 模块）', is_file($logoJs));
    if (is_file($logoJs)) {
        $js = (string) file_get_contents($logoJs);
        $t->assertTrue('    导出 logoMark', str_contains($js, 'export function logoMark'));
        $t->assertTrue('    导出 logoLockup', str_contains($js, 'export function logoLockup'));
        $t->assertTrue('    墨色使用 currentColor 兜底', str_contains($js, 'var(--logo-ink, currentColor)'));
    }

    $tokens = (string) file_get_contents($base . '/public/assets/css/tokens.css');
    $t->assertTrue('  tokens.css 定义 --logo-ink', str_contains($tokens, '--logo-ink:'));
    $t->assertTrue('  tokens.css 定义 --logo-accent', str_contains($tokens, '--logo-accent:'));
    $t->assertTrue(
        '  tokens.css 暗色主题覆盖 LOGO 取色',
        preg_match('/\[data-theme="dark"\][\s\S]*?--logo-ink:/', $tokens) === 1
    );

    // 旧字号牌必须已退役：全站 JS 里不应再出现 brand-mark
    $stale = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($base . '/public/assets/js', \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'js') {
            continue;
        }
        if (str_contains((string) file_get_contents($file->getPathname()), 'brand-mark')) {
            $stale[] = basename($file->getPathname());
        }
    }
    $t->assertTrue('  已无视图使用旧的 .brand-mark 字号牌', count($stale) === 0, implode(',', $stale));
});

$t->guard('各端启动闪屏在服务端内联 LOGO', function () use ($t) {
    $cls = \App\Controllers\PageController::class;
    foreach (['portal', 'student', 'exam', 'exercise', 'teacher', 'admin'] as $page) {
        $ctrl = new $cls(new \Core\Request(), new \Core\Response());
        try {
            $html = $ctrl->{$page}()->body();
        } catch (\Throwable $e) {
            $t->assertTrue("  /{$page} 渲染无异常", false, $e->getMessage());
            continue;
        }
        $t->assertTrue("  /{$page} 闪屏内联了 SVG LOGO", preg_match('/class="boot-logo">\s*<svg/u', $html) === 1);
        $t->assertTrue("  /{$page} 闪屏 LOGO 带品牌取色", str_contains($html, 'logo-accent'));
    }
});

/* ============================================================
 * 七、产品名契约（深蓝网上考试系统）
 *
 * 背景：产品名只有一处定义 —— config/config.php 的 app.name。
 * 服务端渲染外壳时把它注入到 <html data-app-name>（不能塞内联 <script>：
 * 站点 CSP 是 script-src 'self'），前端统一由 core/brand.js#appName() 读取。
 *
 * 这里锁死三点，防止「改名只改一半」：
 *  1) config 里的产品名就是当前产品名；
 *  2) 各端外壳都带 data-app-name，门户 <title> 就是产品名
 *     —— 漏注入时前端只会静默回落到 brand.js 里的兜底常量，
 *     标题看似正常、其实已经和服务端脱钩；
 *  3) 前端 JS 里不再残留写死的旧产品名（「在线考试系统」/
 *     「网上理论考核系统」）。这类字符串以前散在 6 个文件里，
 *     漏一处就会出现「导航是新名、页脚是旧名」的不一致。
 * ============================================================ */
$t->guard('产品名单一来源契约', function () use ($t, $base) {
    $name = (string) config('app.name', '');
    $t->assertSame('  config app.name 为当前产品名', '深蓝网上考试系统', $name);

    $cls = \App\Controllers\PageController::class;
    $render = static function (string $page) use ($cls): string {
        $ctrl = new $cls(new \Core\Request(), new \Core\Response());
        return $ctrl->{$page}()->body();
    };

    foreach (['portal', 'student', 'exam', 'exercise', 'teacher', 'admin'] as $page) {
        $t->assertTrue(
            "  /{$page} 注入 data-app-name",
            str_contains($render($page), 'data-app-name="' . $name . '"')
        );
    }

    $portal = $render('portal');
    $t->assertTrue('  门户 <title> 即产品名', str_contains($portal, "<title>{$name}</title>"));
    $t->assertTrue('  门户闪屏文案含产品名', str_contains($portal, "正在加载 {$name}"));

    $brand = $base . '/public/assets/js/core/brand.js';
    $t->assertTrue('  core/brand.js 存在（前端唯一读取口）', is_file($brand));
    if (is_file($brand)) {
        $js = (string) file_get_contents($brand);
        $t->assertTrue('    导出 appName', str_contains($js, 'export function appName'));
        $t->assertTrue('    从 data-app-name 读取', str_contains($js, 'dataset?.appName'));
    }

    // 前端 JS 不得再写死旧产品名
    $stale = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($base . '/public/assets/js', \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'js') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        if (str_contains($src, '在线考试系统') || str_contains($src, '网上理论考核系统')) {
            $stale[] = basename($file->getPathname());
        }
    }
    $t->assertTrue('  前端已无写死的旧产品名', count($stale) === 0, implode(',', $stale));
});

exit($t->finish());
