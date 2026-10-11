// 登录态冒烟（通用）：对 admin/teacher/student/exercise 任一后台端，
// 桩对应会话，加载入口，模拟点击每个侧边栏菜单，断言可见内容区(#app-content)渲染出内容。
// 用法: node login_smoke.mjs <publicDir> <entry>
import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import path from 'node:path';

const publicDir = process.argv[2] || path.resolve(process.cwd(), '../../public');
const ENTRY = process.argv[3] || 'admin';

const hostSetTimeout = globalThis.setTimeout;
const hostClearTimeout = globalThis.clearTimeout;
const hostSetInterval = globalThis.setInterval;
const hostClearInterval = globalThis.clearInterval;
const hostQueueMicrotask = globalThis.queueMicrotask;

// 各端登录态桩
function mePayload(entry) {
  switch (entry) {
    case 'teacher':
      return { logged_in: true, teacher: { tea_name: '张老师', tea_id: 1 }, csrf_token: 'X' };
    case 'student':
    case 'exercise':
      return { logged_in: true, student: { stu_name: '考生', stu_id: 1, id: 1, grade_id: 'x' }, csrf_token: 'X' };
    case 'admin':
    default:
      return { logged_in: true, admin: { username: 'admin', admin_power: 'systemAdmin' }, csrf_token: 'X', permissions: ['*'] };
  }
}
function mePath(entry) {
  if (entry === 'teacher') return '/api/teacher/me';
  if (entry === 'student' || entry === 'exercise') return '/api/student/me';
  return '/api/admin/me';
}

function makeDom() {
  const dom = new JSDOM(
    `<!DOCTYPE html><html><head><meta charset="utf-8"></head>
     <body><div id="app"></div><div class="boot-splash"></div></body></html>`,
    { url: `http://127.0.0.1:8099/${ENTRY}`, pretendToBeVisual: true }
  );
  const { window } = dom;
  window.matchMedia = window.matchMedia || ((q) => ({
    matches: false, media: q, onchange: null,
    addListener() {}, removeListener() {},
    addEventListener() {}, removeEventListener() {}, dispatchEvent() { return false; },
  }));
  window.requestAnimationFrame = (cb) => hostSetTimeout(() => cb(Date.now()), 0);
  window.cancelAnimationFrame = (id) => hostClearTimeout(id);
  window.scrollTo = () => {};
  window.confirm = () => true;
  window.alert = () => {};
  window.localStorage.clear?.();

  const okBody = (data) => ({ code: 0, message: 'ok', data });
  const EMPTY = {
    list: [], total: 0, page: 1, per_page: 20, total_pages: 0, has_more: false,
    items: [], categories: [], subjects: [], types: [], stats: {},
  };
  const ME = mePayload(ENTRY);
  const MEP = mePath(ENTRY);
  window.fetch = async (url) => {
    const u = String(url);
    let data = EMPTY;
    if (u.endsWith(MEP)) data = ME;
    else if (u.includes(`${ENTRY}/dashboard`)) data = { stats: { exams: 5, students: 100, quizzes: 994, ongoing: 1 }, news: [], recent: [] };
    else if (u.includes(`/api/${ENTRY}/`)) data = EMPTY;
    else if (u.includes('/api/public/')) data = { site: {}, nav: [], quiz_stats: { total: 994 } };
    else if (u.includes('/api/session')) data = { logged_in: true };
    return {
      ok: true, status: 200,
      headers: { get: () => 'application/json' },
      json: async () => okBody(data),
      text: async () => JSON.stringify(okBody(data)),
      clone() { return this; },
    };
  };
  return dom;
}

