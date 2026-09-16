<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Response;

/**
 * 前端页面渲染（SPA 外壳）
 *
 * 说明：本系统前端为原生 ES Module SPA，无构建步骤。
 * 这里只负责输出一个最小的 HTML 外壳，把 CSS/JS 交给浏览器按需加载，
 * 所有数据仍走 /api/** 接口。因此无需模板引擎。
 */
class PageController extends BaseController
{
    /**
     * 各端页面配置：[标题, 入口脚本, 品牌副标题]
     *
     * 标题为 null 表示「用产品名」（config('app.name')）：门户是全站入口，
     * 标题就该是产品名本身，而不是某个功能名。改名只需改 config/config.php。
     */
    private const PAGES = [
        'portal'  => [null, '/assets/js/apps/portal.js', '考试 · 练习 · 成绩'],
        'student' => ['考生中心', '/assets/js/apps/student.js', '个人中心'],
        'exam'    => ['在线考场', '/assets/js/apps/exam.js', '考试进行中'],
        'exercise'=> ['在线练习', '/assets/js/apps/exercise.js', '题库练习与模拟'],
        'teacher' => ['教师工作台', '/assets/js/apps/teacher.js', '组卷 · 监考 · 成绩'],
        'admin'   => ['考试管理后台', '/assets/js/apps/admin.js', '运营管理'],
    ];

    /** 站点入口（门户首页） */
    public function portal(): Response
    {
        return $this->render('portal');
    }

    public function student(): Response
    {
        return $this->render('student');
    }

    public function exam(): Response
    {
        return $this->render('exam');
    }

    public function exercise(): Response
    {
        return $this->render('exercise');
    }

    public function teacher(): Response
    {
        return $this->render('teacher');
    }

    public function admin(): Response
    {
        return $this->render('admin');
    }

    /* ------------------------------------------------------------------ */

    /** 输出指定端的 HTML 外壳 */
    private function render(string $key): Response
    {
        [$title, $entry, $subtitle] = self::PAGES[$key] ?? self::PAGES['portal'];
        $appName = $this->appName();
        // 标题为 null 的端直接用产品名（见 PAGES 注释）
        $title = $title ?? $appName;
        $version = $this->assetVersion();
        $logo = $this->bootLogo();

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN" data-page="{$key}" data-app-name="{$appName}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#4f46e5">
<title>{$title}</title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/tokens.css?v={$version}">
<link rel="stylesheet" href="/assets/css/base.css?v={$version}">
<link rel="stylesheet" href="/assets/css/components.css?v={$version}">
<link rel="stylesheet" href="/assets/css/shell.css?v={$version}">
<link rel="stylesheet" href="/assets/css/login.css?v={$version}">
<link rel="stylesheet" href="/assets/css/portal.css?v={$version}">
<link rel="stylesheet" href="/assets/css/mobile.css?v={$version}">
<script src="/assets/js/theme.js?v={$version}"></script>
</head>
<body>
<div id="app" data-page="{$key}" data-subtitle="{$subtitle}">
  <div class="boot-splash">
    <div class="boot-logo">{$logo}</div>
    <div class="boot-spinner"></div>
    <div class="boot-text">正在加载 {$title}…</div>
  </div>
</div>
<noscript>
  <div style="padding:24px;font-family:system-ui;text-align:center">
    本系统需要启用 JavaScript 才能正常使用，请在浏览器设置中启用后刷新页面。
  </div>
</noscript>
<script src="/assets/js/app-ready.js?v={$version}"></script>
<script type="module" src="{$entry}?v={$version}"></script>
</body>
</html>
HTML;

        return $this->response->html($html);
    }

    /**
     * 产品名（唯一来源：config('app.name')）。
     *
     * 已做 HTML 转义，可同时安全用于 <title> 文本与 data-app-name 属性值。
     * 前端 JS 不直接读这个值，而是读 <html data-app-name>（见 core/brand.js）——
     * 因为 CSP 是 script-src 'self'，不能往页面里塞内联 <script> 传变量。
     */
    private function appName(): string
    {
        return htmlspecialchars((string) config('app.name', '深蓝网上考试系统'), ENT_QUOTES, 'UTF-8');
    }

    /**
     * 启动闪屏用的 LOGO。
     *
     * 直接读取矢量资产内联进 HTML，而不是 <img src>：
     *   1. 闪屏要在 JS 执行之前就画出来，用 <img> 会晚一拍、还可能闪一下；
     *   2. 内联的 SVG 才能吃到主题变量（--logo-ink / --logo-accent），
     *      暗色主题下墨色自动变浅，<img> 加载的独立文档继承不到。
     * 读取文件而不是把路径数据再抄一份到 PHP，保证几何只有一处定义。
     */
    private function bootLogo(): string
    {
        static $svg = null;
        if ($svg === null) {
            $path = BASE_PATH . '/public/assets/img/logo-mark.svg';
            $svg = is_file($path) ? trim((string) file_get_contents($path)) : '';
        }
        return $svg;
    }

    /**
     * 资源版本号：取 assets 下全部 js/css 的最新修改时间，便于缓存失效。
     *
     * 注意：必须递归扫描整个 assets 目录。入口脚本虽然带 ?v=，但它 import 的
     * 子模块（core/、ui/、views/ 等）不带版本号，只按 URL 缓存；若版本只看
     * apps/*.js，改动 ui/shell.js 之类子模块不会让版本号变化，浏览器仍用旧缓存。
     */
    private function assetVersion(): string
    {
        $latest = 0;
        $root = BASE_PATH . '/public/assets';
        if (!is_dir($root)) {
            return substr((string) time(), -6);
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            if (!in_array(strtolower($file->getExtension()), ['js', 'css'], true)) continue;
            $latest = max($latest, (int) $file->getMTime());
        }
        return substr((string) ($latest ?: time()), -6);
    }
}
