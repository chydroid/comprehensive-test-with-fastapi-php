#!/usr/bin/env bash
# 非交互推送脚本（免 GCM 弹窗）
# 用法: bash push.sh [branch]
set -euo pipefail
BRANCH="${1:-master}"
export GIT_TERMINAL_PROMPT=0
export GIT_ASKPASS=echo
cd "$(dirname "$0")"
git -c credential.helper= -c credential.helper=store push origin "$BRANCH"
