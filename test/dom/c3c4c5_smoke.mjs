/**
 * 第十六轮（C3 学习资料库 / C4 AI 阅卷辅助 / C5 考后问卷）—— 前端真实渲染冒烟
 *
 * 断言（以无 pageerror 为前提）：
 *  C3-1 管理端导航含「学习资料」入口，点「上传资料」弹窗含文件控件与标题/科目字段
 *  C3-2 学生端导航含「学习资料」入口，且 #/materials 可渲染
 *  C4-1 教师端批改页可渲染，且有待批数据时提供「AI 建议分」按钮
 *  C4-2 系统设置页含 AI 阅卷分组，且 API Key 渲染为 password
 *  C5-1 教师端考试列表对已结束考试提供「问卷」入口
 *  C5-2 点「问卷」能打开编辑弹窗并含题型选择
 *  C5-3 问卷流程无 JS 错误
 *
 * 注意（踩过的坑）：
 *  - 三种角色共用同一个会话 Cookie，登录会互相顶掉。访问某端页面前必须
 *    重新以该角色登录，否则会打到登录页，误报成「功能坏了」。
 *  - 必须先登录再跳转。未登录就 goto 会落到 notFound，之后改 hash 再 goto
 *    只触发 hashchange、不重新引导，同样会误报。
 *
 * 前置：php -S 127.0.0.1:8099 -t public public/router.php 已启动
 * 用法：node test/dom/c3c4c5_smoke.mjs
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

const norm = (s) => (s || '').replace(/\s+/g, ' ');
const bodyText = (page) => page.evaluate(() => document.body.innerText || '');
const pageErrors = (jsErr) => jsErr.filter((e) => e.startsWith('PAGEERROR'));

async function login(page, url, payload) {
  const r = await page.request.post(`${BASE}${url}`, {
    headers: { 'Content-Type': 'application/json' },
    data: payload,
  });
  return (await r.json()).code === 0;
}

/** 点第一个文案精确命中（去空白后）的按钮/链接 */
async function clickByText(page, re) {
  return page.evaluate((src) => {
    const rx = new RegExp(src, 'i');
    const hit = Array.from(document.querySelectorAll('button, a'))
      .find((el) => rx.test((el.textContent || '').replace(/\s+/g, '').trim()));
    if (hit) { hit.click(); return true; }
    return false;
  }, re.source);
}

