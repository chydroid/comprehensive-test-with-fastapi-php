#!/usr/bin/env bash
# ------------------------------------------------------------------
# 全量测试运行器
#
# 用法（在项目根目录执行）：
#   bash test/run_all.sh              # 运行全部用例
#   bash test/run_all.sh admin        # 只运行文件名包含 admin 的用例
#
# 每个用例以独立 PHP 进程运行，避免类重声明与会话状态互相污染。
# 注意：部分受限环境禁用了 PHP 的 exec/proc_open，故编排放在 shell 层。
# ------------------------------------------------------------------

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

FILTER="${1:-}"
PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "找不到 PHP 可执行文件（可用 PHP_BIN 环境变量指定）" >&2
    exit 1
fi

shopt -s nullglob
FILES=(test/cases/*_test.php)
shopt -u nullglob

if [ ${#FILES[@]} -eq 0 ]; then
    echo "未找到任何测试用例（test/cases/*_test.php）" >&2
    exit 1
fi

TOTAL_PASS=0
TOTAL_FAIL=0
TOTAL_SKIP=0
FAILED_FILES=()
RAN=0

for FILE in "${FILES[@]}"; do
    NAME="$(basename "$FILE" .php)"
    if [ -n "$FILTER" ] && [[ "$NAME" != *"$FILTER"* ]]; then
        continue
    fi

    RAN=$((RAN + 1))
    echo "============================================================"
    echo "[RUN ] $NAME"
    echo "------------------------------------------------------------"

    OUTPUT="$("$PHP_BIN" "$FILE" 2>&1)"
    STATUS=$?
    printf '%s\n' "$OUTPUT"

    SUMMARY="$(printf '%s\n' "$OUTPUT" | grep -E '[0-9]+ PASS / [0-9]+ FAIL / [0-9]+ SKIP' | tail -1)"
    if [ -n "$SUMMARY" ]; then
        NUMS="$(printf '%s' "$SUMMARY" | tr -cd '0-9 ' | tr -s ' ')"
        P="$(printf '%s' "$NUMS" | awk '{print $1}')"
        F="$(printf '%s' "$NUMS" | awk '{print $2}')"
        S="$(printf '%s' "$NUMS" | awk '{print $3}')"
        TOTAL_PASS=$((TOTAL_PASS + ${P:-0}))
        TOTAL_FAIL=$((TOTAL_FAIL + ${F:-0}))
        TOTAL_SKIP=$((TOTAL_SKIP + ${S:-0}))
        if [ "${F:-0}" -gt 0 ]; then
            FAILED_FILES+=("$NAME")
        fi
    else
        TOTAL_FAIL=$((TOTAL_FAIL + 1))
        FAILED_FILES+=("$NAME(无统计输出)")
    fi

    if [ $STATUS -ne 0 ] && [ -z "$SUMMARY" ]; then
        FAILED_FILES+=("$NAME(退出码 $STATUS)")
    fi
done

if [ $RAN -eq 0 ]; then
    echo "没有匹配 \"$FILTER\" 的用例" >&2
    exit 1
fi

echo "============================================================"
echo "总计: $TOTAL_PASS PASS / $TOTAL_FAIL FAIL / $TOTAL_SKIP SKIP"
if [ ${#FAILED_FILES[@]} -gt 0 ]; then
    echo "失败文件: ${FAILED_FILES[*]}"
fi
echo "============================================================"

[ $TOTAL_FAIL -gt 0 ] && exit 1
exit 0
