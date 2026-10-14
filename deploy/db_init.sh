#!/usr/bin/env bash
#
# 数据库初始化：建库建账号 / 导入 27 张表结构 / 清空种子数据
#
# 结构来源：db/ci_bootstrap.sql（最新全量结构，27 张表）。
# 该文件开头两行是 "CREATE DATABASE `csip_exam`" 与 "USE `csip_exam`"，
# 本脚本会剥掉它们，再导入到你指定的库 —— 这样用普通应用账号也能导入，
# 不需要 MySQL root 权限。
#
# 用法:
#   # ① 导入表结构（最常用）
#   bash deploy/db_init.sh --db=comprehensive_test --user=<账号> --pass=<密码>
#
#   # ② 建库 + 建账号 + 授权（需要 root）
#   bash deploy/db_init.sh --create --root-pass=<root密码> \
#        --db=comprehensive_test --user=<新账号> --pass=<新密码>
#
#   # ③ 导入结构后清空种子数据（迁移真实数据前必做，避免主键冲突）
#   bash deploy/db_init.sh --db=comprehensive_test --user=<账号> --pass=<密码> --empty
#
#   # 只清空，不再导结构
#   bash deploy/db_init.sh --db=comprehensive_test --user=<账号> --pass=<密码> --empty-only
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# ---- 默认值 ----
DB_NAME="comprehensive_test"
DB_USER=""
DB_PASS=""
DB_HOST="127.0.0.1"
DB_PORT="3306"
ROOT_USER="root"
ROOT_PASS=""
DO_CREATE=0
DO_IMPORT=1
DO_EMPTY=0
EXPECTED_TABLES=27

# 打印文件头部注释块作为帮助（遇到第一行非注释即停）
show_help() {
  awk 'NR==1{next} /^#/{sub(/^# ?/,""); print; next} {exit}' "$0"
}

for arg in "$@"; do
  case "$arg" in
    --help|-h)      show_help; exit 0 ;;
    --db=*)         DB_NAME="${arg#*=}" ;;
    --user=*)       DB_USER="${arg#*=}" ;;
    --pass=*)       DB_PASS="${arg#*=}" ;;
    --host=*)       DB_HOST="${arg#*=}" ;;
    --port=*)       DB_PORT="${arg#*=}" ;;
    --root-user=*)  ROOT_USER="${arg#*=}" ;;
    --root-pass=*)  ROOT_PASS="${arg#*=}" ;;
    --create)       DO_CREATE=1 ;;
    --empty)        DO_EMPTY=1 ;;
    --empty-only)   DO_EMPTY=1; DO_IMPORT=0 ;;
    *)  echo "❌ 未知参数: $arg（用 --help 看用法）" >&2; exit 1 ;;
  esac
done

# ---- 定位 mysql 客户端（宝塔常在 /www/server/mysql/bin）----
MYSQL=""
for cand in mysql /www/server/mysql/bin/mysql /usr/local/mysql/bin/mysql; do
  if command -v "$cand" >/dev/null 2>&1; then MYSQL="$cand"; break; fi
done
if [ -z "$MYSQL" ]; then
  echo "❌ 找不到 mysql 客户端。宝塔可软链：ln -s /www/server/mysql/bin/mysql /usr/bin/mysql" >&2
  exit 1
fi

# 密码走环境变量 MYSQL_PWD，避免出现在 ps / history 里
run_sql() { # run_sql <user> <pass> <sql> [database]
  local u="$1" p="$2" sql="$3" db="${4:-}"
  MYSQL_PWD="$p" "$MYSQL" -h "$DB_HOST" -P "$DB_PORT" -u "$u" \
      --default-character-set=utf8mb4 -N -B ${db:+"$db"} -e "$sql"
}

echo "数据库: $DB_NAME @ $DB_HOST:$DB_PORT"
echo

