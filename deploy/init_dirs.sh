#!/usr/bin/env bash
#
# 初始化运行时目录与权限（幂等，可反复执行）
#
# 背景：storage/cache、storage/logs、public/uploads 三个目录的 .gitkeep 未纳入
# 版本库，git clone 后它们并不存在，必须手工创建并交给 PHP 运行用户，否则
# 缓存/日志/上传都会在运行时报权限错误。
#
# 用法:
#   bash deploy/init_dirs.sh                      # WEB_USER 默认 www
#   WEB_USER=nginx bash deploy/init_dirs.sh       # 指定 PHP 运行用户
#   bash deploy/init_dirs.sh /www/wwwroot/exam    # 指定项目根
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="${1:-$(cd "$SCRIPT_DIR/.." && pwd)}"
WEB_USER="${WEB_USER:-www}"

if [ ! -d "$ROOT" ]; then
  echo "❌ 项目根不存在: $ROOT" >&2
  exit 1
fi

# PHP-FPM 运行用户可能叫 www / nginx / apache / www-data，自动挑一个存在的
if ! id "$WEB_USER" >/dev/null 2>&1; then
  for cand in www www-data nginx apache; do
    if id "$cand" >/dev/null 2>&1; then
      echo "ℹ️  用户 $WEB_USER 不存在，改用 $cand"
      WEB_USER="$cand"
      break
    fi
  done
fi

cd "$ROOT"

echo "项目根: $ROOT"
echo "运行用户: $WEB_USER"
echo

# 1. 目录
mkdir -p storage/cache storage/logs public/uploads

# 2. 属主：只动运行时目录，不动代码（代码保持 root 属主更安全）
#    失败不中断（非 root / 非 Linux 环境），后面会再核验可写性
if ! chown -R "$WEB_USER":"$WEB_USER" storage public/uploads 2>/dev/null; then
  echo "⚠️  chown 失败：请以 root 执行，或确认 PHP-FPM 运行用户是 $WEB_USER"
fi

# 3. 权限：目录 775（组可写），文件 664
find storage public/uploads -type d -exec chmod 775 {} \;
find storage public/uploads -type f -exec chmod 664 {} \;

# 4. 敏感文件收紧（若已存在）
[ -f .env ] && chmod 600 .env || true

echo "✅ 目录与权限已就绪："
for d in storage/cache storage/logs public/uploads; do
  if [ -w "$d" ]; then
    printf '   %-20s 可写  %s\n' "$d" "$(stat -c '%U:%G %a' "$d" 2>/dev/null || echo "$WEB_USER")"
  else
    printf '   %-20s ⚠️  当前用户不可写（Web 用户是 %s）\n' "$d" "$WEB_USER"
  fi
done

echo
echo "提示：确认 PHP-FPM 的运行用户与上面一致（宝塔通常是 www）。"
echo "      ps aux | grep php-fpm | head -3"
