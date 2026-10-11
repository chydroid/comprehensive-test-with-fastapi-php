/**
 * 考场等待室端到端冒烟：倒计时 → 考试基本信息 → 注意事项 → 「进入考场」按钮 → 答题
 *
 * 为什么单独写这一份：等待室的行为几乎全在前端（倒计时用服务端时间、
 * 到点才放按钮、点击后才拉卷），PHP 单测只能锁住接口字段，锁不住
 * 「按钮到底有没有出现、点了之后能不能进答题」。
 *
 * 用法（先启动 dev server 于 127.0.0.1:8099）：
 *   node test/dom/exam_waitroom_smoke.mjs <publicDir> <examId> <stuId> <stuPwd>
 *
 * 断言：
 *  1) 未开考时渲染大号倒计时（mm:ss / 天 hh:mm:ss），且**不**显示进入按钮；
 *  2) 倒计时下方展示考试基本信息（科目 / 时长 / 满分 / 题量）与注意事项（多条）；
 *  3) 监考开考后（轮询感知）按钮出现并提示已开考；
 *  4) 点击按钮才真正进入答题（渲染出答题卡与题目）。
 */

import { JSDOM } from 'jsdom';
import path from 'path';
import { pathToFileURL } from 'url';

const publicDir = process.argv[2] || path.resolve(process.cwd(), '../../public');
const assetsDir = path.join(publicDir, 'assets');
const [examId_, stuId_, stuPwd_] = process.argv.slice(3);
const examId = Number(examId_);
const BASE = 'http://127.0.0.1:8099';

/* ------------------------------ jsdom 环境 ------------------------------ */
const dom = new JSDOM('<!DOCTYPE html><html><body><div id="app"></div><div id="app-content"></div></body></html>', {
  url: 'http://127.0.0.1:8099/exam',
  pretendToBeVisual: true,
});
const { window } = dom;
for (const k of ['window','document','navigator','location','history','localStorage','sessionStorage','CSS','HTMLElement','Element','Node','NodeList','Event','CustomEvent','MouseEvent','KeyboardEvent','InputEvent','FormData','URLSearchParams','URL','Blob','File','AbortController','AbortSignal','DOMException','getComputedStyle','matchMedia','MutationObserver','IntersectionObserver','ResizeObserver','Text','Comment','DocumentFragment','DOMParser','XMLHttpRequest','Image','SVGElement','HTMLInputElement','HTMLSelectElement','HTMLTextAreaElement','HTMLFormElement']) {
  try { const v = window[k]; if (v !== undefined) globalThis[k] = (typeof v === 'function' && /^(getComputedStyle|matchMedia)$/.test(k)) ? v.bind(window) : v; } catch {}
}
globalThis.self = window;
globalThis.localStorage = window.localStorage;
globalThis.sessionStorage = window.sessionStorage;
window.requestAnimationFrame = () => 0;
window.cancelAnimationFrame = () => {};
window.scrollTo = () => {};
window.alert = () => {};

/* ------------------------------ fetch 代理 ------------------------------ */
const nativeFetch = globalThis.fetch;
let JAR = { cookie: '', csrf: '' };
const jars = {};

