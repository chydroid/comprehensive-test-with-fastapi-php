<?php

declare(strict_types=1);

/**
 * 后台写操作「权限点推导」回归测试。
 *
 * 背景：SessionAuthMiddleware::writePoint() 会把未显式登记的写操作按
 * 「POST → <模块>.add / PUT → <模块>.edit / DELETE → <模块>.delete」推导权限点。
 * 一旦某个控制类动作漏登记，就会被推导成一个语义不符的权限点。
 *
 *   BUG-238 单个考生强制交卷 POST /api/admin/monitor/submit-one 漏登记，
 *           被推导成 monitor.add。而 testAdmin 只被授予 monitor.view +
 *           monitor.control，于是监考页行内「收卷」恒 403，同页「全部收卷」
 *           （/monitor/submit，显式 monitor.control）却正常 —— 形成
 *           「能收全场、收不了单人」的怪象。
 *   BUG-239 开放入场 POST /api/admin/exams/{id}/open 漏登记，被推导成
 *           exam.add（新增考试）。它实际只是写入考场口令，属考试信息更新。
 *
 * 这里直接对中间件的私有方法 writePoint() 做断言，锁定推导结果：
 * 任一断言失败即代表对应缺陷回归。
 *
 * 注意：用闭包而非具名函数，避免与其它用例文件在同进程内重名冲突。
 */

require __DIR__ . '/../../core/helpers.php';

use App\Middlewares\SessionAuthMiddleware;
use Test\Harness;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';

bootstrap();

$t = new Harness();
echo "== 后台权限点推导测试 ==\n";

/** 调用中间件私有方法 writePoint()，得到该写操作实际要求的权限点 */
$derivedPoint = static function (string $method, string $path, string $readPoint): ?string {
    $ref = new \ReflectionMethod(SessionAuthMiddleware::class, 'writePoint');
    $ref->setAccessible(true);
    return $ref->invoke(new SessionAuthMiddleware(), $method, $path, $readPoint);
};

/** 角色是否被授予该权限点（与中间件 can() 同规则，含 '*' 与 '模块.*' 通配） */
$roleHas = static function (string $role, string $point): bool {
    $granted = (array) (config('rbac.' . $role) ?? []);
    if ($granted === []) {
        return false;
    }
    if (in_array('*', $granted, true) || in_array($point, $granted, true)) {
        return true;
    }
    return in_array(explode('.', $point)[0] . '.*', $granted, true);
};

/* ==================== BUG-238 单个考生强制交卷 ==================== */
$one = $derivedPoint('POST', '/api/admin/monitor/submit-one', 'monitor.view');
$t->assertSame(
    'BUG-238 单个强制交卷要求 monitor.control（而非推导出的 monitor.add）',
    'monitor.control',
    $one
);
$t->assertTrue(
    'BUG-238 testAdmin 具备「单个强制交卷」所需权限点',
    $roleHas('testAdmin', (string) $one)
);

/* ==================== BUG-239 开放入场 ==================== */
$open = $derivedPoint('POST', '/api/admin/exams/{id}/open', 'exam.view');
$t->assertSame(
    'BUG-239 开放入场要求 exam.edit（而非推导出的 exam.add）',
    'exam.edit',
    $open
);
$t->assertTrue(
    'BUG-239 testAdmin 具备「开放入场」所需权限点',
    $roleHas('testAdmin', (string) $open)
);

/* ============ 相邻契约：显式登记项未被推导逻辑覆盖 ============ */
$t->assertSame(
    '全员收卷仍为 monitor.control',
    'monitor.control',
    $derivedPoint('POST', '/api/admin/monitor/submit', 'monitor.view')
);
$t->assertSame(
    '开考仍为 exam.start',
    'exam.start',
    $derivedPoint('POST', '/api/admin/exams/{id}/start', 'exam.view')
);
$t->assertSame(
    '导出成绩为 score.export（GET 敏感操作不被只读点放行）',
    'score.export',
    $derivedPoint('GET', '/api/admin/scores/export', 'score.view')
);
$t->assertSame(
    '新建考试仍按推导为 exam.add',
    'exam.add',
    $derivedPoint('POST', '/api/admin/exams', 'exam.view')
);

$t->finish();
