<?php

declare(strict_types=1);

/**
 * B4 成绩公示 + C1 电子证书 + C2 补考 —— 前端契约与路由可达性
 *
 * 主流程已在 `publish_cert_retake_test.php` 里端到端跑过（含真实 HTTP 链路）。
 * 这里补两类后端测试覆盖不到的东西：
 *
 *   1. **源码契约**：这三项的前端接线（编辑器字段、补考弹窗、成绩榜入口、证书页、
 *      公开核验页、CSS 类）散在 8 个文件里，任一环被误删都不会有后端报错，
 *      只会在真人点击时才发现。静态断言让「删掉接线」立刻变红。
 *
 *   2. **未登录边界**：新路由是否落进了正确的鉴权域 —— 公开核验必须免登录，
 *      其余三个必须 401。路由表本身在 routes_test.php 里查，但「实际鉴权结果」
 *      只能靠真请求验证。
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
echo "== B4/C1/C2 前端契约与路由可达性 ==\n";

$read = static function (string $rel) use ($base): string {
    $p = $base . '/' . ltrim($rel, '/');
    return is_file($p) ? (string) file_get_contents($p) : '';
};

/* ==================================================================
 * 一、API 资源层：四个新通道必须都在 index.js 里
 * ================================================================== */
$t->guard('一 前端 API 层登记齐全', function () use ($t, $read) {
    $api = $read('public/assets/js/api/index.js');
    $t->assertTrue('学生端 scoreBoard', str_contains($api, "scoreBoard: (params) => http.get('/api/student/score-board'"));
    $t->assertTrue('学生端 certificates', str_contains($api, "certificates: () => http.get('/api/student/certificates')"));
    $t->assertTrue('学生端 certificate(id)', str_contains($api, '/student/certificates/${examId}'));
    $t->assertTrue('公开核验 verifyCertificate', str_contains($api, "verifyCertificate: (certNo) => http.get('/api/public/certificates/verify'"));
    $t->assertTrue('教师端 retakeCandidates', str_contains($api, 'retakeCandidates: (id) => http.get(`/api/teacher/exams/${id}/retake-candidates`)'));
    $t->assertTrue('教师端 createRetake', str_contains($api, 'createRetake:     (id, body) => http.post(`/api/teacher/exams/${id}/retake`'));
    $t->assertTrue('管理端 retakeCandidates', str_contains($api, 'retakeCandidates: (id) => http.get(`/api/admin/exams/${id}/retake-candidates`)'));
    $t->assertTrue('管理端 createRetake', str_contains($api, 'createRetake:     (id, body) => http.post(`/api/admin/exams/${id}/retake`'));
});

/* ==================================================================
 * 二、考试编辑器（管理端 / 教师端）都提供公示粒度与证书达标分
 * ================================================================== */
$t->guard('二 双端考试编辑器含公示与证书字段', function () use ($t, $read) {
    foreach ([
        '管理端' => 'public/assets/js/views/admin/exam.js',
        '教师端' => 'public/assets/js/views/teacher/exam-editor.js',
    ] as $who => $rel) {
        $js = $read($rel);
        $t->assertTrue("{$who} 编辑器存在", $js !== '');
        $t->assertTrue("{$who} 含区块标题", str_contains($js, '成绩公示与电子证书'));
        $t->assertTrue("{$who} 提交 score_visibility", str_contains($js, 'score_visibility: scoreVisibility'));
        $t->assertTrue("{$who} 提交 cert_threshold", str_contains($js, 'cert_threshold: Math.max(0, Number(thresholdInput.value) || 0)'));
        $t->assertTrue("{$who} 三档粒度选项齐备", str_contains($js, '仅本人') && str_contains($js, '本班同学') && str_contains($js, '全体考生'));
        $t->assertTrue("{$who} 默认回落 private", str_contains($js, "'private'"));
        $t->assertTrue("{$who} 达标分说明「不发放证书」", str_contains($js, '本场不发放证书'));
    }
});

