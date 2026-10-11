#!/usr/bin/env bash
# 非交互推送脚本（免 GCM 弹窗）
# 用法: bash push.sh [branch]
set -euo pipefail
BRANCH="${1:-master}"
export GIT_TERMINAL_PROMPT=0
export GIT_ASKPASS=echo
cd "$(dirname "$0")"
# 密钥扫描闸门：跟踪文件命中真实密钥/口令即中止推送（防上传到公开远端）
bash scan_secrets.sh || { echo "推送已中止。" >&2; exit 1; }
git -c credential.helper= -c credential.helper=store push origin "$BRANCH"
