// 门户考生登录跳转回归：登录成功后应整页跳转到 /student（真实页面），
// 而不是把 /student 当作 hash 路由（router.navigate('/student') 会写成
// location.hash='#/student'，门户 SPA 无此路由 -> 报「页面不存在 / 路径 /student 无效」）。
// 用法: node portal_login_smoke.mjs [publicDir]
import { JSDOM, VirtualConsole } from 'jsdom';
import path from 'path';
import { pathToFileURL } from 'url';

const publicDir = process.argv[2] || path.resolve(process.cwd(), '../../public');
const assetsDir = path.join(publicDir, 'assets');
const BASE = 'http://127.0.0.1:8099';

const virtualConsole = new VirtualConsole();
virtualConsole.on('jsdomError', (e) => console.log('[jsdomError]', e.message));
const dom = new JSDOM('<!DOCTYPE html><html><body><div id="app"></div></body></html>', {
  url: `${BASE}/portal`,
  pretendToBeVisual: true,
  virtualConsole,
});
const { window } = dom;

for (const k of ['window', 'document', 'navigator', 'location', 'history', 'localStorage', 'sessionStorage', 'CSS', 'HTMLElement', 'Element', 'Node', 'NodeList', 'Event', 'CustomEvent', 'MouseEvent', 'KeyboardEvent', 'InputEvent', 'FormData', 'URLSearchParams', 'URL', 'Blob', 'File', 'AbortController', 'AbortSignal', 'DOMException', 'getComputedStyle', 'matchMedia', 'MutationObserver', 'IntersectionObserver', 'ResizeObserver', 'Text', 'Comment', 'DocumentFragment', 'DOMParser', 'XMLHttpRequest', 'Image', 'SVGElement', 'HTMLInputElement', 'HTMLSelectElement', 'HTMLTextAreaElement', 'HTMLFormElement']) {
  try { const v = window[k]; if (v !== undefined) globalThis[k] = (typeof v === 'function' && /^(getComputedStyle|matchMedia)$/.test(k)) ? v.bind(window) : v; } catch {}
}
globalThis.self = window;
globalThis.localStorage = window.localStorage;
globalThis.sessionStorage = window.sessionStorage;
globalThis.requestAnimationFrame = () => 0;
globalThis.cancelAnimationFrame = () => {};
window.requestAnimationFrame = () => 0;
window.cancelAnimationFrame = () => {};
window.scrollTo = () => {};

/* 捕获整页跳转：路由/门户代码使用的是裸全局 location/history（非 window.location），
 * 而 harness 已把 globalThis.location 设为可写自有属性，因此直接替换即可。 */
const NAV = [];
const assignSpy = (u) => { NAV.push(String(u)); };
let _hash = '#/';
const fakeLocation = {
  pathname: '/portal',
  search: '',
  origin: BASE,
  get href() { return BASE + '/portal' + this.search + this.hash; },
  get hash() { return _hash; },
  set hash(v) {
    const nv = String(v).startsWith('#') ? v : '#' + v;
    if (nv !== _hash) { _hash = nv; window.dispatchEvent(new window.Event('hashchange')); }
  },
  assign: assignSpy,
  replace: assignSpy,
};
globalThis.location = fakeLocation;
window.history.replaceState = (state, title, url) => {
  if (typeof url === 'string' && url.includes('#')) _hash = '#' + url.split('#')[1];
};
globalThis.history = window.history;

/* 桩 fetch：考生会话 + 登录 + 公开接口，全部走本地桩，避免依赖真实服务器 */
const nativeFetch = globalThis.fetch;
async function stubFetch(input, init = {}) {
  let url = typeof input === 'string' ? input : input.url;
  const body = init.body ? JSON.parse(init.body) : null;
  const H = (obj) => ({
    status: 200, statusText: 'OK', ok: true,
    headers: { get: (h) => { const l = String(h).toLowerCase(); if (l === 'content-type') return 'application/json'; return null; } },
    text: async () => JSON.stringify(obj),
    json: async () => obj,
  });

  if (url.endsWith('/api/student/me')) {
    return H({ code: 0, message: 'ok', data: { logged_in: false } });
  }
  if (url.endsWith('/api/student/login')) {
    console.log('[stub] student/login called, body=', JSON.stringify(body));
    return H({ code: 0, message: 'ok', data: { csrf_token: 'T', student: { stu_name: '测试考生', grade_id: 'G1' } } });
  }
  if (url.includes('/api/public/') || url.includes('/api/health')) {
    return H({ code: 0, message: 'ok', data: {} });
  }
  // 其余请求（若有）回落到真实 fetch
  return nativeFetch(input, init);
}
globalThis.fetch = stubFetch;
window.fetch = stubFetch;

