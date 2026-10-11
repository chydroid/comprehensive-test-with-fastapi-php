#!/usr/bin/env bash
# ===================================================================
# 一键全功能实测：前端自检 + 后端回归 + AI 端到端 + DOM 冒烟
#
# 用法（在项目根目录执行）：
#   bash test/run_comprehensive.sh
#
# 四层：
#   1) 前端 ES 模块自检      —— temp/check_frontend.mjs（无需 server）
#   2) 后端全量回归（串行）  —— test/run_all.sh
#   3) AI 端到端实测         —— temp/run_e2e_ai.sh（自带 dev server）
#   4) DOM 冒烟              —— temp/domtest/*.mjs（jsdom 直连真实 dev server；
#                               waitroom / lifecycle 需先用 seed 脚本造真实考场）
#
# 退出码：任一层有失败则非 0，四层全绿为 0。
# 说明：temp/ 下的配套脚本（check_frontend / run_e2e_ai / domtest / seed）
#       目前未被 git 跟踪，本机可直接跑；若要在其它机器或 CI 复现，
#       需把这些文件一并纳入版本控制（见脚本末尾说明）。
# ===================================================================
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

# 运行时 PATH：PHP 8.3 + managed Node 22 + Git/System32（pkill/php 等）
export PATH="/usr/bin:/bin:/c/Windows/System32:/c/Users/CHY/.workbuddy/binaries/PortableGit/versions/1.2.0/usr/bin:/d/BtSoft/php/83:/c/Users/CHY/.workbuddy/binaries/node/versions/22.22.2-6:$PATH"

PUB="$ROOT/public"
DT="$ROOT/temp/domtest"

pass_layers=0
fail_layers=0
overall=0

log() { echo; echo "==================== $* ===================="; }

# ---------- 层1：前端自检 ----------
log "[1/4] 前端 ES 模块自检"
if [ -f "$ROOT/temp/check_frontend.mjs" ]; then
  node "$ROOT/temp/check_frontend.mjs" 2>&1 | tail -12
  rc=${PIPESTATUS[0]}
  if [ "$rc" -eq 0 ]; then echo "[OK] [1/4] 前端自检通过"; pass_layers=$((pass_layers+1));
  else echo "[FAIL] [1/4] 前端自检失败(rc=$rc)"; fail_layers=$((fail_layers+1)); overall=1; fi
else
  echo "[SKIP] temp/check_frontend.mjs 不存在"
fi

# ---------- 层2：后端全量回归 ----------
log "[2/4] 后端全量回归（串行）"
bash "$ROOT/test/run_all.sh" 2>&1 | tail -25
rc=${PIPESTATUS[0]}
if [ "$rc" -eq 0 ]; then echo "[OK] [2/4] 后端回归通过"; pass_layers=$((pass_layers+1));
else echo "[FAIL] [2/4] 后端回归失败(rc=$rc)"; fail_layers=$((fail_layers+1)); overall=1; fi

# ---------- 层3：AI 端到端 ----------
log "[3/4] AI 端到端实测"
if [ -f "$ROOT/temp/run_e2e_ai.sh" ]; then
  bash "$ROOT/temp/run_e2e_ai.sh" 2>&1 | tail -20
  rc=${PIPESTATUS[0]}
  if [ "$rc" -eq 0 ]; then echo "[OK] [3/4] AI e2e 通过"; pass_layers=$((pass_layers+1));
  else echo "[FAIL] [3/4] AI e2e 失败(rc=$rc)"; fail_layers=$((fail_layers+1)); overall=1; fi
else
  echo "[SKIP] temp/run_e2e_ai.sh 不存在"
fi

# ---------- 层4：DOM 冒烟 ----------
log "[4/4] DOM 冒烟（jsdom 直连真实 dev server）"
if [ ! -d "$DT" ]; then
  echo "[SKIP] temp/domtest 不存在（未被跟踪，无法跑 UI 冒烟）"
