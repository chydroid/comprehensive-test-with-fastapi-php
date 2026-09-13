<?php

declare(strict_types=1);

namespace Core;

/**
 * 轻量进程内指标（Prometheus 文本格式）
 *
 * - inc/gauge 收集计数与仪表，render 输出 Prometheus 格式（含 # TYPE）
 * - 进程内累加：在 Swoole/RoadRunner 常驻模式下汇聚整个生命周期；
 *   PHP-FPM 多 worker 下各进程独立（跨进程聚合建议改用 Redis/APCu 原子计数，属生产化下一步）
 */
final class Metrics
{
    /** @var array<string, array<string, float>> name => (labelKey => value) */
    private static array $counters = [];
    /** @var array<string, array<string, float>> */
    private static array $gauges = [];
    /** @var array<string, array<string, array>> name => (labelKey => labels) */
    private static array $labelSets = [];

    /** 计数器累加 */
    public static function inc(string $name, array $labels = [], float $delta = 1.0): void
    {
        $k = self::key($labels);
        self::$counters[$name][$k] = (self::$counters[$name][$k] ?? 0) + $delta;
        self::$labelSets[$name][$k] = $labels;
    }

    /** 仪表（set 语义，后写覆盖） */
    public static function gauge(string $name, float $value, array $labels = []): void
    {
        $k = self::key($labels);
        self::$gauges[$name][$k] = $value;
        self::$labelSets[$name][$k] = $labels;
    }

    /** 记录一次请求：总量 + 时长（sum/count 供 Prometheus 算平均/百分位） */
    public static function recordRequest(string $method, string $path, int $status, float $duration): void
    {
        self::inc('http_requests_total', ['method' => $method, 'path' => $path, 'status' => (string) $status]);
        self::inc('http_request_duration_seconds_sum', ['method' => $method, 'path' => $path], $duration);
        self::inc('http_request_duration_seconds_count', ['method' => $method, 'path' => $path]);
        if ($status >= 500) {
            self::inc('app_errors_total', ['type' => 'server']);
        }
    }

    /** 输出 Prometheus 文本格式 */
    public static function render(): string
    {
        $lines = [];
        $types = [['TYPE' => 'counter', 'data' => self::$counters], ['TYPE' => 'gauge', 'data' => self::$gauges]];
        foreach ($types as $group) {
            foreach ($group['data'] as $name => $byKey) {
                $lines[] = "# TYPE $name " . $group['TYPE'];
                foreach ($byKey as $k => $v) {
                    $labels = self::$labelSets[$name][$k] ?? [];
                    $lines[] = $name . self::labelStr($labels) . ' ' . self::fmt($v);
                }
            }
        }
        return implode("\n", $lines) . "\n";
    }

    private static function key(array $labels): string
    {
        ksort($labels);
        return md5(json_encode($labels));
    }

    private static function labelStr(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $name => $value) {
            $parts[] = $name . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $value) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function fmt(float $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
    }
}
