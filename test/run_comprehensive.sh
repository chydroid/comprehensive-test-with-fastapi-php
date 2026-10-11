#!/usr/bin/env bash
# ===================================================================
# 一键全功能实测：前端自检 + 后端回归 + AI 端到端 + DOM 冒烟
#
# 用法（在项目根目录执行）：
#   bash test/run_comprehensive.sh
#
# 四层：
#   1) 前端 ES 模块自检      —— test/check_frontend.mjs（无需 server）
#   2) 后端全量回归（串行）  —— test/run_all.sh
#   3) AI 端到端实测         —— test/run_e2e_ai.sh（自带 dev server）
#   4) DOM 冒烟              —— test/dom/*.mjs（jsdom / playwright 直连真实 dev server；
#                               waitroom / lifecycle 需先用 seed 脚本造真实考场）
#
# 退出码：任一层有失败则非 0，四层全绿为 0。
#
# 依赖：
#   - PHP 8.3（CLI，需 pdo_mysql）+ 一个已存在且可写的 MySQL 库（默认 csip_exam）
#   - Node 22 + test/dom 下的 jsdom / playwright（见 test/dom/package.json；CI 用 npm ci）
#   - DOM 层里 a2a3 / a4 / c3c4c5 走真实 Chromium；若浏览器不可用会自动 [SKIP] 不计入失败
# ===================================================================
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

# ---------- 可移植运行时 PATH ----------
# Windows 本地用 WorkBuddy 托管的 PHP / Node；其它平台（CI / Linux）信任系统已就位的 php / node。
case "$(uname -s 2>/dev/null)" in
  MINGW*|CYGWIN*|MSYS*)
    export PATH="/usr/bin:/bin:/c/Windows/System32:/c/Users/CHY/.workbuddy/binaries/PortableGit/versions/1.2.0/usr/bin:/d/BtSoft/php/83:/c/Users/CHY/.workbuddy/binaries/node/versions/22.22.2-6:$PATH"
    ;;
  *)
    : # CI / Linux：php 与 node 由 setup-php / setup-node 注入 PATH
    ;;
esac

# Node 解析依赖：优先 test/dom/node_modules；本地再补一条托管工作区（playwright 常驻此处）
export NODE_PATH="$ROOT/test/dom/node_modules:/c/Users/CHY/.workbuddy/binaries/node/workspace/node_modules:${NODE_PATH:-}"

PUB="$ROOT/public"
DT="$ROOT/test/dom"
SVR_HOST="127.0.0.1"
SVR_PORT="8099"
SVR_ADDR="$SVR_HOST:$SVR_PORT"

pass_layers=0
fail_layers=0
overall=0

log() { echo; echo "==================== $* ===================="; }

# ---------- 层1：前端自检 ----------
log "[1/4] 前端 ES 模块自检"
if [ -f "$ROOT/test/check_frontend.mjs" ]; then
  node "$ROOT/test/check_frontend.mjs" 2>&1 | tail -12
  rc=${PIPESTATUS[0]}
  if [ "$rc" -eq 0 ]; then echo "[OK] [1/4] 前端自检通过"; pass_layers=$((pass_layers+1));
  else echo "[FAIL] [1/4] 前端自检失败(rc=$rc)"; fail_layers=$((fail_layers+1)); overall=1; fi
else
  echo "[SKIP] test/check_frontend.mjs 不存在"
fi

# ---------- 层2：后端全量回归 ----------
log "[2/4] 后端全量回归（串行）"
bash "$ROOT/test/run_all.sh" 2>&1 | tail -25
rc=${PIPESTATUS[0]}
if [ "$rc" -eq 0 ]; then echo "[OK] [2/4] 后端回归通过"; pass_layers=$((pass_layers+1));
else echo "[FAIL] [2/4] 后端回归失败(rc=$rc)"; fail_layers=$((fail_layers+1)); overall=1; fi

