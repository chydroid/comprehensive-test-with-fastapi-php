<?php

declare(strict_types=1);

/**
 * 重置并初始化数据库：DROP + CREATE + 应用 database/init.sql（保证 schema 与 init.sql 一致）
 * ⚠️ 会删除并重建数据库（开发/测试/CI 用；请勿指向生产库）
 * 用 PDO 实现，CI 与本地无需安装 mysql 客户端。
 *
 * 用法: php bin/setup_db.php
 */

require dirname(__DIR__) . '/core/helpers.php';
bootstrap();

$db = config('database');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['port']),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$name = $db['name'];
$pdo->exec("DROP DATABASE IF EXISTS `$name`");
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`");

$sql = (string) file_get_contents(BASE_PATH . '/database/init.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)), static fn ($s) => $s !== '');

$applied = 0;
foreach ($statements as $stmt) {
    $head = strtoupper(ltrim($stmt));
    // 库/连接已由本脚本处理，跳过 init.sql 里的 CREATE DATABASE / USE
    if (str_starts_with($head, 'CREATE DATABASE') || str_starts_with($head, 'USE ')) {
        continue;
    }
    $pdo->exec($stmt);
    $applied++;
}

echo "数据库 `$name` 已重置并初始化，应用 {$applied} 条语句\n";
