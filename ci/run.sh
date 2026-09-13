#!/usr/bin/env bash
# ============================================================
# CI 统一入口：语法检查 + 初始化数据库 + 运行全部测试
# 本地与 CI（GitHub Actions / Gitee Go 等）均可复用
# 用法: bash ci/run.sh
# 依赖环境变量（可选，用于覆盖 .env 或环境）：DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS
# ============================================================
set -euo pipefail
cd "$(dirname "$0")/.."

echo "== PHP 版本 =="
command -v php >/dev/null || { echo "缺少 php 命令"; exit 1; }
php -v | head -1

echo "== 语法检查 =="
failed=0
while IFS= read -r f; do
  if ! php -l "$f" >/dev/null 2>&1; then
    echo "语法错误: $f"
    failed=1
  fi
done < <(find core app config database public bin -name '*.php' -type f)
if [ "$failed" -ne 0 ]; then exit 1; fi
echo "语法检查通过"

echo "== 初始化数据库 =="
php bin/setup_db.php

echo "== 运行全部测试 =="
php test/run_all.php

echo "== CI 全部通过 =="
