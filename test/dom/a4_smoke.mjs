/**
 * A4 主观题批改 —— 前端真实渲染冒烟
 *
 * 断言（无 pageerror 为前提）：
 *  1) 教师端导航含「主观题批改」入口
 *  2) 教师端 #/grading 可渲染，标题为「主观题批改」
 *  3) 该页给出明确内容（待批列表或空态），不是白板
 *  4) 「新增考试」弹窗的组卷矩阵含「问答题」行（A4 把 longtext 纳入组卷）
 *  5) 组卷矩阵 5 行题型齐全
 *
 * 前置：php -S 127.0.0.1:8099 -t public public/router.php 已启动
 * 用法：node test/dom/a4_smoke.mjs
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

  const tLogin = await page.request.post(`${BASE}/api/teacher/login`, {
    headers: { 'Content-Type': 'application/json' },
    data: { username: 'teacher1', password: 'teacher@2026' },
  });
  check((await tLogin.json()).code === 0, '教师登录成功');

  /* ================= 主观题批改页 ================= */
  await page.goto(`${BASE}/teacher#/grading`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);

  let txt = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  check(/主观题批改/.test(txt), '导航含「主观题批改」入口');
  check(/主观题批改/.test(txt) && /问答题逐题评分|批阅/.test(txt), '批改页标题与说明渲染', txt.slice(0, 140));
  // 无已结束考试时是空态；有数据时是待批列表。两者都算「有内容」，只排除白板
  check(/暂无可批阅的考试|批阅进度|待批题数/.test(txt), '页面给出明确内容而非白板', txt.slice(0, 160));

  let pageErr = jsErr.filter((e) => e.startsWith('PAGEERROR'));
  check(pageErr.length === 0, '批改页无未捕获错误', pageErr.join(' | '));

  /* ================= 组卷矩阵含问答题 ================= */
  jsErr.length = 0;
  await page.goto(`${BASE}/teacher#/`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  const opened = await page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === '新增考试');
    if (!btn) return false;
    btn.click();
    return true;
  });
  check(opened, '找到并点击「新增考试」');
  await page.waitForTimeout(1500);

  txt = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  check(/组卷方式|组卷参数/.test(txt), '弹窗出现组卷配置区', txt.slice(0, 150));
  check(/问答题/.test(txt), '组卷矩阵含「问答题」行');

  const matrixTypes = await page.evaluate(() => {
    const table = document.querySelector('.modal .table, .modal table');
    if (!table) return [];
    return [...table.querySelectorAll('tbody tr td:first-child')].map((td) => td.textContent.trim());
  });
  check(
    ['判断题', '单选题', '多选题', '填空题', '问答题'].every((l) => matrixTypes.includes(l)),
    '矩阵五行题型齐全',
    matrixTypes.join(',')
  );

  // 手动选题的题型下拉也应能筛问答题
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('.segmented button')].find((x) => x.textContent.trim() === '手动选题');
    b?.click();
  });
  await page.waitForTimeout(1200);
  const hasLongtextOption = await page.evaluate(() => {
    const sel = [...document.querySelectorAll('.modal select')].find((s) =>
      [...s.options].some((o) => o.textContent.trim() === '判断题'));
    if (!sel) return false;
    return [...sel.options].some((o) => o.textContent.trim() === '问答题');
  });
  check(hasLongtextOption, '手动选题题型下拉含「问答题」');

  pageErr = jsErr.filter((e) => e.startsWith('PAGEERROR'));
  check(pageErr.length === 0, '组卷面板无未捕获错误', pageErr.join(' | '));

  await browser.close();
  console.log(`\n结果: ${pass} PASS / ${fail} FAIL`);
  if (fail) { console.log('失败项:\n - ' + failures.join('\n - ')); process.exit(1); }
  process.exit(0);
}

main().catch((e) => { console.error('FATAL', e); process.exit(3); });
