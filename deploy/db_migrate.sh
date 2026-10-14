#!/usr/bin/env bash
#
# 数据迁移：从现有系统导出 → 导入到新库 → 逐表行数校验
#
# 三个子命令：
#   dump    在【源服务器】执行，导出纯数据（--no-create-info，表结构由 db_init.sh 负责）
#   import  在【新服务器】执行，把 dump 出来的数据灌进目标库
#   verify  两边各跑一次，diff 比对行数是否一致
#
# 用法:
#   # 源服务器：导出
#   bash deploy/db_migrate.sh dump --db=csip_exam --user=root --out=/root/exam_data.sql
#   bash deploy/db_migrate.sh dump --db=csip_exam --user=root --out=/root/d.sql --ignore=admin_log
#
#   # 新服务器：导入
#   bash deploy/db_migrate.sh import --db=comprehensive_test --user=<账号> --pass=<密码> --file=/root/exam_data.sql
#
#   # 两边各跑一次再比对
#   bash deploy/db_migrate.sh verify --db=csip_exam --user=root        > /tmp/src.txt
#   bash deploy/db_migrate.sh verify --db=comprehensive_test --user=<账号> > /tmp/dst.txt
#   diff /tmp/src.txt /tmp/dst.txt && echo "数据一致"
#
set -euo pipefail

# 打印文件头部注释块作为帮助（遇到第一行非注释即停）
show_help() {
  awk 'NR==1{next} /^#/{sub(/^# ?/,""); print; next} {exit}' "$0"
}

# --help 可能是第一个参数（此时它还没被当作子命令），必须先于 shift 处理
if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
  show_help
  exit 0
fi

CMD="${1:-}"
[ $# -gt 0 ] && shift || true

DB_NAME="comprehensive_test"
DB_USER=""
DB_PASS=""
DB_HOST="127.0.0.1"
DB_PORT="3306"
OUT=""
FILE=""
IGNORE=""

for arg in "$@"; do
  case "$arg" in
    --help|-h)   show_help; exit 0 ;;
    --db=*)      DB_NAME="${arg#*=}" ;;
    --user=*)    DB_USER="${arg#*=}" ;;
    --pass=*)    DB_PASS="${arg#*=}" ;;
    --host=*)    DB_HOST="${arg#*=}" ;;
    --port=*)    DB_PORT="${arg#*=}" ;;
    --out=*)     OUT="${arg#*=}" ;;
    --file=*)    FILE="${arg#*=}" ;;
    --ignore=*)  IGNORE="${arg#*=}" ;;
    *)  echo "❌ 未知参数: $arg（用 --help 看用法）" >&2; exit 1 ;;
  esac
done

BIN_DIR=""
for d in /usr/bin /www/server/mysql/bin /usr/local/mysql/bin; do
  [ -d "$d" ] && BIN_DIR="$d" && break
done
MYSQL="$BIN_DIR/mysql"
MYSQLDUMP="$BIN_DIR/mysqldump"
[ -x "$MYSQL" ] || MYSQL="$(command -v mysql || true)"
[ -x "$MYSQLDUMP" ] || MYSQLDUMP="$(command -v mysqldump || true)"

if [ -z "$MYSQL" ] || [ -z "$MYSQLDUMP" ]; then
  echo "❌ 找不到 mysql / mysqldump 客户端" >&2; exit 1
fi

ask_pass() {
  if [ -z "$DB_PASS" ]; then
    read -rsp "数据库用户 ${DB_USER} 的密码: " DB_PASS; echo
  fi
}
common_args() { # 不含密码，密码走 MYSQL_PWD
  echo -n "-h $DB_HOST -P $DB_PORT -u $DB_USER --default-character-set=utf8mb4"
}

case "$CMD" in
  # ---------------------------------------------------------------- dump
  dump)
    [ -n "$OUT" ]  || { echo "❌ 需要 --out=<输出文件>" >&2; exit 1; }
    [ -n "$DB_USER" ] || { echo "❌ 需要 --user" >&2; exit 1; }
    ask_pass

    ignore_args=()
    if [ -n "$IGNORE" ]; then
      IFS=',' read -ra tbls <<< "$IGNORE"
      for t in "${tbls[@]}"; do
        ignore_args+=( --ignore-table="$DB_NAME.$t" )
      done
    fi

    echo "▸ 从 $DB_NAME@$DB_HOST 导出数据 → $OUT"
    echo "  （--single-transaction 不锁表，业务无需停机）"
    MYSQL_PWD="$DB_PASS" "$MYSQLDUMP" $(common_args) \
        --single-transaction --quick \
        --no-create-info --skip-triggers --skip-lock-tables \
        --set-gtid-purged=OFF \
        ${ignore_args[@]+"${ignore_args[@]}"} \
        "$DB_NAME" > "$OUT"

    echo "✅ 导出完成: $(du -h "$OUT" | cut -f1)  $OUT"
    echo
    echo "传到新服务器： scp $OUT root@<新服务器>:/root/"
    ;;

  # -------------------------------------------------------------- import
  import)
    [ -n "$FILE" ] || { echo "❌ 需要 --file=<数据文件>" >&2; exit 1; }
    [ -f "$FILE" ] || { echo "❌ 文件不存在: $FILE" >&2; exit 1; }
    [ -n "$DB_USER" ] || { echo "❌ 需要 --user" >&2; exit 1; }
    ask_pass

    echo "▸ 导入 $FILE → $DB_NAME@$DB_HOST"
    echo "  目标库应已由 deploy/db_init.sh 建好结构并清空种子数据"
    if MYSQL_PWD="$DB_PASS" "$MYSQL" $(common_args) --database="$DB_NAME" < "$FILE"; then
      echo "✅ 导入完成（无报错）"
    else
      echo
      echo "❌ 导入中断。常见原因："
      echo "   · Column count doesn't match → 源表结构与本仓结构不一致，见 DEPLOY.md 第 10 节"
      echo "   · Duplicate entry           → 目标库未清空，先跑 db_init.sh --empty-only"
      echo "   · 表不存在                   → 结构没导，先跑 deploy/db_init.sh"
      exit 1
    fi
    ;;

  # -------------------------------------------------------------- verify
  verify)
    [ -n "$DB_USER" ] || { echo "❌ 需要 --user" >&2; exit 1; }
    ask_pass
    # 输出 "表名 行数"，供两边 diff
    MYSQL_PWD="$DB_PASS" "$MYSQL" $(common_args) -N -B -e \
      "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_NAME' ORDER BY table_name;" \
      | while read -r t; do
          [ -z "$t" ] && continue
          n=$(MYSQL_PWD="$DB_PASS" "$MYSQL" $(common_args) -N -B -e "SELECT COUNT(*) FROM \`$t\`;" "$DB_NAME")
          printf '%s\t%s\n' "$t" "$n"
        done
    ;;

  *) echo "用法: bash deploy/db_migrate.sh {dump|import|verify} [选项]"; echo "      bash deploy/db_migrate.sh --help"; exit 1 ;;
esac