async function realFetch(input, init = {}) {
  let url = typeof input === 'string' ? input : input.url;
  if (url.startsWith('/')) url = BASE + url;
  const headers = { ...(init.headers || {}) };
  if (JAR.cookie) headers['Cookie'] = JAR.cookie;
  if (JAR.csrf && !['GET', 'HEAD'].includes(String(init.method || 'GET').toUpperCase())) {
    headers['X-CSRF-Token'] = JAR.csrf;
  }
  if (init.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
  const res = await nativeFetch(url, { method: init.method || 'GET', headers, body: init.body, redirect: 'manual' });
  const text = await res.text();
  const setCookie = res.headers.get('set-cookie');
  if (setCookie) {
    const m = /CSIPSESSION=([^;]+)/.exec(setCookie);
    if (m) JAR.cookie = 'CSIPSESSION=' + m[1];
  }
  return {
    ok: res.ok, status: res.status, statusText: res.statusText,
    headers: { get: (h) => res.headers.get(String(h).toLowerCase()) },
    text: async () => text,
    json: async () => { try { return JSON.parse(text); } catch { return {}; } },
  };
}
globalThis.fetch = realFetch;
window.fetch = realFetch;

const navRouter = { last: '', navigate(p) { this.last = p; }, get current() { return {}; }, refresh() {} };

/* ------------------------------ 断言工具 ------------------------------ */
let pass = 0, fail = 0;
function ok(label, cond, extra = '') {
  if (cond) { pass++; console.log(`  PASS  ${label}`); }
  else { fail++; console.log(`  FAIL  ${label}${extra ? '  -> ' + extra : ''}`); }
}
function section(t) { console.log(`\n=== ${t} ===`); }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function api(method, url, body) {
  const init = { method };
  if (body !== undefined) init.body = JSON.stringify(body);
  const res = await realFetch(url, init);
  const json = await res.json();
  return { status: res.status, json, data: json?.data ?? null, message: json?.message || '' };
}

/** 渲染视图并等到文本稳定（倒计时每秒变化，故只按长度判稳） */
async function render(mod, fnName, args) {
  const url = pathToFileURL(path.join(assetsDir, 'js', mod)).href + `?t=${Date.now()}`;
  const m = await import(url);
  const fn = m[fnName];
  if (typeof fn !== 'function') throw new Error(`导出 ${fnName} 不是函数`);
  const result = await fn(args);
  const node = result && result.node ? result.node : result;
  const host = window.document.getElementById('app-content');
  host.innerHTML = '';
  if (node && node.nodeType) host.append(node);
  let prev = -1, stable = 0;
  for (let i = 0; i < 40; i++) {
    await sleep(100);
    const len = (host.textContent || '').replace(/\s+/g, '').length;
    if (len === prev) { stable++; if (stable >= 3) break; } else { stable = 0; }
    prev = len;
  }
  return {
    text: (host.textContent || '').replace(/\s+/g, ''),
    el: host,
    buttons: [...host.querySelectorAll('button')].map((b) => (b.textContent || '').trim()),
    dispose: result && result.dispose,
  };
}

/** 等到「进入考场」按钮可见，最多 ms 毫秒 */
async function waitEnterButton(host, ms) {
  const deadline = Date.now() + ms;
  while (Date.now() < deadline) {
    const box = host.querySelector('.wait-enter-box');
    if (box && box.style.display !== 'none') {
      const btn = box.querySelector('button');
      if (btn) return btn;
    }
    await sleep(200);
  }
  return null;
}

(async () => {
  try {
    if (!examId) { console.error('缺少 examId 参数'); process.exit(3); }

    /* ---- 管理员登录 + 开放入场 ---- */
    section('准备：管理员开放入场');
    const r = await realFetch('/api/admin/login', { method: 'POST', body: JSON.stringify({ username: 'admin', password: 'admin@2026' }) });
    const lj = await r.json();
    JAR.csrf = lj?.data?.csrf_token || '';
    jars.admin = { ...JAR };
    ok('管理员登录成功', r.status === 200 && JAR.cookie !== '', `status=${r.status}`);

    const opened = await api('POST', `/api/admin/exams/${examId}/open`);
    const pwd = String(opened.data?.exam_pwd ?? '');
    ok('开放入场并下发口令', opened.status === 200 && /^\d{4,10}$/.test(pwd), `${opened.status} ${pwd}`);

    /* ---- 考生凭口令入场 ---- */
    section('一、入场 —— 未开考应看到倒计时与考试信息');
    JAR = { cookie: '', csrf: '' };
    const login = await api('POST', '/api/exam/login', {
      exam_id: examId, stu_id: stuId_, password: stuPwd_, exam_pwd: pwd,
    });
    ok('凭考场口令入场成功', login.status === 200, `status=${login.status} ${login.message}`);
    ok('阶段为等待开考', String(login.data?.phase) === 'waiting', String(login.data?.phase));
    const stuJar = { ...JAR };

    const v = await render('views/student/exam.js', 'ExamTakeView', { router: navRouter, query: { exam_id: examId } });

    const cd = v.el.querySelector('.wait-cd-value');
    ok('渲染出倒计时数字', !!cd, v.text.slice(0, 200));
    ok('倒计时格式为 mm:ss 或 天 hh:mm:ss',
      !!cd && /^(\d+天)?\d{2}:\d{2}:\d{2}$/.test((cd.textContent || '').trim()),
      cd ? cd.textContent : '(无元素)');
    ok('标题写明「距开考还有」', v.text.includes('距开考还有'), v.text.slice(0, 200));
    ok('倒计时区域可见（未到点不放行）',
      v.el.querySelector('.wait-cd')?.style.display !== 'none');

    const enterBox = v.el.querySelector('.wait-enter-box');
    ok('存在「进入考场」按钮节点', !!enterBox?.querySelector('button'), v.buttons.join('|'));
    ok('未到点：进入按钮隐藏', !!enterBox && enterBox.style.display === 'none',
      enterBox ? `display=${enterBox.style.display}` : '(无元素)');

    ok('展示考试基本信息', v.text.includes('考试基本信息'), v.text.slice(0, 300));
    ok('基本信息含时长/满分/题量', /分钟/.test(v.text) && /分/.test(v.text) && /题/.test(v.text), v.text.slice(0, 300));
    ok('基本信息未出现空值科目（科目名已下发）', !v.text.includes('考试科目—'), v.text.slice(0, 300));

    ok('展示考试注意事项', v.text.includes('考试注意事项'), v.text.slice(0, 300));
    const noticeItems = [...v.el.querySelectorAll('.wait-notice li')];
    ok('注意事项按条目渲染（≥3 条）', noticeItems.length >= 3, `count=${noticeItems.length}`);
    ok('条目已去掉行首自带编号', noticeItems.every((li) => !/^\s*\d+[.、]/.test(li.textContent || '')),
      noticeItems.map((li) => li.textContent).join(' | '));

    /* ---- 监考出题 + 开考 ---- */
    section('二、监考开考 —— 轮询感知后应出现「进入考场」按钮');
    JAR = jars.admin;
    const gen = await api('POST', `/api/admin/exams/${examId}/generate`, {});
    ok('出题成功', gen.status === 200, `status=${gen.status} ${gen.message}`);
    const start = await api('POST', `/api/admin/exams/${examId}/start`);
    ok('开考成功', start.status === 200, `status=${start.status} ${start.message}`);

    JAR = stuJar;
    const enterBtn = await waitEnterButton(v.el, 15000);
    ok('开考后「进入考场」按钮出现', !!enterBtn, v.text.slice(0, 300));
    // 注意：v.text 是 render() 当时的快照，开考后界面已变化，这里必须重新取实时文本
    const liveText = (v.el.textContent || '').replace(/\s+/g, '');
    ok('提示本场已开考', liveText.includes('本场考试已开考'), liveText.slice(0, 300));
    ok('倒计时已隐藏', v.el.querySelector('.wait-cd')?.style.display === 'none',
      `display=${v.el.querySelector('.wait-cd')?.style.display}`);

    /* ---- 点击按钮才进入答题 ---- */
    section('三、点击「进入考场」 —— 进入答题界面');
    if (enterBtn) {
      enterBtn.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
      let ready = false;
      for (let i = 0; i < 60; i++) {
        await sleep(200);
        const t = (v.el.textContent || '').replace(/\s+/g, '');
        if (t.includes('答题卡') || /第1\/\d+题/.test(t)) { ready = true; break; }
      }
      const t = (v.el.textContent || '').replace(/\s+/g, '');
      ok('渲染出答题界面（答题卡 / 题号进度）', ready, t.slice(0, 300));
      ok('答题界面含交卷入口', [...v.el.querySelectorAll('button')].some((b) => (b.textContent || '').trim() === '交卷'),
        [...v.el.querySelectorAll('button')].map((b) => (b.textContent || '').trim()).join('|'));
    }

    v.dispose?.();
    console.log(`\n结果: ${pass} PASS / ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
  } catch (e) {
    console.error('FATAL', e);
    process.exit(3);
  }
})();
