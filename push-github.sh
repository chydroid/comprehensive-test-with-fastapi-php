#!/usr/bin/env bash
# 非交互推送到 GitHub（免 GCM 弹窗）
#
# 凭据来源：GITHUB_PAT 环境变量（只在该进程内使用，不写入 .git/config、不落盘）
# 用法:      GITHUB_PAT=<你的PAT> bash push-github.sh [branch]
#
# 说明：先清空 credential.helper 再挂一个只回显用户名/密码的匿名 helper，
# 从而完全绕开 Git Credential Manager 的交互窗口。
set -euo pipefail

BRANCH="${1:-master}"

if [ -z "${GITHUB_PAT:-}" ]; then
  echo "错误：未设置 GITHUB_PAT 环境变量，无法免交互推送。" >&2
  echo "用法：GITHUB_PAT=<你的PAT> bash push-github.sh [branch]" >&2
  exit 1
fi

export GIT_TERMINAL_PROMPT=0
export GIT_ASKPASS=echo
export GCM_INTERACTIVE=never

cd "$(dirname "$0")"

git -c credential.helper= \
    -c credential.helper='!f() { echo username=x-access-token; echo password="$GITHUB_PAT"; }; f' \
    push github "$BRANCH"
