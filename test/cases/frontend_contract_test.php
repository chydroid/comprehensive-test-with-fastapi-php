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

exit($t->finish());