/* ==================================================================
 * 三、补考：共享弹窗 + 双端接线 + 只有已结束场次才出现入口
 * ================================================================== */
$t->guard('三 补考共享弹窗契约', function () use ($t, $read) {
    $js = $read('public/assets/js/views/retake.js');
    $t->assertTrue('共享模块存在', $js !== '');
    $t->assertTrue('导出 openRetakeDialog', str_contains($js, 'export async function openRetakeDialog'));
    $t->assertTrue('导出 retakeBadge', str_contains($js, 'export function retakeBadge'));
    $t->assertTrue('调用候选名单接口', str_contains($js, 'api.retakeCandidates(examId)'));
    $t->assertTrue('调用生成补考接口', str_contains($js, 'api.createRetake(examId'));
    $t->assertTrue('提交 stu_ids', str_contains($js, 'stu_ids: pickedIds'));
    $t->assertTrue('提交 exam_start/exam_end', str_contains($js, 'exam_start: examStart') && str_contains($js, 'exam_end: examEnd'));
    $t->assertTrue('勾到已通过者才带 allow_passed', str_contains($js, 'allow_passed: allowPassed ? 1 : 0'));
    $t->assertTrue('默认只勾未通过 + 缺考', str_contains($js, "r.state !== 'passed'"));
    $t->assertTrue('时间控件为 datetime-local', str_contains($js, "type: 'datetime-local'"));
    $t->assertTrue('结束早于开始被前端挡住', str_contains($js, '补考结束时间必须晚于开始时间'));
});

$t->guard('三 双端补考入口接线', function () use ($t, $read) {
    $tea = $read('public/assets/js/views/teacher/index.js');
    $adv = $read('public/assets/js/views/admin/exam.js');
    foreach (['教师端' => $tea, '管理端' => $adv] as $who => $js) {
        $t->assertTrue("{$who} 引入 openRetakeDialog", str_contains($js, 'openRetakeDialog'));
        $t->assertTrue("{$who} 传入本端 API", str_contains($js, 'api: teacherApi') || str_contains($js, 'api: adminApi'));
        // 补考只对「已结束」的场次开放：进行中的考试还没判分，谁没通过无从谈起
        $t->assertTrue("{$who} 仅在已结束场次显示入口", str_contains($js, "st.startsWith('over')"));
        $t->assertTrue("{$who} 行内展示补考标签", str_contains($js, 'retakeBadge'));
    }
});

/* ==================================================================
 * 四、学生端：成绩榜入口 / 证书页
 * ================================================================== */
$t->guard('四 我的成绩带成绩榜入口（可见性由服务端定）', function () use ($t, $read) {
    $js = $read('public/assets/js/views/student/center.js');
    $t->assertTrue('读取服务端下发的 can_view_board', str_contains($js, 'r.can_view_board'));
    $t->assertTrue('调用 scoreBoard 接口', str_contains($js, 'studentApi.scoreBoard'));
    $t->assertTrue('渲染榜单行', str_contains($js, 'board-row'));
    $t->assertTrue('标出「我」这一行', str_contains($js, "is_me"));
    $t->assertTrue('显示补考标记', str_contains($js, '补考'));
    $t->assertTrue('显示已发证标记', str_contains($js, '已发证'));
    // 「粒度只在一处判定」：前端若自己按 score_visibility 过滤 rows，就把服务端判定架空了
    $t->assertTrue('前端不自行按粒度过滤', !str_contains($js, "score_visibility ==="));
});

