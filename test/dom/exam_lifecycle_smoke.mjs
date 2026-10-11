// 考试生命周期端到端冒烟：真实后端 + jsdom 渲染。
//
// 覆盖需求主线：
//   开放入场（下发口令）→ 考生凭口令入场 → 监考出题（逐人随机卷）
//   → 开考（手动 / 到点惰性自动）→ 考生进入答题
//
// 用法（先启动 dev server 于 127.0.0.1:8099）：
//   node exam_lifecycle_smoke.mjs <publicDir> <examId> <examPwd> <teacherName> <stuId> <stuPwd>

import { JSDOM } from 'jsdom';
import path from 'path';
import { pathToFileURL } from 'url';

const publicDir = process.argv[2] || path.resolve(process.cwd(), '../../public');
const assetsDir = path.join(publicDir, 'assets');
const [examId_, examPwd_, teacherName_, stuId_, stuPwd_] = process.argv.slice(3);
const examId = Number(examId_);
const BASE = 'http://127.0.0.1:8099';

/* ------------------------------ jsdom 环境 ------------------------------ */
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
window.requestAnimationFrame = () => 0;
window.cancelAnimationFrame = () => {};
window.scrollTo = () => {};
window.alert = () => {};

/* ------------------------------ fetch 代理 ------------------------------ */
const nativeFetch = globalThis.fetch;
let JAR = { cookie: '', csrf: '' };   // 当前身份
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

const routerStub = { navigate() {}, get current() { return {}; }, refresh() {} };
const navRouter = { last: '', navigate(p) { this.last = p; }, get current() { return {}; }, refresh() {} };

/* ------------------------------ 断言工具 ------------------------------ */
let pass = 0, fail = 0;
function ok(label, cond, extra = '') {
  if (cond) { pass++; console.log(`  PASS  ${label}`); }
  else { fail++; console.log(`  FAIL  ${label}${extra ? '  -> ' + extra : ''}`); }
}
function section(t) { console.log(`\n=== ${t} ===`); }

async function api(method, url, body) {
  const init = { method };
  if (body !== undefined) init.body = JSON.stringify(body);
  const res = await realFetch(url, init);
  const json = await res.json();
  return { status: res.status, json, data: json?.data ?? null, message: json?.message || '' };
}

/* ------------------------------ 视图渲染 ------------------------------ */
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
    await new Promise((r) => setTimeout(r, 100));
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

