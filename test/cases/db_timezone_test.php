<?php

declare(strict_types=1);

/**
 * 数据库会话时区对齐回归测试（防 CI 时区错位类失败）。
 *
 * 背景：应用以 PHP（app.timezone，默认 Asia/Shanghai）写入裸 datetime 字符串，
 * 而过期判定等核心逻辑用 MySQL NOW() 比较。若 MySQL 会话时区 ≠ PHP 时区
 * （如容器化 MySQL 默认 UTC），所有 exam_end > NOW() / exam_end <= NOW() 比较
 * 会整体偏移数小时，导致"考试是否过期"等关键判断失真——曾致全功能实测（四层）
 * CI 失败（exam_expiry 18 FAIL / wrong_book 1 FAIL）。
 *
 * 修复：Database::newPdo 在连接建立后强制 SET time_zone 为与 app.timezone 对齐的
 * 偏移量。本测试断言该会话时区确实生效，避免修复被无意回退。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';

bootstrap();

use Core\Database;
use Test\Harness;

$t = new Harness();
echo "== 数据库会话时区对齐回归 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('时区对齐', '数据库不可用');
    exit($t->finish());
}

/** 与 Core\Database 内 sessionTimeZone() 保持一致的偏移量计算 */
$expectedOffset = static function (): string {
    $tzName = (string) (config('app.timezone', 'UTC') ?? 'UTC');
    try {
        $tz = new \DateTimeZone($tzName);
    } catch (\Throwable $e) {
        $tz = new \DateTimeZone('UTC');
    }
    $offsetSec = $tz->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
    $sign = $offsetSec >= 0 ? '+' : '-';
    $abs = abs($offsetSec);
    return sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
};

$t->guard('DB 会话时区与 app.timezone 对齐', function () use ($t, $expectedOffset) {
    $row = Database::fetch('SELECT @@session.time_zone AS tz');
    $actual = (string) ($row['tz'] ?? '');
    $expected = $expectedOffset();
    $t->assertSame('会话时区 = 预期偏移（如 +08:00）', $expected, $actual);
});

$t->guard('NOW() 与 PHP 当前时间在同一时区（偏差 < 5 分钟）', function () use ($t) {
    $row = Database::fetch('SELECT NOW() AS n');
    $dbNow = strtotime((string) ($row['n'] ?? ''));
    $phpNow = time();
    $t->assertTrue('DB NOW() 与 PHP time() 偏差 < 300s', abs($dbNow - $phpNow) < 300,
        'db=' . ($row['n'] ?? '') . ' diff=' . ($dbNow - $phpNow));
});

exit($t->finish());