$t->guard('四 我的证书页与导航入口', function () use ($t, $read) {
    $view = $read('public/assets/js/views/student/certificates.js');
    $app = $read('public/assets/js/apps/student.js');
    $t->assertTrue('证书视图存在', $view !== '');
    $t->assertTrue('调证书列表接口', str_contains($view, 'studentApi.certificates()'));
    $t->assertTrue('调单张证书接口', str_contains($view, 'studentApi.certificate('));
    $t->assertTrue('提供打印出口', str_contains($view, 'printCert'));
    $t->assertTrue('提示编号可核验', str_contains($view, '证书核验'));
    $t->assertTrue('NAV 登记 certificates', str_contains($app, "key: 'certificates', label: '我的证书'"));
    $t->assertTrue('VIEWS 绑定证书视图', str_contains($app, 'certificates: StudentCertificatesView'));
    $t->assertTrue('移动标签栏仍为 5 项', substr_count($app, "\n      'exams',\n      'exercise',\n      'wrongbook',\n      'scores',") === 1);
});

/* ==================================================================
 * 五、门户公开核验页
 * ================================================================== */
$t->guard('五 门户证书核验页接线', function () use ($t, $read) {
    $view = $read('public/assets/js/views/cert-verify.js');
    $app = $read('public/assets/js/apps/portal.js');
    $nav = $read('public/assets/js/views/portal.js');
    $t->assertTrue('核验视图存在', $view !== '');
    $t->assertTrue('调用公开核验接口', str_contains($view, 'siteApi.verifyCertificate'));
    $t->assertTrue('未命中给出明确提示', str_contains($view, '未查询到该证书'));
    $t->assertTrue('门户注册 /verify 路由', str_contains($app, "path: '/verify'"));
    $t->assertTrue('门户导航含核验入口', str_contains($nav, "link('#/verify', '证书核验'"));
});

/* ==================================================================
 * 六、CSS：新增区块必须有样式，否则前端渲染成裸文本
 * ================================================================== */
$t->guard('六 新增组件的样式存在', function () use ($t, $read) {
    $css = $read('public/assets/css/components.css');
    foreach (['.retake-list', '.retake-row', '.score-board', '.board-row', '.board-rank',
        '.cert-card', '.cert-head', '.cert-no', '.cert-seal'] as $sel) {
        $t->assertTrue("components.css 含 {$sel}", str_contains($css, $sel . ' ') || str_contains($css, $sel . ',') || str_contains($css, $sel . '{') || str_contains($css, $sel . '.'));
    }
});

/* ==================================================================
 * 七、未登录边界（真请求）
 * ================================================================== */
if (!Harness::dbAvailable()) {
    $t->skip('七 未登录边界', '数据库不可用');
    exit($t->finish());
}

$t->guard('七 公开核验免登录 · 其余三处 401', function () use ($t) {
    // 公开核验：未登录也必须 200，且用 valid=false 表达「查不到」而不是 401/404
    $pub = Http::get('/api/public/certificates/verify?cert_no=CT20000101DEADBEEFDEADBEEFDEADBEEFDEADBEEF');
    $t->assertSame('公开核验 -> 200', 200, $pub['status']);
    $t->assertSame('valid=false', false, (bool) (Http::data($pub)['valid'] ?? true));

    $t->assertSame('缺参数也 200', 200, Http::get('/api/public/certificates/verify')['status']);

    // 学生端两处：未登录一律 401
    $t->assertSame('score-board -> 401', 401, Http::get('/api/student/score-board?exam_id=1')['status']);
    $t->assertSame('我的证书 -> 401', 401, Http::get('/api/student/certificates')['status']);
    $t->assertSame('单张证书 -> 401', 401, Http::get('/api/student/certificates/1')['status']);

    // 补考生成：写接口，未登录 401（CSRF 也不该先于鉴权生效）
    $t->assertSame('管理端补考候选 -> 401', 401, Http::get('/api/admin/exams/1/retake-candidates')['status']);
    $t->assertSame('管理端生成补考 -> 401', 401, Http::post('/api/admin/exams/1/retake', [
        'stu_ids' => ['1'], 'exam_start' => '2026-01-01 10:00:00', 'exam_end' => '2026-01-01 11:00:00',
    ])['status']);
    $t->assertSame('教师端补考候选 -> 401', 401, Http::get('/api/teacher/exams/1/retake-candidates')['status']);
});

exit($t->finish());