/* ------------------------------ 主流程 ------------------------------ */
(async () => {
  try {
    if (!examId) { console.error('缺少 examId 参数'); process.exit(3); }

    /* ---- 管理员登录 ---- */
    section('管理员登录');
    const r = await realFetch('/api/admin/login', { method: 'POST', body: JSON.stringify({ username: 'admin', password: 'admin@2026' }) });
    const lj = await r.json();
    JAR.csrf = lj?.data?.csrf_token || '';
    jars.admin = { ...JAR };
    ok('管理员登录成功', r.status === 200 && JAR.cookie !== '', `status=${r.status}`);

    /* ---- 1. 建场即开放入场：考场控制区直接呈现入场开放态（口令随建场生成） ---- */
    section('1. 建场即开放入场 —— 监考页应展示入场开放态与口令');
    let v = await render('views/admin/monitor.js', 'MonitorView', { router: routerStub, query: { exam_id: examId } });
    ok('渲染出「考场控制」区', v.text.includes('考场控制'));
    ok('建场即开放入场（含凭口令入场提示）', v.text.includes('凭考场口令入场') || v.text.includes('入场已开放'), v.text.slice(0, 160));
    ok('已处入场开放态（提供「重新生成口令」）', v.buttons.includes('重新生成口令'), v.buttons.join('|'));
    ok('尚未开考前先有出题入口', v.buttons.includes('开始出题'), v.buttons.join('|'));
    v.dispose?.();

    /* ---- 2. 开放入场：下发口令 ---- */
    section('2. 开放入场 —— 生成考场口令');
    const opened = await api('POST', `/api/admin/exams/${examId}/open`);
    const newPwd = String(opened.data?.exam_pwd ?? '');
    ok('开放入场接口返回 200', opened.status === 200, `status=${opened.status} ${opened.message}`);
    ok('下发了 6 位考场口令', /^\d{6}$/.test(newPwd), newPwd);
    ok('口令与初始不同（重新生成）', newPwd !== String(examPwd_), `${newPwd} vs ${examPwd_}`);

    const examAfterOpen = await api('GET', `/api/admin/exams/${examId}`);
    ok('开放入场后状态仍为未开考', String(examAfterOpen.data?.exam_status) === 'exam', String(examAfterOpen.data?.exam_status));

    /* ---- 3. 开放后监考页展示口令与后续动作 ---- */
    section('3. 已开放入场 —— 监考页展示口令 / 出题 / 开考');
    v = await render('views/admin/monitor.js', 'MonitorView', { router: routerStub, query: { exam_id: examId } });
    ok('展示口令', v.text.includes(newPwd), v.text.slice(0, 200));
    ok('提示入场已开放（凭口令入场提示）', v.text.includes('凭考场口令入场') || v.text.includes('入场已开放'), v.text.slice(0, 200));
    ok('按钮变为「重新生成口令」', v.buttons.includes('重新生成口令'), v.buttons.join('|'));
    ok('提供「开始出题」', v.buttons.includes('开始出题'), v.buttons.join('|'));
    ok('提供「开考」', v.buttons.includes('开考'), v.buttons.join('|'));
    v.dispose?.();

    /* ---- 4. 考生凭口令入场（开考前 15 分钟内） ---- */
    section('4. 考生凭口令入场 —— 进入等待室');
    JAR = { cookie: '', csrf: '' };
    const login = await api('POST', '/api/exam/login', {
      exam_id: examId, stu_id: stuId_, password: stuPwd_, exam_pwd: newPwd,
    });
    ok('入场比赛成功', login.status === 200, `status=${login.status} ${login.message}`);
    ok('阶段为等待开考', String(login.data?.phase) === 'waiting', String(login.data?.phase));

    const statusRes = await api('GET', '/api/exam/status');
    ok('状态接口可读', statusRes.status === 200, `status=${statusRes.status}`);
    ok('等待室中试卷未就绪', statusRes.data?.paper_ready === false, JSON.stringify(statusRes.data?.paper_ready));
    const tooEarly = await api('GET', '/api/exam/paper?paper_id=1');
    ok('未开考不得取题（409）', tooEarly.status === 409, `status=${tooEarly.status}`);
    const stuJar = { ...JAR };

    /* ---- 4b. 考生端等待室渲染 ---- */
    const tv = await render('views/student/exam.js', 'ExamTakeView', { router: navRouter, query: { exam_id: examId } });
    ok('等待室渲染出开考倒计时', tv.text.includes('距开考还有'), tv.text.slice(0, 160));
    ok('等待室展示开考时间', tv.text.includes('开考时间'), tv.text.slice(0, 160));
    tv.dispose?.();

    navRouter.last = '';
    await render('views/student/exam.js', 'ExamLoginView', { router: navRouter, query: { exam_id: examId } });
    await new Promise((r) => setTimeout(r, 400));
    ok('已入场的考生打开登录页自动跳转考场', /\/take/.test(String(navRouter.last)), String(navRouter.last));

    /* ---- 5. 监考出题：逐人随机卷 ---- */
    section('5. 监考出题 —— 为参考班级每人随机组卷');
    JAR = jars.admin;
    const gen = await api('POST', `/api/admin/exams/${examId}/generate`, {});
    ok('出题接口返回 200', gen.status === 200, `status=${gen.status} ${gen.message}`);
    ok('已入场考生均出卷（出题只覆盖已入场）', Number(gen.data?.generated) === Number(gen.data?.entered ?? 0) && Number(gen.data?.generated) >= 1, JSON.stringify(gen.data));
    ok('无缺题警告', Array.isArray(gen.data?.warnings) && gen.data.warnings.length === 0, JSON.stringify(gen.data?.warnings));

    const examAfterGen = await api('GET', `/api/admin/exams/${examId}`);
    ok('出题后状态推进为已排卷', String(examAfterGen.data?.exam_status) === 'paper', String(examAfterGen.data?.exam_status));

    v = await render('views/admin/monitor.js', 'MonitorView', { router: routerStub, query: { exam_id: examId } });
    ok('监考页提示已出题完毕', v.text.includes('已出题完毕'), v.text.slice(0, 200));
    ok('出题后不再显示「开始出题」', !v.buttons.includes('开始出题'), v.buttons.join('|'));
    ok('仍提供「开考」', v.buttons.includes('开考'), v.buttons.join('|'));
    v.dispose?.();

    /* ---- 6. 开考：口令保持稳定 ---- */
    section('6. 开考 —— 口令不得突变');
    const start = await api('POST', `/api/admin/exams/${examId}/start`);
    ok('开考接口返回 200', start.status === 200, `status=${start.status} ${start.message}`);
    ok('开考沿用原口令', String(start.data?.exam_pwd) === newPwd, `${start.data?.exam_pwd} vs ${newPwd}`);

    v = await render('views/admin/monitor.js', 'MonitorView', { router: routerStub, query: { exam_id: examId } });
    ok('监考页提示考试进行中', v.text.includes('考试进行中'), v.text.slice(0, 200));
    ok('进行中不再提供「开考」', !v.buttons.includes('开考'), v.buttons.join('|'));
    ok('进行中仍可全局操作（全部收卷）', v.buttons.includes('全部收卷'), v.buttons.join('|'));
    v.dispose?.();

    /* ---- 7. 考生侧：开考后进入答题 ---- */
    section('7. 开考后考生进入答题');
    JAR = stuJar;
    const stuStatus = await api('GET', '/api/exam/status');
    ok('阶段切换为答题中', String(stuStatus.data?.phase) === 'answering', String(stuStatus.data?.phase));
    ok('试卷已就绪', stuStatus.data?.paper_ready === true, JSON.stringify(stuStatus.data?.paper_ready));

    const paper = await api('GET', '/api/exam/paper?paper_id=1');
    ok('可以取到第 1 题', paper.status === 200, `status=${paper.status} ${paper.message}`);
    ok('取题响应不含答案字段', !JSON.stringify(paper.json).includes('quiz_key'));

    /* ---- 7b. 考生端答题界面渲染 ---- */
    const tv2 = await render('views/student/exam.js', 'ExamTakeView', { router: navRouter, query: { exam_id: examId } });
    ok('答题界面渲染出答题卡', tv2.text.includes('答题卡'), tv2.text.slice(0, 200));
    ok('答题界面渲染出题号进度', tv2.text.includes('第1/4题'), tv2.text.slice(0, 200));
    ok('答题界面渲染出题型与选项', tv2.text.includes('判断题') && tv2.text.includes('对'), tv2.text.slice(0, 240));
    ok('答题卡标出总题数', tv2.text.includes('0/4'), tv2.text.slice(0, 240));
    ok('含上一题/下一题导航', tv2.buttons.includes('下一题') && tv2.buttons.includes('上一题'), tv2.buttons.join('|'));
    ok('含交卷入口', tv2.buttons.includes('交卷'), tv2.buttons.join('|'));
    tv2.dispose?.();

    /* ---- 8. 考试管理列表渲染 ---- */
    section('8. 考试管理列表 —— 行内动作');
    JAR = jars.admin;
    const ev = await render('views/admin/exam.js', 'ExamView', { router: routerStub, can: () => true });
    ok('考试列表渲染出内容', ev.text.length > 120, `len=${ev.text.length}`);
    ok('列表包含夹具考试', ev.text.includes('自动化考试'), ev.text.slice(0, 160));
    ev.dispose?.();

    /* ---- 9. 教师端 ---- */
    section('9. 教师端监考 —— 同样的考场控制');
    JAR = { cookie: '', csrf: '' };
    const tLogin = await api('POST', '/api/teacher/login', { username: teacherName_, password: 'teacher@2026' });
    if (tLogin.status !== 200) {
      ok('教师端登录', false, `status=${tLogin.status} ${tLogin.message}`);
    } else {
      JAR.csrf = tLogin.data?.csrf_token || '';
      jars.teacher = { ...JAR };
      ok('教师端登录成功', true);

      const te = await render('views/teacher/index.js', 'TeacherExamsView', { router: routerStub });
      ok('教师端考试列表渲染出内容', te.text.length > 60, `len=${te.text.length}`);
      te.dispose?.();

      const tm = await render('views/teacher/index.js', 'TeacherMonitorView', { router: routerStub, query: { exam_id: examId } });
      ok('教师端监考页有「考场控制」', tm.text.includes('考场控制'), tm.text.slice(0, 200));
      ok('教师端监考页显示进行中', tm.text.includes('考试进行中'), tm.text.slice(0, 200));
      ok('教师端监考页有全局收卷', tm.buttons.includes('全员交卷'), tm.buttons.join('|'));
      tm.dispose?.();
    }

    /* ---- 10. 考生个人中心：待考/入场提示 ---- */
    section('10. 考生个人中心 —— 开考提示与入场景');
    JAR = { cookie: '', csrf: '' };
    const sLogin = await api('POST', '/api/student/login', { username: stuId_, password: stuPwd_ });
    ok('考生登录个人中心', sLogin.status === 200, `status=${sLogin.status} ${sLogin.message}`);

    const sc = await render('views/student/center.js', 'StudentExamsView', { router: navRouter });
    ok('我的考试列出该场考试', sc.text.includes('自动化考试'), sc.text.slice(0, 200));
    ok('顶部给出开考提示', sc.text.includes('已开考'), sc.text.slice(0, 220));
    ok('提供「继续答题」入口', sc.buttons.includes('继续答题'), sc.buttons.join('|'));
    ok('卡片标出「进行中」', sc.text.includes('进行中'), sc.text.slice(0, 220));

    console.log(`\n结果: ${pass} PASS / ${fail} FAIL`);
    process.exit(fail ? 1 : 0);
  } catch (e) {
    console.error('FATAL', e);
    process.exit(3);
  }
})();