function linkGlobals(dom) {
  const { window } = dom;
  const keys = [
    'window', 'document', 'navigator', 'location', 'history', 'localStorage', 'sessionStorage',
    'CSS', 'HTMLElement', 'Element', 'Node', 'NodeList', 'Event', 'CustomEvent', 'MouseEvent',
    'KeyboardEvent', 'InputEvent', 'FormData', 'URLSearchParams', 'URL', 'Blob', 'File',
    'AbortController', 'AbortSignal', 'DOMException', 'getComputedStyle', 'matchMedia',
    'requestAnimationFrame', 'cancelAnimationFrame', 'fetch', 'MutationObserver',
    'IntersectionObserver', 'ResizeObserver', 'alert', 'confirm', 'prompt', 'open', 'close',
    'scrollTo',
    'Text', 'Comment', 'DocumentFragment', 'DOMParser', 'XMLHttpRequest', 'Image', 'SVGElement',
    'HTMLInputElement', 'HTMLSelectElement', 'HTMLTextAreaElement', 'HTMLFormElement',
  ];
  for (const k of keys) {
    let v;
    try { v = window[k]; } catch { continue; }
    if (v === undefined) continue;
    try {
      globalThis[k] = (typeof v === 'function' && /^(getComputedStyle|matchMedia|fetch|requestAnimationFrame|cancelAnimationFrame)$/.test(k))
        ? v.bind(window)
        : v;
    } catch { /* noop */ }
  }
  globalThis.self = window;
  globalThis.localStorage = window.localStorage;
  globalThis.sessionStorage = window.sessionStorage;
  globalThis.setTimeout = hostSetTimeout;
  globalThis.clearTimeout = hostClearTimeout;
  globalThis.setInterval = hostSetInterval;
  globalThis.clearInterval = hostClearInterval;
  globalThis.queueMicrotask = hostQueueMicrotask;
  if (typeof globalThis.fetch !== 'function') globalThis.fetch = window.fetch.bind(window);
}

const errors = [];
process.on('unhandledRejection', (e) => errors.push('unhandledRejection: ' + (e?.stack || e)));
process.on('uncaughtException', (e) => errors.push('uncaughtException: ' + (e?.stack || e)));

const tick = (ms = 50) => new Promise((r) => hostSetTimeout(r, ms));

const file = path.join(publicDir, 'assets', 'js', 'apps', `${ENTRY}.js`);
if (!fs.existsSync(file)) { console.error(`[${ENTRY}] 入口不存在`, file); process.exit(1); }

const dom = makeDom();
linkGlobals(dom);
const { window } = dom;
const doc = window.document;

(async () => {
  try {
    await import('file://' + file.replace(/\\/g, '/') + `?t=${Date.now()}-${ENTRY}`);
    for (let i = 0; i < 20; i++) await tick(25);

    const content = doc.getElementById('app-content');
    const navButtons = [...doc.querySelectorAll('.nav-item')];
    let pass = 0, fail = 0;
    const results = [];

    for (const btn of navButtons) {
      const key = btn.getAttribute('data-key') || (btn.dataset && btn.dataset.key) || '?';
      const label = btn.getAttribute('tip') || (btn.textContent || '').trim();
      btn.click();
      try { window.dispatchEvent(new window.Event('hashchange')); } catch { /* noop */ }
      for (let i = 0; i < 12; i++) await tick(20);

      const childCount = content ? content.children.length : 0;
      const hasError = content ? content.textContent.includes('加载失败') : false;
      if (childCount > 0) {
        pass++;
        results.push(`PASS ${label}(${key}): #app-content 子节点=${childCount}${hasError ? ' [视图内部报“加载失败”，但渲染链路已通]' : ''}`);
      } else {
        fail++;
        results.push(`FAIL ${label}(${key}): #app-content 为空，菜单点不开`);
      }
    }

    console.log(`\n=== [${ENTRY}] 登录态 · 菜单点击验证 (菜单数=${navButtons.length}) ===`);
    for (const r of results) console.log(r);
    console.log(`结果: ${pass} PASS / ${fail} FAIL${errors.length ? `, 运行时错误=${errors.length}` : ''}`);
    if (errors.length) for (const e of errors) console.log('  ' + e);
    try { dom.window.close(); } catch { /* noop */ }
    process.exit(fail > 0 ? 1 : 0);
  } catch (e) {
    console.log(`[${ENTRY}] 加载抛错:`, e?.stack || e);
    if (errors.length) for (const er of errors) console.log('  ' + er);
    process.exit(1);
  }
})();
