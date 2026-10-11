// 管理统一入口 /manage DOM 冒烟（playwright 直连真实 dev server）。
// 验证：页面无 JS 错误（回归 installErrorHandlers 解构 TypeError 一类运行时缺陷）、
//       登录表单渲染、错误凭据提交出现服务端 401 文案；
//       可选 env 提供真实凭据（MANAGE_ADMIN_USER/PASS）时追加「成功登录跳转」断言
//       （CI 种子账号 admin/admin@2026；本地默认不传则跳过该段）。
// 用法: node manage_smoke.mjs [publicDir]   （dev server 由外层脚本起停，本脚本不负责）
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = 'http://127.0.0.1:8099';

let pass = 0, fail = 0;
const failures = [];
function check(ok, label, detail = '') {
  if (ok) { pass++; console.log(`  PASS  ${label}`); }
  else { fail++; failures.push(label + (detail ? ' :: ' + detail : '')); console.log(`  FAIL  ${label}${detail ? '  << ' + detail : ''}`); }
}

async function main() {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const jsErr = [];
  page.on('pageerror', (e) => jsErr.push('PAGEERROR ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') jsErr.push(m.text()); });

  /* ================= 1. 页面加载无 JS 错误 ================= */
  await page.goto(`${BASE}/manage`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  check(jsErr.length === 0, '/manage 加载无 JS 错误', jsErr.join(' | '));

  /* ================= 2. 登录表单渲染 ================= */
  const txt = (await page.evaluate(() => (document.body.innerText || ''))).replace(/\s+/g, ' ');
  check(/后台管理/.test(txt), '标题「后台管理」出现', txt.slice(0, 120));
  check(/统一入口/.test(txt), '副标题「统一入口」出现');
  const inputs = await page.locator('form input').count();
  check(inputs >= 2, '账号+密码输入框渲染', `count=${inputs}`);
  const btn = page.locator('form button[type="submit"]');
  check(await btn.count() === 1, '登录按钮存在');

  /* ================= 3. 错误凭据 → 服务端 401 文案 ================= */
  await page.fill('form input[name="username"]', '__no_such_user__');
  await page.fill('form input[name="password"]', 'wrong-pass-123');
  await btn.click();
  await page.waitForTimeout(900);
  const txt2 = (await page.evaluate(() => (document.body.innerText || ''))).replace(/\s+/g, ' ');
  check(/账号或密码不正确/.test(txt2), '错误凭据显示服务端提示', txt2.slice(0, 150));
  // 401 网络响应会被 Chromium 记一条 console error（预期行为），只断言未捕获的页面异常
  check(!jsErr.some((e) => e.startsWith('PAGEERROR')), '提交过程无未捕获 pageerror', jsErr.filter((e) => e.startsWith('PAGEERROR')).join(' | '));

  /* ================= 4.（可选）真实凭据成功跳转 ================= */
  const au = process.env.MANAGE_ADMIN_USER, ap = process.env.MANAGE_ADMIN_PASS;
  if (au && ap) {
    await page.goto(`${BASE}/manage`, { waitUntil: 'domcontentloaded' });
    await page.fill('form input[name="username"]', au);
    await page.fill('form input[name="password"]', ap);
    await page.locator('form button[type="submit"]').click();
    await page.waitForTimeout(1800);
    check(page.url().includes('/admin'), '管理员成功跳转 /admin', page.url());
    const txt3 = (await page.evaluate(() => (document.body.innerText || ''))).replace(/\s+/g, ' ');
    check(/仪表盘|考试管理/.test(txt3), '管理工作台已渲染');
  } else {
    console.log('  [SKIP] 未提供 MANAGE_ADMIN_USER/PASS，跳过成功登录跳转断言');
  }

  await browser.close();
  console.log(`\n结果: ${pass} PASS / ${fail} FAIL`);
  if (fail) { console.log('失败项:\n - ' + failures.join('\n - ')); process.exit(1); }
  process.exit(0);
}

main().catch((e) => { console.error('FATAL', e); process.exit(3); });