else
  pkill -f "php -S 127.0.0.1:8099" 2>/dev/null || true
  sleep 1
  php -S 127.0.0.1:8099 -t public public/router.php > /tmp/dom_srv_main.log 2>&1 &
  SRV=$!
  sleep 2

  dom_pass=0; dom_fail=0
  run_dom() {
    local tag="$1"; shift
    node "$@" > "/tmp/dom_${tag}.log" 2>&1
    local r=$?
    if [ "$r" -eq 0 ]; then echo "  [OK  ] $tag"; dom_pass=$((dom_pass+1));
    else echo "  [FAIL] $tag (rc=$r) —— 见 /tmp/dom_${tag}.log"; dom_fail=$((dom_fail+1)); fi
  }

  # 自包含：四端登录流 + 各功能模块渲染/交互
  run_dom login_admin    "$DT/login_smoke.mjs" "$PUB" admin
  run_dom login_teacher  "$DT/login_smoke.mjs" "$PUB" teacher
  run_dom login_student  "$DT/login_smoke.mjs" "$PUB" student
  run_dom login_portal   "$DT/login_smoke.mjs" "$PUB" portal
  run_dom admin_login    "$DT/admin_login_smoke.mjs" "$PUB"
  run_dom portal_login   "$DT/portal_login_smoke.mjs" "$PUB"
  run_dom a2a3           "$DT/a2a3_smoke.mjs" "$PUB"
  run_dom a4             "$DT/a4_smoke.mjs" "$PUB"
  run_dom c3c4c5         "$DT/c3c4c5_smoke.mjs" "$PUB"
  run_dom settings_view  "$DT/settings_view_smoke.mjs" "$PUB"
  run_dom mock_setup     "$DT/mock_setup_smoke.mjs" "$PUB"
  run_dom mobile_exam    "$DT/mobile_exam_smoke.mjs" "$PUB"
  run_dom exam_form      "$DT/exam_form_smoke.mjs" "$PUB"
  # 纯视图结构（桩 api，不需要 server，但放进同一轮也无妨）
  run_dom generate_panel "$DT/generate_panel_smoke.mjs" "$PUB"

  # 种子考场依赖型：先造两个独立可考考场（各 2 名夹具考生，绑定 teacher1）
  if [ -f "$ROOT/temp/seed_exam_for_smoke.php" ]; then
    php "$ROOT/temp/seed_exam_for_smoke.php" > "$ROOT/temp/seed.json" 2> "$ROOT/temp/seed.err"
    src=$?
    if [ "$src" -ne 0 ]; then
      echo "  [SKIP] 种子造场失败(rc=$src)，waitroom/lifecycle 跳过"; cat "$ROOT/temp/seed.err"
    else
      W_ID=$(node -e "process.stdout.write(String(require('./temp/seed.json').waitroom.exam_id))")
      W_STU=$(node -e "process.stdout.write(String(require('./temp/seed.json').waitroom.stu_a))")
      L_ID=$(node -e "process.stdout.write(String(require('./temp/seed.json').lifecycle.exam_id))")
      L_PWD=$(node -e "process.stdout.write(String(require('./temp/seed.json').lifecycle.exam_pwd))")
      L_STU=$(node -e "process.stdout.write(String(require('./temp/seed.json').lifecycle.stu_a))")
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
    echo "  [SKIP] temp/seed_exam_for_smoke.php 不存在，waitroom/lifecycle 跳过"
  fi

  kill "$SRV" 2>/dev/null || true
  pkill -f "php -S 127.0.0.1:8099" 2>/dev/null || true
  rm -f "$ROOT/temp/seed.json" "$ROOT/temp/seed.err"
  echo "  ---- DOM 冒烟：$dom_pass OK / $dom_fail FAIL ----"
  if [ "$dom_fail" -eq 0 ]; then echo "[OK] [4/4] DOM 冒烟通过"; pass_layers=$((pass_layers+1));
  else echo "[FAIL] [4/4] DOM 冒烟有失败"; fail_layers=$((fail_layers+1)); overall=1; fi
fi

log "总汇总"
echo "通过层: $pass_layers / 4    失败层: $fail_layers"
if [ "$overall" -eq 0 ]; then echo "全功能实测全部通过"; else echo "存在失败层，请查看上方日志"; fi
exit $overall
