// 「系统设置」页真实交互冒烟：切换标签 → 渲染 schema 表单 → 改值保存 → 校验拦截 → 恢复
// 用法: node settings_view_smoke.mjs <publicDir>
import { JSDOM } from 'jsdom';
import path from 'path';
import { pathToFileURL } from 'url';

const publicDir = process.argv[2] || path.resolve(process.cwd(), 'public');
const assetsDir = path.join(publicDir, 'assets');
const BASE = 'http://127.0.0.1:8099';

const dom = new JSDOM('<!DOCTYPE html><html><body><div id="app"></div><div id="app-content"></div></body></html>', {
  url: 'http://127.0.0.1:8099/admin',
  pretendToBeVisual: true,
});
const { window } = dom;

for (const k of ['window','document','navigator','location','history','localStorage','sessionStorage','CSS','HTMLElement','Element','Node','NodeList','Event','CustomEvent','MouseEvent','KeyboardEvent','InputEvent','FormData','URLSearchParams','URL','Blob','File','AbortController','AbortSignal','DOMException','getComputedStyle','matchMedia','MutationObserver','IntersectionObserver','ResizeObserver','Text','Comment','DocumentFragment','DOMParser','XMLHttpRequest','Image','SVGElement','HTMLInputElement','HTMLSelectElement','HTMLTextAreaElement','HTMLFormElement']) {
  try { const v = window[k]; if (v !== undefined) globalThis[k] = (typeof v === 'function' && /^(getComputedStyle|matchMedia)$/.test(k)) ? v.bind(window) : v; } catch {}
}
globalThis.self = window;
globalThis.localStorage = window.localStorage;
globalThis.sessionStorage = window.sessionStorage;
window.requestAnimationFrame = (cb) => 0;
window.cancelAnimationFrame = () => {};
window.scrollTo = () => {};

let SESSION_COOKIE = '';
const nativeFetch = globalThis.fetch;
async function realFetch(input, init = {}) {
  let url = typeof input === 'string' ? input : input.url;
  if (url.startsWith('/')) url = BASE + url;
  const headers = { ...(init.headers || {}) };
  if (SESSION_COOKIE) headers['Cookie'] = SESSION_COOKIE;
  if (init.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
  const res = await nativeFetch(url, { method: init.method || 'GET', headers, body: init.body, redirect: 'manual' });
  const text = await res.text();
  const setCookie = res.headers.get('set-cookie');
  if (setCookie && !SESSION_COOKIE) {
    const m = /CSIPSESSION=([^;]+)/.exec(setCookie);
    if (m) SESSION_COOKIE = 'CSIPSESSION=' + m[1];
  }
  // http.js 会读 res.headers.get(...)，必须提供 headers 代理
  const headerProxy = {
    get: (h) => {
      const key = String(h).toLowerCase();
      if (key === 'set-cookie') return setCookie;
      return res.headers.get(key);
    },
  };
  return {
    ok: res.ok, status: res.status, statusText: res.statusText, headers: headerProxy,
    text: async () => text,
    json: async () => { try { return JSON.parse(text); } catch { return {}; } },
  };
}
globalThis.fetch = realFetch;
window.fetch = realFetch;

/* ---------------- 断言工具 ---------------- */
let pass = 0, fail = 0;
const ok = (name, cond, info = '') => {
  if (cond) { pass++; console.log(`  PASS  ${name}`); }
  else { fail++; console.log(`  FAIL  ${name}${info ? ' -> ' + info : ''}`); }
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function waitFor(fn, timeout = 5000) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) { if (fn()) return true; await sleep(80); }
  return false;
}
/** 异步谓词版：fn 返回 Promise<boolean>（用于「等后端真的落库」这类断言） */
async function waitForAsync(fn, timeout = 5000) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) { if (await fn()) return true; await sleep(100); }
  return false;
}
const $ = (sel) => window.document.querySelector(sel);
const $$ = (sel) => Array.from(window.document.querySelectorAll(sel));
const norm = (s) => (s || '').replace(/\s+/g, '');
const btnByText = (txt) => $$('button').find((b) => norm(b.textContent).includes(txt));
const tabByText = (txt) => $$('.tabs .tab').find((b) => norm(b.textContent).includes(txt));

