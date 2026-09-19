<?php

declare(strict_types=1);

/**
 * B1 / B3 / B4 前端契约与移动端适配测试
 *
 * 这三项的主体逻辑在浏览器里（切屏上报、选项乱序渲染、导入弹窗、底部标签栏），
 * 后端测试覆盖不到，因此这里对「源码契约」做静态断言：一旦有人把关键接线删掉，
 * 测试立刻失败，而不是等到真人拿手机点一遍才发现。
 *
 * 覆盖：
 *   B1 防作弊 —— 选项按 option_order 重排、切屏/失焦上报、水印、设置开关下发、
 *               API 层上报通道、监考页异常次数与异常记录面板
 *   B3 批量导入 —— API 通道、导入弹窗、模板下载、知识点列
 *   B4 移动端 —— 视口标签、样式加载顺序、底部标签栏、触控目标、断点标尺
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Services\AuthSession;
use App\Services\Setting;
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
echo "== B1/B3/B4 前端契约与移动端测试 ==\n";

$read = static function (string $rel) use ($base): string {
    $p = $base . '/' . ltrim($rel, '/');
    return is_file($p) ? (string) file_get_contents($p) : '';
};

/* ============================================================
 * 一、B1 防作弊：前端接线
 * ============================================================ */
$t->guard('B1 选项乱序渲染', function () use ($t, $read) {
    $js = $read('public/assets/js/views/exam-runner.js');
    $t->assertTrue('exam-runner 读取 option_order', str_contains($js, 'option_order'));
    $t->assertTrue('按 option_order 重排选项', str_contains($js, 'selectableList'));
    $t->assertTrue('渲染循环使用重排后的列表', str_contains($js, 'for (const o of selectableList)'));
    // 若渲染循环仍引用未重排的 selectable，乱序就是纯摆设
    $t->assertTrue('渲染循环未再使用原始顺序', !str_contains($js, 'for (const o of selectable)'));
});

$t->guard('B1 切屏与失焦上报', function () use ($t, $read) {
    $js = $read('public/assets/js/views/exam-runner.js');
    $t->assertTrue('监听 visibilitychange', str_contains($js, "addEventListener('visibilitychange'"));
    $t->assertTrue('监听 window blur', str_contains($js, "addEventListener('blur'"));
    $t->assertTrue('上报类型 tab_hidden', str_contains($js, "'tab_hidden'"));
    $t->assertTrue('上报类型 blur', str_contains($js, "'blur'"));
    $t->assertTrue('仅正式考试模式启用', str_contains($js, "cfg.mode !== 'exam'"));
    $t->assertTrue('受设置开关约束', str_contains($js, "appSettingBool('enable_cheat_guard')"));
    $t->assertTrue('dispose 中注销监听', str_contains($js, "removeEventListener('visibilitychange'"));
    $t->assertTrue('dispose 中移除水印', str_contains($js, 'watermarkNode.remove()'));
});

$t->guard('B1 水印', function () use ($t, $read) {
    $js = $read('public/assets/js/views/exam-runner.js');
    $t->assertTrue('水印节点类名', str_contains($js, 'exam-watermark'));
    $css = $read('public/assets/css/portal.css');
    $t->assertTrue('水印样式已定义', str_contains($css, '.exam-watermark'));
    $t->assertTrue('水印不拦截点击', str_contains($css, 'pointer-events: none'));
});

$t->guard('B1 考生端接线', function () use ($t, $read) {
    $js = $read('public/assets/js/views/student/exam.js');
    $t->assertTrue('考生端传入 reportCheat', str_contains($js, 'reportCheat'));
    $t->assertTrue('考生端传入 cheatLabel', str_contains($js, 'cheatLabel'));
    $t->assertTrue('水印含考生姓名', str_contains($js, 'stu_name'));
});

