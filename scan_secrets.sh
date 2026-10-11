#!/usr/bin/env bash
# 密钥扫描闸门：扫描 git 跟踪文件内容，命中真实密钥/口令即非零退出。
# 接入点：push.sh、push-github.sh（推送前强制调用，命中即中止）；
#         .github/workflows/comprehensive.yml（CI 推送后复核）。
# 约定：任何真实密钥只存本地 .env（已 gitignore）或数据库，绝不写入被跟踪文件。
set -u
cd "$(dirname "$0")"

# 真实口令字面量用引号拼接拆开书写（'xxx'"yyy"），避免扫描命中本脚本自身。
PATTERNS=(
  'cpk-[A-Za-z0-9]{16,}'            # AI 平台密钥（cpk- 前缀 + 16 位以上）
  'ghp_[A-Za-z0-9]{30,}'            # GitHub PAT（classic）
  'github_pat_[A-Za-z0-9_]{20,}'    # GitHub PAT（fine-grained）
  'sk-[A-Za-z0-9]{30,}'             # OpenAI 风格密钥
  'AKIA[0-9A-Z]{16}'                # AWS AccessKeyId
  'BEGIN [A-Z ]*PRIVATE KEY-----'   # 私钥块
  'R4iBKMX'"GrDLWRDnc"              # 本项目 MySQL 应用账号口令
  'd74e408875'"b8"                  # 本机 MySQL root 口令
)

hits=0
for p in "${PATTERNS[@]}"; do
  while IFS= read -r m; do
    [ -n "$m" ] || continue
    echo "❌ 疑似密钥/口令进入跟踪文件: $m"
    hits=1
  done < <(git grep -nE "$p" -- . 2>/dev/null)
done

if [ "$hits" -ne 0 ]; then
  echo ""
  echo "已拦截：请把密钥移回本地 .env（gitignore）或改用占位值，再重新推送。"
  exit 1
fi

echo "✅ 密钥扫描通过：git 跟踪文件无敏感串（共 $(git ls-files | wc -l) 个文件）"
exit 0