/* ---------------- 主体 ---------------- */
(async () => {
  console.log('=== 系统设置页 真实交互冒烟 ===');

  const login = await realFetch('/api/admin/login', { method: 'POST', body: JSON.stringify({ username: 'admin', password: 'admin@2026' }) });
  const lj = await login.json();
  ok('管理员登录成功', login.status === 200 && !!(lj?.data?.csrf_token), `status=${login.status}`);
  // 写操作需 CSRF 令牌；真实应用由 admin 入口在登录后注入，此处显式设置。
  // 关键：必须导入「无查询串」的 http.js —— 应用内部模块图是
  // system.js → api/index.js → '../core/http.js'（无 ?t=）。带查询串会得到
  // 另一个模块实例，令牌设不到应用用的那个上，PUT 会因缺 X-CSRF-Token 被拒。
  const httpMod = await import(pathToFileURL(path.join(assetsDir, 'js/core/http.js')).href);
  httpMod.setCsrfToken(String(lj?.data?.csrf_token || ''));

  const c = window.document.getElementById('app-content');
  const mod = await import(pathToFileURL(path.join(assetsDir, 'js/views/admin/system.js')).href + `?t=${Date.now()}`);
  const view = await mod.ConfigView({});
  const node = view && view.node ? view.node : view;
  c.innerHTML = '';
  c.append(node.nodeType ? node : (node.node || node));

  /* ---- 1. 标签页由后端分组渲染 ---- */
  // 期望标签数 = 固定「站点信息」+ 后端 GROUPS 分组数，避免写死数量后
  // 新增分组（如「模拟考试与练习」）时断言过期或弱化。
  const meta = (await (await realFetch('/api/admin/settings')).json())?.data || {};
  const groupLabels = Object.values(meta.groups || {}).map((g) => g.label);
  const expectTabs = 1 + groupLabels.length;

  const gotTabs = await waitFor(() => $$('.tabs .tab').length === expectTabs);
  ok(`渲染出 ${expectTabs} 个设置标签页`, gotTabs, `实际 ${$$('.tabs .tab').length}`);
  const labels = $$('.tabs .tab').map((b) => norm(b.textContent));
  for (const l of ['站点信息', '考试规则', '安全策略', '界面与体验']) {
    ok(`标签含「${l}」`, labels.some((x) => x.includes(l)), labels.join('|'));
  }
  // 每个后端分组都必须有对应标签页（防止新增分组前端不渲染）
  for (const l of groupLabels) {
    ok(`标签含后端分组「${l}」`, labels.some((x) => x.includes(norm(l))), labels.join('|'));
  }
  ok('默认停在站点信息（6 个字段）', $$('.form-grid input, .form-grid textarea').length === 6,
    `控件数=${$$('.form-grid input, .form-grid textarea').length}`);

  /* ---- 2. 切到「考试规则」，控件由 schema 驱动 ---- */
  const leadInput = () => $('input[name="exam_entry_lead_minutes"]');
  tabByText('考试规则').click();
  ok('考试规则表单渲染', await waitFor(() => !!leadInput()));

  const li = leadInput();
  ok('入场分钟数带 min/max 约束', li.getAttribute('min') === '0' && li.getAttribute('max') === '1440',
    `min=${li.getAttribute('min')} max=${li.getAttribute('max')}`);
  ok('入场分钟数带单位后缀', norm($('.input-addon')?.textContent) === '分钟',
    norm($('.input-addon')?.textContent));
  ok('开关型设置渲染为 switch',
    $$('.switch').length >= 2
      && $('input[name="exam_allow_view_answer"]')?.type === 'checkbox'
      && $('input[name="exam_show_score_immediately"]')?.type === 'checkbox',
    `switch=${$$('.switch').length} allowView=${$('input[name="exam_allow_view_answer"]')?.type}`);
  ok('每个字段都带帮助文案', $$('.field-hint').length >= 5, `hint=${$$('.field-hint').length}`);

  /* ---- 3. 越界值在前端即被拦下 ---- */
  const original = li.value;
  li.value = '99999';
  btnByText('保存设置').click();
  await sleep(700);
  let api = await (await realFetch('/api/admin/settings')).json();
  ok('越界值未写入后端', Number(api?.data?.values?.exam_entry_lead_minutes) === Number(original),
    `后端=${api?.data?.values?.exam_entry_lead_minutes}`);

  /* ---- 4. 合法值保存后立即生效（含公开接口） ---- */
  leadInput().value = '45';
  btnByText('保存设置').click();
  // 轮询后端直到真正落库（避免固定 sleep 造成的偶发假失败）
  const saved = await waitForAsync(async () => {
    const j = await (await realFetch('/api/admin/settings')).json();
    return Number(j?.data?.values?.exam_entry_lead_minutes) === 45;
  }, 6000);
  api = await (await realFetch('/api/admin/settings')).json();
  ok('保存后管理端读到新值', saved, `后端=${api?.data?.values?.exam_entry_lead_minutes}`);
  const pub = await (await realFetch('/api/public/settings')).json();
  ok('公开接口同步新值（前端文案随动）', Number(pub?.data?.exam_entry_lead_minutes) === 45,
    `公开=${pub?.data?.exam_entry_lead_minutes}`);

  /* ---- 5. 恢复原值，避免污染环境 ---- */
  tabByText('考试规则').click();
  await waitFor(() => !!leadInput());
  leadInput().value = String(original);
  btnByText('保存设置').click();
  await sleep(900);
  api = await (await realFetch('/api/admin/settings')).json();
  ok('恢复原值成功', Number(api?.data?.values?.exam_entry_lead_minutes) === Number(original),
    `后端=${api?.data?.values?.exam_entry_lead_minutes}`);

  /* ---- 6. 安全策略分组含开关与数字混排 ---- */
  tabByText('安全策略').click();
  ok('安全策略表单渲染', await waitFor(() => !!$('input[name="password_min_length"]')));
  ok('限流总开关为 switch', !!$('input[name="rate_limit_enabled"]'));
  ok('登录限流次数为数字输入', $('input[name="login_max_attempts"]')?.type === 'number',
    $('input[name="login_max_attempts"]')?.type);
  ok('未泄露到公开接口',
    !('login_max_attempts' in ((await (await realFetch('/api/public/settings')).json())?.data || {})));

  /* ---- 7. 界面与体验分组 ---- */
  tabByText('界面与体验').click();
  ok('界面分组渲染', await waitFor(() => !!$('input[name="monitor_refresh_seconds"]')));

  /* ---- 8. 模拟考试与练习分组（隔离策略 + 数据上限） ---- */
  tabByText('模拟考试与练习').click();
  ok('模拟考试与练习表单渲染', await waitFor(() => !!$('input[name="mock_daily_limit"]')));
  ok('「正式考试期间开放在线练习」为开关',
    $('input[name="exercise_allow_during_exam"]')?.type === 'checkbox',
    $('input[name="exercise_allow_during_exam"]')?.type);
  ok('「正式考试期间开放模拟考试」为开关',
    $('input[name="mock_allow_during_exam"]')?.type === 'checkbox',
    $('input[name="mock_allow_during_exam"]')?.type);
  const dailyIn = $('input[name="mock_daily_limit"]');
  ok('每日场次上限为数字输入且范围为 1–100',
    dailyIn?.type === 'number' && dailyIn.getAttribute('min') === '1' && dailyIn.getAttribute('max') === '100',
    `type=${dailyIn?.type} min=${dailyIn?.getAttribute('min')} max=${dailyIn?.getAttribute('max')}`);
  const maxQIn = $('input[name="mock_max_questions"]');
  ok('单场题数上限为数字输入且范围为 1–1000',
    maxQIn?.type === 'number' && maxQIn.getAttribute('min') === '1' && maxQIn.getAttribute('max') === '1000',
    `type=${maxQIn?.type} min=${maxQIn?.getAttribute('min')} max=${maxQIn?.getAttribute('max')}`);
  // 只有两个上限项带单位（开关项没有单位），断言到具体文案而非笼统数量
  const addons = $$('.input-addon').map((n) => norm(n.textContent));
  ok('上限项带正确单位后缀（场 / 题，新增的「天」亦带单位）',
    addons.includes('场') && addons.includes('题') && addons.includes('天'),
    addons.join('|'));
  // 上限类参数需在考生端可见（配额提示），策略开关同样属于无敏感信息的公开子集
  const pubPractice = ((await (await realFetch('/api/public/settings')).json())?.data) || {};
  for (const k of ['mock_daily_limit', 'mock_max_questions', 'mock_allow_during_exam', 'exercise_allow_during_exam']) {
    ok(`公开接口下发 ${k}`, k in pubPractice, Object.keys(pubPractice).join('|'));
  }

  console.log(`\n结果: ${pass} PASS / ${fail} FAIL`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('FATAL', e); process.exit(3); });
