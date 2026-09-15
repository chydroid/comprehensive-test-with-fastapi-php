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
    /** 各端页面配置：[标题, 入口脚本, 品牌副标题] */
    private const PAGES = [
        'portal'  => ['在线考试系统', '/assets/js/apps/portal.js', '考试 · 练习 · 成绩'],
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
        $version = $this->assetVersion();

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN" data-page="{$key}">
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
    <div class="boot-logo">考</div>
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

    /** 资源版本号：取全部入口脚本与样式的最新修改时间，便于缓存失效 */
    private function assetVersion(): string
    {
        $latest = 0;
        foreach ([
            glob(BASE_PATH . '/public/assets/js/apps/*.js') ?: [],
            glob(BASE_PATH . '/public/assets/css/*.css') ?: [],
        ] as $files) {
            foreach ($files as $f) {
                $latest = max($latest, (int) filemtime($f));
            }
        }
        return substr((string) ($latest ?: time()), -6);
    }
}