$t->guard('B1 API 与设置', function () use ($t, $read) {
    $api = $read('public/assets/js/api/index.js');
    $t->assertTrue('examApi.reportCheat 已声明', str_contains($api, 'reportCheat'));
    $t->assertTrue('教师端 cheatEvents 已声明', str_contains($api, 'cheatEvents'));
    $t->assertTrue('上报路径 /exam/cheat', str_contains($api, "'/exam/cheat'"));

    $settings = $read('public/assets/js/core/app-settings.js');
    $t->assertTrue('FALLBACK 含 enable_cheat_guard', str_contains($settings, 'enable_cheat_guard'));

    // 后端 schema + 公开下发
    $t->assertTrue('Setting SCHEMA 登记 enable_cheat_guard', isset(Setting::SCHEMA['enable_cheat_guard']));
    $t->assertTrue('设置项对前端公开', (bool) (Setting::SCHEMA['enable_cheat_guard']['public'] ?? false));

    AuthSession::logout();
    $res = Http::get('/api/public/settings');
    $t->assertSame('GET /api/public/settings -> 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertTrue('公开设置含 enable_cheat_guard', array_key_exists('enable_cheat_guard', $data));
});

$t->guard('B1 监考端异常展示', function () use ($t, $read) {
    $admin = $read('public/assets/js/views/admin/monitor.js');
    $t->assertTrue('管理端读异常次数字段', str_contains($admin, 'cheat_count'));
    $t->assertTrue('管理端有「异常考生」统计卡', str_contains($admin, '异常考生'));
    $t->assertTrue('管理端表格有「异常次数」列', str_contains($admin, "title: '异常次数'"));
    $t->assertTrue('管理端调 cheatEvents', str_contains($admin, 'cheatEvents'));
    $t->assertTrue('管理端异常类型有中文映射', str_contains($admin, "tab_hidden: '切屏'"));

    $teacher = $read('public/assets/js/views/teacher/index.js');
    $t->assertTrue('教师端读异常次数字段', str_contains($teacher, 'cheat_count'));
    $t->assertTrue('教师端有「异常考生」统计卡', str_contains($teacher, '异常考生'));
    $t->assertTrue('教师端表格有「异常次数」列', str_contains($teacher, "title: '异常次数'"));
    $t->assertTrue('教师端调 cheatEvents', str_contains($teacher, 'cheatEvents'));
});

/* ============================================================
 * 二、B3 批量导入：前端契约
 * ============================================================ */
$t->guard('B3 导入前端', function () use ($t, $read) {
    $api = $read('public/assets/js/api/index.js');
    $t->assertTrue('importQuizzes 已声明', str_contains($api, 'importQuizzes'));
    $t->assertTrue('导入路径正确', str_contains($api, "'/admin/quizzes/import'"));

    $js = $read('public/assets/js/views/admin/quiz.js');
    $t->assertTrue('题库页有 openImport', str_contains($js, 'function openImport'));
    $t->assertTrue('有批量导入入口按钮', str_contains($js, "'批量导入'"));
    $t->assertTrue('入口按 quiz.import 权限显示', str_contains($js, "can('quiz.import')"));
    $t->assertTrue('有 CSV 模板下载', str_contains($js, '题库导入模板.csv'));
    $t->assertTrue('支持文件上传', str_contains($js, 'type: \'file\''));
    $t->assertTrue('调用导入接口', str_contains($js, 'adminApi.importQuizzes'));
    $t->assertTrue('渲染失败明细', str_contains($js, '导入结果'));
    $t->assertTrue('编辑器含知识点字段', str_contains($js, "name: 'quiz_kp'"));
    $t->assertTrue('列表含知识点列', str_contains($js, "title: '知识点'"));
    // textarea 必须导入，否则整个导入弹窗渲染即抛 ReferenceError
    $t->assertTrue('textarea 已导入', str_contains($js, 'select, textarea,'));
});

/* ============================================================
 * 三、B4 移动端适配
 * ============================================================ */
$t->guard('B4 视口与样式加载', function () use ($t, $base) {
    $cls = \App\Controllers\PageController::class;
    $render = static function (string $page) use ($cls): string {
        $ctrl = new $cls(new \Core\Request(), new \Core\Response());
        return $ctrl->{$page}()->body();
    };

    foreach (['portal', 'student', 'exam', 'exercise', 'teacher', 'admin'] as $page) {
        $html = $render($page);
        $t->assertTrue("/{$page} 视口含 width=device-width", str_contains($html, 'width=device-width'));
        $t->assertTrue("/{$page} 视口含 viewport-fit=cover（刘海屏安全区）", str_contains($html, 'viewport-fit=cover'));
        $t->assertTrue("/{$page} 加载 mobile.css", str_contains($html, '/assets/css/mobile.css'));
    }

    // 加载顺序必须是 mobile.css 在最后，否则覆盖规则会被基础样式反压
    $html = $render('student');
    $t->assertTrue(
        'mobile.css 在所有样式之后加载',
        strrpos($html, 'mobile.css') > strrpos($html, 'portal.css')
    );
});

$t->guard('B4 底部标签栏', function () use ($t, $read) {
    $js = $read('public/assets/js/ui/shell.js');
    $t->assertTrue('shell 支持 mobileTabs 配置', str_contains($js, 'mobileTabs'));
    $t->assertTrue('渲染 .mobile-tabbar', str_contains($js, 'mobile-tabbar'));
    $t->assertTrue('标签项类名', str_contains($js, 'mobile-tabbar-item'));
    $t->assertTrue('根节点打 has-mobile-tabs 标记', str_contains($js, 'has-mobile-tabs'));
    $t->assertTrue('setActive 同步标签栏', str_contains($js, 'tabButtons'));

    $app = $read('public/assets/js/apps/student.js');
    $t->assertTrue('考生端启用底部标签栏', str_contains($app, 'mobileTabs'));
    $t->assertTrue('标签含我的考试', str_contains($app, "'exams'"));
    $t->assertTrue('标签含错题本', str_contains($app, "'wrongbook'"));

    $css = $read('public/assets/css/shell.css');
    $t->assertTrue('桌面端隐藏标签栏', str_contains($css, '.mobile-tabbar { display: none; }'));
    $t->assertTrue('窄屏显示标签栏', str_contains($css, '.app-shell.has-mobile-tabs .mobile-tabbar'));
    $t->assertTrue('内容区为标签栏留白', str_contains($css, '.app-shell.has-mobile-tabs .app-content'));
    $t->assertTrue('标签栏适配安全区', str_contains($css, 'env(safe-area-inset-bottom'));
});

$t->guard('B4 触控目标与断点', function () use ($t, $read) {
    $css = $read('public/assets/css/mobile.css');
    $t->assertTrue('存在 pointer: coarse 分支', str_contains($css, '@media (pointer: coarse)'));
    $t->assertTrue('按钮最小高度 44px', str_contains($css, '.btn { min-height: 44px; }'));
    $t->assertTrue('图标按钮保证宽度', str_contains($css, '.btn-icon, .btn-icon.btn-sm, .btn-icon.btn-xs { min-width: 44px; }'));
    $t->assertTrue('表单控件最小高度', str_contains($css, '.input, .select { min-height: 44px; }'));
    $t->assertTrue('答题选项整行可点', str_contains($css, '.option-item { min-height: 48px; }'));
    $t->assertTrue('表格横向滚动并可惯性', str_contains($css, '-webkit-overflow-scrolling: touch'));
    $t->assertTrue('触屏去掉粘滞行悬停', str_contains($css, '@media (hover: none)'));
    $t->assertTrue('记录断点标尺', str_contains($css, '断点标尺'));
    $t->assertTrue('考试页固定底栏保留', str_contains($css, '.exam-bottombar'));
    $t->assertTrue('答题卡移动端抽屉保留', str_contains($css, '.exam-layout.is-sheet-open .answer-sheet'));
});

$t->guard('B4 CSS 无语法破损', function () use ($t, $read) {
    foreach (['shell.css', 'mobile.css', 'portal.css', 'components.css'] as $f) {
        $css = $read('public/assets/css/' . $f);
        $t->assertTrue("{$f} 大括号配平", substr_count($css, '{') === substr_count($css, '}'));
    }
});

/* ============================================================
 * 四、B3 后端路由可达性（与前端 api 层对齐）
 * ============================================================ */
$t->guard('B3 路由可达', function () use ($t, $base) {
    $router = new Core\Router();
    foreach (require $base . '/config/routes.php' as $route) {
        if (!is_array($route) || count($route) < 3) continue;
        $router->add($route[0], $route[1], $route[2], $route[3] ?? []);
    }
    $t->assertSame('POST /api/admin/quizzes/import 已注册', '/api/admin/quizzes/import', $router->match('POST', '/api/admin/quizzes/import'));
    $t->assertSame('POST /api/exam/cheat 已注册', '/api/exam/cheat', $router->match('POST', '/api/exam/cheat'));
    $t->assertSame('教师端 cheat-events 已注册', '/api/teacher/monitor/cheat-events', $router->match('GET', '/api/teacher/monitor/cheat-events'));
    $t->assertSame('管理端 cheat-events 已注册', '/api/admin/monitor/cheat-events', $router->match('GET', '/api/admin/monitor/cheat-events'));
});

exit($t->finish());
