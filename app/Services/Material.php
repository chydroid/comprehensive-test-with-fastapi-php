<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * C3 学习资料库（轻量版）。
 *
 * 定位边界：**不做 LMS**。没有课程、章节、学习进度、学时、测验绑定 —— 那些东西
 * 会把「理论考核系统」拽成「在线教育平台」，与本项目定位不符。这里只解决一个
 * 很具体的问题：教师发了课件，考生得有个地方能找到、能下载。
 *
 * 因此只有三件事：按科目/分类浏览、下载（记浏览量）、后台增删改。
 *
 * 文件安全：上传只放行白名单扩展名（见 config upload.allowed_doc_ext），
 * 保存时丢弃原始文件名、改用随机名 —— 否则「讲义.php」会被 Web 服务器当成
 * 脚本执行，等于给站点开了一个任意代码上传的后门。
 */
final class Material
{
    /** 列表（管理端与考生端共用同一口径，只是可见性不同） */
    public static function list(array $filters, int $offset, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['subj_id'])) {
            $where[] = 'm.subj_id = ?';
            $params[] = (int) $filters['subj_id'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'm.category = ?';
            $params[] = (string) $filters['category'];
        }
        $kw = trim((string) ($filters['keyword'] ?? ''));
        if ($kw !== '') {
            $where[] = '(m.title LIKE ? OR m.summary LIKE ?)';
            $params[] = '%' . $kw . '%';
            $params[] = '%' . $kw . '%';
        }

        $sql = implode(' AND ', $where);
        $rows = Database::fetchAll(
            "SELECT m.*, s.subj_name
             FROM `material` m
             LEFT JOIN `subject` s ON s.id = m.subj_id
             WHERE {$sql}
             ORDER BY m.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $total = (int) (Database::fetch(
            "SELECT COUNT(*) AS c FROM `material` m WHERE {$sql}",
            $params
        )['c'] ?? 0);

        return [
            'data'  => array_map([self::class, 'shape'], $rows),
            'total' => $total,
        ];
    }

    /** 现有分类（用于筛选下拉，不单独建分类表） */
    public static function categories(): array
    {
        $rows = Database::fetchAll(
            'SELECT category, COUNT(*) AS c FROM `material`
             WHERE category <> \'\' GROUP BY category ORDER BY c DESC, category ASC'
        );
        return array_map(static fn (array $r): array => [
            'name'  => (string) $r['category'],
            'count' => (int) $r['c'],
        ], $rows);
    }

    public static function find(int $id): ?array
    {
        $row = Database::fetch('SELECT * FROM `material` WHERE id = ?', [$id]);
        return $row === null ? null : self::shape($row);
    }

    /** @return array<string,mixed> */
    public static function create(array $data): array
    {
        $row = [
            'title'     => mb_substr(trim((string) ($data['title'] ?? '')), 0, 200),
            'subj_id'   => (int) ($data['subj_id'] ?? 0),
            'category'  => mb_substr(trim((string) ($data['category'] ?? '')), 0, 60),
            'summary'   => mb_substr(trim((string) ($data['summary'] ?? '')), 0, 500),
            'file_name' => mb_substr(trim((string) ($data['file_name'] ?? '')), 0, 255),
            'file_url'  => mb_substr(trim((string) ($data['file_url'] ?? '')), 0, 255),
            'file_ext'  => mb_substr(trim((string) ($data['file_ext'] ?? '')), 0, 16),
            'file_size' => max(0, (int) ($data['file_size'] ?? 0)),
            'uploader'  => mb_substr(trim((string) ($data['uploader'] ?? '')), 0, 64),
        ];
        Database::query(
            'INSERT INTO `material` (title, subj_id, category, summary, file_name, file_url, file_ext, file_size, uploader)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values($row)
        );
        $id = (int) Database::lastInsertId();
        return self::find($id) ?? [];
    }

    public static function delete(int $id): bool
    {
        Database::query('DELETE FROM `material` WHERE id = ?', [$id]);
        return true;
    }

    /** 浏览量 +1。用于「哪些资料真的有人看」的朴素观测，不做去重。 */
    public static function hit(int $id): void
    {
        Database::query('UPDATE `material` SET hits = hits + 1 WHERE id = ?', [$id]);
    }

    /** 统一输出形状：大小转成人类可读，扩展名小写 */
    private static function shape(array $r): array
    {
        return [
            'id'        => (int) $r['id'],
            'title'     => (string) ($r['title'] ?? ''),
            'subj_id'   => (int) ($r['subj_id'] ?? 0),
            'subj_name' => (string) ($r['subj_name'] ?? ''),
            'category'  => (string) ($r['category'] ?? ''),
            'summary'   => (string) ($r['summary'] ?? ''),
            'file_name' => (string) ($r['file_name'] ?? ''),
            'file_url'  => (string) ($r['file_url'] ?? ''),
            'file_ext'  => strtolower((string) ($r['file_ext'] ?? '')),
            'file_size' => (int) ($r['file_size'] ?? 0),
            'size_text' => self::humanSize((int) ($r['file_size'] ?? 0)),
            'uploader'  => (string) ($r['uploader'] ?? ''),
            'hits'      => (int) ($r['hits'] ?? 0),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ];
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }
}