# ---------- 层3：AI 端到端 ----------
log "[3/4] AI 端到端实测"
if [ -f "$ROOT/test/run_e2e_ai.sh" ]; then
  bash "$ROOT/test/run_e2e_ai.sh" 2>&1 | tail -20
  rc=${PIPESTATUS[0]}
  if [ "$rc" -eq 0 ]; then echo "[OK] [3/4] AI e2e 通过"; pass_layers=$((pass_layers+1));
  else echo "[FAIL] [3/4] AI e2e 失败(rc=$rc)"; fail_layers=$((fail_layers+1)); overall=1; fi
else
  echo "[SKIP] test/run_e2e_ai.sh 不存在"
fi

# ---------- 层4：DOM 冒烟 ----------
log "[4/4] DOM 冒烟（jsdom / playwright 直连真实 dev server）"
if [ ! -d "$DT" ]; then
  echo "[SKIP] test/dom 不存在"
else
  # 先装 DOM 层依赖（CI 走 npm ci；本地若已装则秒过）
  if [ -f "$DT/package.json" ]; then
    (cd "$DT" && (command -v npm >/dev/null 2>&1 && npm install --no-audit --no-fund) || true) | tail -3
  fi

  pkill -f "php -S $SVR_ADDR" 2>/dev/null || true
  sleep 1
  php -S "$SVR_ADDR" -t public public/router.php > /tmp/dom_srv_main.log 2>&1 &
  SRV=$!
  sleep 2

  # playwright chromium 可用性（不可用时 a2a3/a4/c3c4c5 自动跳过，不算失败）
  PW_OK=0
  if [ -d "$DT/node_modules/playwright" ]; then
    if (cd "$DT" && npx playwright install chromium >/dev/null 2>&1); then PW_OK=1; fi
  fi
  echo "  playwright chromium: $([ "$PW_OK" -eq 1 ] && echo '可用' || echo '不可用（a2a3/a4/c3c4c5 将跳过）')"

  dom_pass=0; dom_fail=0
  run_dom() {
    local tag="$1"; shift
    node "$@" > "/tmp/dom_${tag}.log" 2>&1
    local r=$?
    if [ "$r" -eq 0 ]; then echo "  [OK  ] $tag"; dom_pass=$((dom_pass+1));
    else echo "  [FAIL] $tag (rc=$r) —— 见 /tmp/dom_${tag}.log"; dom_fail=$((dom_fail+1)); fi
  }

  # 自包含：四端登录流（login_smoke 为前端桩）+ 各功能模块渲染/交互
  run_dom login_admin    "$DT/login_smoke.mjs" "$PUB" admin
  run_dom login_teacher  "$DT/login_smoke.mjs" "$PUB" teacher
  run_dom login_student  "$DT/login_smoke.mjs" "$PUB" student
  run_dom login_portal   "$DT/login_smoke.mjs" "$PUB" portal
  run_dom admin_login    "$DT/admin_login_smoke.mjs" "$PUB"
  run_dom portal_login   "$DT/portal_login_smoke.mjs" "$PUB"
  # 管理统一入口（真实 dev server + 真实登录链路；可选 env 传真实凭据测成功跳转）
  run_dom manage         "$DT/manage_smoke.mjs" "$PUB"
  # 以下三项走真实 Chromium：浏览器可用才跑，否则跳过（不计入失败）
  if [ "$PW_OK" -eq 1 ]; then
    run_dom a2a3           "$DT/a2a3_smoke.mjs" "$PUB"
    run_dom a4             "$DT/a4_smoke.mjs" "$PUB"
    run_dom c3c4c5         "$DT/c3c4c5_smoke.mjs" "$PUB"
  else
    echo "  [SKIP] a2a3 / a4 / c3c4c5（playwright chromium 不可用）"
  fi
  run_dom settings_view  "$DT/settings_view_smoke.mjs" "$PUB"
  run_dom mock_setup     "$DT/mock_setup_smoke.mjs" "$PUB"
  run_dom mobile_exam    "$DT/mobile_exam_smoke.mjs" "$PUB"
  run_dom exam_form      "$DT/exam_form_smoke.mjs" "$PUB"
  # 纯视图结构（桩 api，不需要 server，但放进同一轮也无妨）
  run_dom generate_panel "$DT/generate_panel_smoke.mjs" "$PUB"

  # 种子考场依赖型：先造两个独立可考考场（各 2 名夹具考生，绑定 teacher1）
  if [ -f "$ROOT/test/seed_exam_for_smoke.php" ]; then
    php "$ROOT/test/seed_exam_for_smoke.php" > "$ROOT/test/seed.json" 2> "$ROOT/test/seed.err"
    src=$?
    if [ "$src" -ne 0 ]; then
      echo "  [SKIP] 种子造场失败(rc=$src)，waitroom/lifecycle 跳过"; cat "$ROOT/test/seed.err"
    else
      W_ID=$(node -e "process.stdout.write(String(require('./test/seed.json').waitroom.exam_id))")
      W_STU=$(node -e "process.stdout.write(String(require('./test/seed.json').waitroom.stu_a))")
      L_ID=$(node -e "process.stdout.write(String(require('./test/seed.json').lifecycle.exam_id))")
      L_PWD=$(node -e "process.stdout.write(String(require('./test/seed.json').lifecycle.exam_pwd))")
      L_STU=$(node -e "process.stdout.write(String(require('./test/seed.json').lifecycle.stu_a))")
      run_dom exam_waitroom  "$DT/exam_waitroom_smoke.mjs" "$PUB" "$W_ID" "$W_STU" testPwd123
      run_dom exam_lifecycle "$DT/exam_lifecycle_smoke.mjs" "$PUB" "$L_ID" "$L_PWD" teacher1 "$L_STU" testPwd123
      # 精确清理这两个种子考场（按 id 删除，绝不靠命名前缀误删真实数据）
      php -r '
        require "core/helpers.php"; bootstrap();
        $ids = array_filter(array_map("intval", [
          isset($argv[1]) ? (int)$argv[1] : 0,
          isset($argv[2]) ? (int)$argv[2] : 0,
        ]), function ($x) { return $x > 0; });
        if ($ids) {
          $in = implode(",", $ids);
          \Core\Database::query("DELETE FROM stupaper WHERE exam_id IN ($in)");
          \Core\Database::query("DELETE FROM stuscore WHERE exam_id IN ($in)");
          \Core\Database::query("DELETE FROM examinfo WHERE id IN ($in)");
          echo "  已清理种子考场 " . count($ids) . " 场（id=" . $in . "）\n";
        }
      ' "$W_ID" "$L_ID"
    fi
  else
    echo "  [SKIP] test/seed_exam_for_smoke.php 不存在，waitroom/lifecycle 跳过"
  fi

  # playwright 不可用时的优雅跳过（不计入失败）
  if [ "$PW_OK" -ne 1 ]; then
    echo "  [SKIP] a2a3 / a4 / c3c4c5（playwright chromium 不可用）"
  fi

  kill "$SRV" 2>/dev/null || true
  pkill -f "php -S $SVR_ADDR" 2>/dev/null || true
  rm -f "$ROOT/test/seed.json" "$ROOT/test/seed.err"
  echo "  ---- DOM 冒烟：$dom_pass OK / $dom_fail FAIL ----"
  if [ "$dom_fail" -eq 0 ]; then echo "[OK] [4/4] DOM 冒烟通过"; pass_layers=$((pass_layers+1));
  else echo "[FAIL] [4/4] DOM 冒烟有失败"; fail_layers=$((fail_layers+1)); overall=1; fi
fi

log "总汇总"
echo "通过层: $pass_layers / 4    失败层: $fail_layers"
if [ "$overall" -eq 0 ]; then echo "全功能实测全部通过"; else echo "存在失败层，请查看上方日志"; fi
exit $overall