/* 断言工具 */
const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok: !!ok });
  console.log(`[${ok ? 'PASS' : 'FAIL'}] ${name}${!ok && detail ? ` -> ${detail}` : ''}`);
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function waitFor(fn, { timeout = 6000, interval = 50 } = {}) {
  const end = Date.now() + timeout;
  for (;;) {
    const v = fn();
    if (v) return v;
    if (Date.now() > end) return null;
    await sleep(interval);
  }
}

(async () => {
  try {
    /* 导入门户入口（模块顶层会执行 boot：读取会话 -> 未登录 -> 渲染首页） */
    await import(pathToFileURL(path.join(assetsDir, 'js/apps/portal.js')).href + `?t=${Date.now()}`);

    // 等待首页渲染（路由已启动）
    check('门户首页渲染（含品牌名）', await waitFor(() => document.querySelector('.portal, .portal-nav, #app *')));

    /* 模拟未登录用户点击「在线练习」：改写 hash 触发路由守卫 -> require -> /login
     * 注意：路由读的是全局 location（即上面的 fakeLocation），故必须改写全局 location.hash */
    location.hash = '#/exercise';
    window.dispatchEvent(new window.Event('hashchange'));

    const form = await waitFor(() => document.querySelector('form.login-form'));
    check('未登录访问 /exercise 被重定向到登录页（出现登录表单）', !!form);
    if (!form) {
      console.log('DEBUG hash=', location.hash, '| body=', (document.body.textContent || '').slice(0, 200));
      const pass = results.filter((r) => r.ok).length;
      console.log(`\n结果: ${pass} PASS / ${results.length - pass} FAIL`);
      process.exit(1);
    }

    const u = form.querySelector('input[name="username"]');
    const p = form.querySelector('input[name="password"]');
    check('登录表单含用户名/密码输入框', !!u && !!p);
    u.value = 'student01';
    p.value = '123456';

    /* 提交登录 */
    form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    const submitted = await waitFor(() => NAV.length > 0, { timeout: 4000 });
    if (!submitted) {
      console.log('DEBUG NAV=', JSON.stringify(NAV), '| hash=', location.hash);
      const toast = [...document.querySelectorAll('.toast, .toast-error, .toast-success, .toast-warning')].map((t) => t.textContent).join(' | ');
      const alert = document.querySelector('.alert-danger, .alert-warning');
      console.log('DEBUG toast=', toast || '(none)', '| alert=', alert ? alert.textContent : '(none)');
      console.log('DEBUG body tail=', (document.body.textContent || '').replace(/\s+/g, ' ').slice(-200));
    }

    check('登录成功后执行整页跳转', NAV.length > 0, `NAV=${JSON.stringify(NAV)}`);
    check('跳转目标是考生中心真实页面 /student', NAV.includes('/student'), `NAV=${JSON.stringify(NAV)}`);
    check('跳转未误写成 hash 路由（location.hash 不应变成 #/student）',
      !String(location.hash || '').startsWith('#/student'), `hash=${location.hash}`);
    check('页面未出现「路径 /student 无效」的 404', !/路径\s*\/student\s*无效|页面不存在/.test(document.body.textContent || ''));

    const pass = results.filter((r) => r.ok).length;
    const fail = results.length - pass;
    console.log(`\n结果: ${pass} PASS / ${fail} FAIL （共 ${results.length} 项）`);
    process.exit(fail ? 1 : 0);
  } catch (e) {
    console.error('FATAL', e);
    process.exit(3);
  }
})();