# ---------- ① 建库 + 建账号 ----------
if [ "$DO_CREATE" = 1 ]; then
  if [ -z "$ROOT_PASS" ]; then
    read -rsp "MySQL $ROOT_USER 密码: " ROOT_PASS; echo
  fi
  if [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
    echo "❌ --create 需要同时给出 --user 与 --pass" >&2; exit 1
  fi
  echo "▸ 创建数据库 $DB_NAME (utf8mb4)"
  run_sql "$ROOT_USER" "$ROOT_PASS" \
    "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

  echo "▸ 创建账号 $DB_USER 并授权"
  exists=$(run_sql "$ROOT_USER" "$ROOT_PASS" \
    "SELECT COUNT(*) FROM mysql.user WHERE user='$DB_USER' AND host IN ('localhost','127.0.0.1');")
  if [ "${exists:-0}" = "0" ]; then
    run_sql "$ROOT_USER" "$ROOT_PASS" \
      "CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; \
       CREATE USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
  else
    echo "  账号已存在，跳过创建（如需改密请自行 SET PASSWORD）"
  fi
  run_sql "$ROOT_USER" "$ROOT_PASS" \
    "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; \
     GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1'; \
     FLUSH PRIVILEGES;"
  echo "✅ 库与账号就绪"
  echo
fi

if [ -z "$DB_USER" ]; then
  echo "❌ 缺少 --user（应用账号）" >&2; exit 1
fi
if [ -z "$DB_PASS" ]; then
  read -rsp "数据库用户 $DB_USER 的密码: " DB_PASS; echo
fi

# ---------- ② 导入表结构 ----------
if [ "$DO_IMPORT" = 1 ]; then
  SRC="$ROOT/db/ci_bootstrap.sql"
  [ -f "$SRC" ] || { echo "❌ 找不到 $SRC" >&2; exit 1; }

  TMP="$(mktemp /tmp/exam_struct_XXXXXX.sql)"
  trap 'rm -f "$TMP"' EXIT
  # 剥掉 CREATE DATABASE / USE，其余原样保留
  grep -v -E '^(CREATE DATABASE|USE )' "$SRC" > "$TMP"

  echo "▸ 导入表结构 → $DB_NAME（$(wc -l < "$TMP") 行 SQL）"
  MYSQL_PWD="$DB_PASS" "$MYSQL" -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
      --default-character-set=utf8mb4 --database="$DB_NAME" < "$TMP"

  n=$(run_sql "$DB_USER" "$DB_PASS" \
      "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" )
  echo "▸ 当前表数量: $n（应为 $EXPECTED_TABLES）"
  if [ "$n" != "$EXPECTED_TABLES" ]; then
    echo "⚠️  表数量不是 $EXPECTED_TABLES，请检查上面的导入报错"
  fi
  echo "✅ 结构导入完成"
  echo
fi

# ---------- ③ 清空种子数据 ----------
if [ "$DO_EMPTY" = 1 ]; then
  echo "▸ 清空 $DB_NAME 所有表的数据（保留结构）"
  run_sql "$DB_USER" "$DB_PASS" "SET FOREIGN_KEY_CHECKS=0;"
  tables=$(run_sql "$DB_USER" "$DB_PASS" \
    "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_NAME';")
  cnt=0
  for t in $tables; do
    run_sql "$DB_USER" "$DB_PASS" "TRUNCATE TABLE \`$t\`;" "$DB_NAME" >/dev/null
    cnt=$((cnt+1))
  done
  run_sql "$DB_USER" "$DB_PASS" "SET FOREIGN_KEY_CHECKS=1;"
  echo "✅ 已清空 $cnt 张表，库现在是干净的空结构（可安全导入真实数据）"
fi

echo
echo "下一步："
echo "  1) 把 DB_NAME/DB_USER/DB_PASS 写进 .env"
echo "  2) bash deploy/db_migrate.sh import --db=$DB_NAME --user=$DB_USER --file=<数据文件>"
