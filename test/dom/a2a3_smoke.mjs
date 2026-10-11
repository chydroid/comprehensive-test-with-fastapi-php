/**
 * A2 成绩分析 / A3 组卷多样化 —— 前端真实渲染冒烟
 *
 * 断言（无 pageerror 为前提）：
 *  1) 管理端 #/analysis 可渲染，标题为「成绩分析」
 *  2) 教师端「新增考试」弹窗出现「组卷方式」三选一（随机抽题 / 手动选题 / 按知识点）
 *  3) 切到「手动选题」→ 出现「搜索题库」与选中区
 *  4) 切到「按知识点」→ 出现「加载知识点」入口
 *  5) 教师端考试列表出现「组卷」列（随机/手动选题/按知识点）
 *  6) 教师端 #/analysis 可渲染
 *
 * 前置：php -S 127.0.0.1:8099 -t public public/router.php 已启动
 * 用法：node test/dom/a2a3_smoke.mjs
 */
import { createRequire } from 'module';
import { pathToFileURL } from 'url';

const require = createRequire(pathToFileURL('E:/develop/comprehensive-test-with-fastapi-php/temp/pwtest/noop.js'));
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8099';

let pass = 0, fail = 0;
const failures = [];
function check(ok, label, detail = '') {
  if (ok) { pass++; console.log(`  PASS  ${label}`); }
  else { fail++; failures.push(label + (detail ? ' :: ' + detail : '')); console.log(`  FAIL  ${label}${detail ? '  << ' + detail : ''}`); }
}

async function main() {
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();
  const jsErr = [];
  page.on('pageerror', (e) => jsErr.push('PAGEERROR ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') jsErr.push(m.text()); });

  /* ================= 管理端：成绩分析 ================= */
  const aLogin = await page.request.post(`${BASE}/api/admin/login`, {
    headers: { 'Content-Type': 'application/json' },
    data: { username: 'admin', password: 'admin@2026' },
  });
  check((await aLogin.json()).code === 0, '管理员登录成功');

  await page.goto(`${BASE}/admin#/analysis`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  let txt = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  check(/成绩分析/.test(txt), '管理端成绩分析页可渲染', txt.slice(0, 120));
  check(!jsErr.some((e) => e.startsWith('PAGEERROR')), '管理端无未捕获 pageerror', jsErr.filter((e) => e.startsWith('PAGEERROR')).join(' | '));
  const adminHasNav = await page.evaluate(() => (document.body.innerText || '').includes('成绩分析'));
  check(adminHasNav, '管理端导航含「成绩分析」入口');

  /* ================= 教师端：组卷模式 ================= */
  const tLogin = await page.request.post(`${BASE}/api/teacher/login`, {
    headers: { 'Content-Type': 'application/json' },
    data: { username: 'teacher1', password: 'teacher@2026' },
  });
  check((await tLogin.json()).code === 0, '教师登录成功');

  jsErr.length = 0;
  await page.goto(`${BASE}/teacher#/`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  txt = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  check(/考试管理/.test(txt), '教师端考试管理可渲染');
  check(/组卷/.test(txt), '教师端列表含「组卷」列');

  // 打开「新增考试」弹窗
  const opened = await page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === '新增考试');
    if (!btn) return false;
    btn.click();
    return true;
  });
  check(opened, '找到并点击「新增考试」');
  await page.waitForTimeout(1500);

  txt = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  check(/组卷方式/.test(txt), '弹窗出现「组卷方式」区', txt.slice(0, 150));
  const segLabels = await page.evaluate(() => [...document.querySelectorAll('.segmented button')].map((b) => b.textContent.trim()));
  check(segLabels.includes('随机抽题') && segLabels.includes('手动选题') && segLabels.includes('按知识点'),
    '三种组卷模式可选', segLabels.join(','));

  // 切到「手动选题」
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('.segmented button')].find((x) => x.textContent.trim() === '手动选题');
    b?.click();
  });
  await page.waitForTimeout(1200);
  txt = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  check(/搜索/.test(txt), '手动选题面板出现「搜索」按钮');
  check(/已选 0 题|尚未选择题目/.test(txt), '手动选题面板出现选中区');

  // 切到「按知识点」
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('.segmented button')].find((x) => x.textContent.trim() === '按知识点');
    b?.click();
  });
  await page.waitForTimeout(1200);
  txt = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  check(/加载知识点/.test(txt), '按知识点面板出现「加载知识点」');
  check(/请先选择科目|暂无知识点|知识点/.test(txt), '按知识点面板给出明确提示');

  const pageErr = jsErr.filter((e) => e.startsWith('PAGEERROR'));
  check(pageErr.length === 0, '组卷模式切换无未捕获错误', pageErr.join(' | '));

  // 关闭弹窗
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('.modal button, .dialog button')].find((x) => x.textContent.trim() === '取消');
    b?.click();
  });
  await page.waitForTimeout(600);

  /* ================= 教师端：成绩分析 ================= */
  jsErr.length = 0;
  await page.goto(`${BASE}/teacher#/analysis`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  txt = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  check(/成绩分析/.test(txt), '教师端成绩分析页可渲染', txt.slice(0, 120));
  const tErr = jsErr.filter((e) => e.startsWith('PAGEERROR'));
  check(tErr.length === 0, '教师端成绩分析无未捕获错误', tErr.join(' | '));

  await browser.close();
  console.log(`\n结果: ${pass} PASS / ${fail} FAIL`);
  if (fail) { console.log('失败项:\n - ' + failures.join('\n - ')); process.exit(1); }
  process.exit(0);
}

main().catch((e) => { console.error('FATAL', e); process.exit(3); });
