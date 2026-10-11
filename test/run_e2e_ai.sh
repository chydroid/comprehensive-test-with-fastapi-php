#!/usr/bin/env bash
# AI 功能端到端实测：起 dev server → 跑 HTTP 实测 → pkill 收尾
# 必须三者写在同一次 bash 调用内（沙箱后台进程随调用结束被回收）。
set -u
export PATH="/usr/bin:/bin:/c/Windows/System32:/d/BtSoft/php/83:$PATH"
cd "$(dirname "$0")/.."

pkill -f "php -S 127.0.0.1:8099" 2>/dev/null
php -S 127.0.0.1:8099 -t public public/router.php > /tmp/e2e_srv.log 2>&1 &
SRV=$!
sleep 2

php test/e2e_ai_check.php
RC=$?

echo ""
echo "════ 后端 provider 判定（独立进程，读库真实配置）════"
php -r '
require "core/helpers.php"; bootstrap();
$g = \App\Services\Grading\GraderFactory::providerInfo();
$c = \App\Services\Composition\ComposerFactory::providerInfo();
echo "  阅卷 grading  => ", json_encode($g, JSON_UNESCAPED_UNICODE), "\n";
echo "  组卷 compose  => ", json_encode($c, JSON_UNESCAPED_UNICODE), "\n";
use App\Services\Setting;
echo "  开关 ai_grading_enabled = ", var_export(Setting::bool("ai_grading_enabled", false), true), "\n";
echo "  endpoint  = ", var_export(Setting::get("ai_grading_endpoint", ""), true), "\n";
echo "  model     = ", var_export(Setting::get("ai_grading_model", ""), true), "\n";
echo "  api_key   = ", Setting::get("ai_grading_api_key", "") === "" ? "(空)" : "(已配置)", "\n";
echo "  curl 可用 = ", function_exists("curl_init") ? "是" : "否", "\n";
'

pkill -f "php -S 127.0.0.1:8099" 2>/dev/null
exit $RC