async function main() {
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();
  const jsErr = [];
  page.on('pageerror', (e) => jsErr.push('PAGEERROR ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') jsErr.push(m.text()); });

  /* ============================================================
   * C3. 学习资料库
   * ============================================================ */
  jsErr.length = 0;
  check(await login(page, '/api/admin/login', { username: 'admin', password: 'admin@2026' }), '管理员登录成功');

  await page.goto(`${BASE}/admin#/materials`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);

  let txt = norm(await bodyText(page));
  check(/学习资料/.test(txt), 'C3-1 管理端导航含「学习资料」入口');
  check(/上传资料/.test(txt), 'C3-1 资料页有「上传资料」按钮', txt.slice(0, 180));

  const opened = await clickByText(page, /^上传资料$/);
  await page.waitForTimeout(1000);
  check(opened, 'C3-1 「上传资料」按钮可点击');
  const hasFile = await page.evaluate(() => !!document.querySelector('input[type=file]'));
  check(hasFile, 'C3-1 弹窗含文件选择控件');
  txt = norm(await bodyText(page));
  check(/标题|资料名称/.test(txt) && /科目/.test(txt), 'C3-1 弹窗含标题与科目字段', txt.slice(0, 220));
  check(pageErrors(jsErr).length === 0, 'C3-1 管理端资料页无未捕获错误', pageErrors(jsErr).join(' | '));

  // 学生端：必须先以学生身份登录，再跳转（顺序反了会落到 notFound 误报）
  jsErr.length = 0;
  check(await login(page, '/api/student/login', { username: '165165', password: '123456' }), '学生登录成功');
  await page.goto(`${BASE}/student#/materials`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  txt = norm(await bodyText(page));
  check(/学习资料/.test(txt), 'C3-2 学生端导航含「学习资料」入口', txt.slice(0, 160));
  check(/暂无学习资料|资料/.test(txt) && !/页面不存在/.test(txt), 'C3-2 学生端资料页给出明确内容', txt.slice(0, 200));
  check(pageErrors(jsErr).length === 0, 'C3-2 学生端资料页无未捕获错误', pageErrors(jsErr).join(' | '));

  /* ============================================================
   * C4. AI 阅卷辅助
   * ============================================================ */
  jsErr.length = 0;
  check(await login(page, '/api/teacher/login', { username: 'teacher1', password: 'teacher@2026' }), '教师登录成功');

  await page.goto(`${BASE}/teacher#/grading`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  txt = norm(await bodyText(page));
  check(/主观题批改/.test(txt), 'C4-1 批改页可渲染', txt.slice(0, 140));

  // 有待批题才渲染「AI 建议分」；无待批时是空态，两者都是正确行为，
  // 但不能是「按钮没做出来」——所以空态必须能被明确识别出来。
  const hasAiBtn = await page.evaluate(() =>
    Array.from(document.querySelectorAll('button, a')).some((el) => /AI\s*建议分/.test(el.textContent || ''))
  );
  const emptyState = /本场考试没有需要|暂无可批阅的考试|暂无待批|待批题数\s*0/.test(txt);
  check(hasAiBtn || emptyState, 'C4-1 AI 建议分按钮存在（或确为空态）', emptyState ? '空态' : txt.slice(0, 160));
  check(pageErrors(jsErr).length === 0, 'C4-1 批改页无未捕获错误', pageErrors(jsErr).join(' | '));

  // 系统设置在 #/config（#/system 是「系统维护」）。教师登录会顶掉管理员会话，必须重新登录。
  jsErr.length = 0;
  check(await login(page, '/api/admin/login', { username: 'admin', password: 'admin@2026' }), '重新以管理员登录');
  await page.goto(`${BASE}/admin#/config`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  txt = norm(await bodyText(page));
  check(/系统设置/.test(txt) && !/请使用管理员账号登录/.test(txt), 'C4-2 系统设置页可渲染', txt.slice(0, 160));
  check(/AI\s*阅卷|智能阅卷/.test(txt), 'C4-2 设置页含 AI 阅卷分组', txt.slice(0, 260));
  // 设置页按分组分 Tab 渲染，未激活的分组其字段根本不在 DOM 里，必须先切过去
  await clickByText(page, /AI\s*阅卷与组卷/);
  await page.waitForTimeout(1200);
  // 密钥类配置必须渲染成 password，否则明文口令会直接暴露在 DOM 里
  const secretType = await page.evaluate(() => {
    const el = document.querySelector('input[name="ai_grading_api_key"]');
    return el ? el.type : null;
  });
  check(secretType === 'password', 'C4-2 API Key 渲染为 password 输入框', String(secretType));
  check(pageErrors(jsErr).length === 0, 'C4-2 设置页无未捕获错误', pageErrors(jsErr).join(' | '));

  /* ============================================================
   * C5. 考后问卷
   * ============================================================ */
  jsErr.length = 0;
  check(await login(page, '/api/teacher/login', { username: 'teacher1', password: 'teacher@2026' }), '重新以教师登录');
  await page.goto(`${BASE}/teacher#/`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);

  const hasSurveyBtn = await page.evaluate(() =>
    Array.from(document.querySelectorAll('button, a'))
      .some((el) => /^问卷$/.test((el.textContent || '').replace(/\s+/g, '').trim()))
  );
  check(hasSurveyBtn, 'C5-1 教师端考试列表对已结束考试提供「问卷」入口');

  if (hasSurveyBtn) {
    await clickByText(page, /^问卷$/);
    // 弹窗要先拉一次问卷配置再渲染，1.8s 不够，会误判成「弹窗没打开」
    await page.waitForTimeout(3500);
    txt = norm(await bodyText(page));
    check(/考后问卷/.test(txt), 'C5-2 问卷弹窗已打开', txt.slice(-260));
    check(/题目配置|添加题目/.test(txt), 'C5-2 弹窗含题目配置区', txt.slice(-260));
    // 题型选择只在点了「添加题目」之后才出现（空态只有一句引导语）
    await clickByText(page, /^添加题目$/);
    await page.waitForTimeout(800);
    txt = norm(await bodyText(page));
    check(/题型/.test(txt) && /评分题/.test(txt) && /单选题/.test(txt) && /文本题/.test(txt),
      'C5-2 新增题目后三种题型齐备', txt.slice(-300));
  } else {
    check(false, 'C5-2 问卷弹窗（无已结束考试，无法验证）');
    check(false, 'C5-2 弹窗含题目配置区（无已结束考试，无法验证）');
    check(false, 'C5-2 三种题型齐备（无已结束考试，无法验证）');
  }
  check(pageErrors(jsErr).length === 0, 'C5-3 问卷流程无未捕获错误', pageErrors(jsErr).join(' | '));

  await browser.close();

  console.log('\n================ 合计 ' + (pass + fail) + ' 项：PASS ' + pass + '，FAIL ' + fail + ' ================');
  if (fail > 0) {
    console.log('\n失败明细：');
    failures.forEach((f) => console.log('  - ' + f));
    process.exitCode = 1;
  }
}

main().catch((e) => { console.error('SMOKE CRASH', e); process.exitCode = 1; });
