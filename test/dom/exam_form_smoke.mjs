// 新建考试表单行为验证（真实 dev server + 真实视图代码）
//   1) 默认开始时间 = 当前 + 10 分钟，结束时间 = 开始 + 1 小时
//   2) 参考班级为多选；仅有一个班级时默认选中
//   3) 题目数量输入框只接受数字
//   4) 数量失焦时校验题库存量：不足则 toast + 焦点保留在该输入框
// 用法: node exam_form_smoke.mjs [publicDir]
import { JSDOM } from 'jsdom';
import path from 'path';
import { pathToFileURL } from 'url';

const publicDir = process.argv[2] || path.resolve(process.cwd(), '../../public');
const assetsDir = path.join(publicDir, 'assets');
const BASE = 'http://127.0.0.1:8099';

const dom = new JSDOM('<!DOCTYPE html><html><body><div id="app"></div><div id="app-content"></div></body></html>', {
  url: `${BASE}/admin`,
  pretendToBeVisual: true,
});
const { window } = dom;

for (const k of ['window', 'document', 'navigator', 'location', 'history', 'localStorage', 'sessionStorage', 'CSS', 'HTMLElement', 'Element', 'Node', 'NodeList', 'Event', 'CustomEvent', 'MouseEvent', 'KeyboardEvent', 'InputEvent', 'FormData', 'URLSearchParams', 'URL', 'Blob', 'File', 'AbortController', 'AbortSignal', 'DOMException', 'getComputedStyle', 'matchMedia', 'MutationObserver', 'IntersectionObserver', 'ResizeObserver', 'Text', 'Comment', 'DocumentFragment', 'DOMParser', 'XMLHttpRequest', 'Image', 'SVGElement', 'HTMLInputElement', 'HTMLSelectElement', 'HTMLTextAreaElement', 'HTMLFormElement']) {
  try { const v = window[k]; if (v !== undefined) globalThis[k] = (typeof v === 'function' && /^(getComputedStyle|matchMedia)$/.test(k)) ? v.bind(window) : v; } catch {}
}
globalThis.self = window;
globalThis.localStorage = window.localStorage;
globalThis.sessionStorage = window.sessionStorage;
// 关掉 rAF：避免 openModal 的自动聚焦抢走我们手动设置的焦点
globalThis.requestAnimationFrame = () => 0;
globalThis.cancelAnimationFrame = () => {};
window.requestAnimationFrame = () => 0;
window.cancelAnimationFrame = () => {};
window.scrollTo = () => {};

/* ---------------- 真实 fetch 代理（带 admin cookie） ---------------- */
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
  return {
    ok: res.ok,
    status: res.status,
    statusText: res.statusText,
    headers: { get: (h) => (String(h).toLowerCase() === 'set-cookie' ? setCookie : res.headers.get(String(h).toLowerCase())) },
    text: async () => text,
    json: async () => { try { return JSON.parse(text); } catch { return {}; } },
  };
}
globalThis.fetch = realFetch;
window.fetch = realFetch;

/* ---------------- 断言工具 ---------------- */
const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok: !!ok });
  console.log(`[${ok ? 'PASS' : 'FAIL'}] ${name}${!ok && detail ? ` -> ${detail}` : ''}`);
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function waitFor(fn, { timeout = 5000, interval = 60 } = {}) {
  const end = Date.now() + timeout;
  for (;;) {
    const v = fn();
    if (v) return v;
    if (Date.now() > end) return null;
    await sleep(interval);
  }
}
const toMin = (t) => { const [h, m] = String(t).split(':').map(Number); return h * 60 + m; };
const pad = (n) => String(n).padStart(2, '0');
const hhmm = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
const json = async (p) => (await realFetch(p)).json();

/* 组卷矩阵：题型行 → 难度列序号 */
const MATRIX = [
  { code: 'radio1', label: '判断题', cells: [['Y', '易'], ['Z', '中'], ['N', '难']] },
  { code: 'radio2', label: '单选题', cells: [['Y', '易'], ['Z', '中'], ['N', '难']] },
  { code: 'checkbox', label: '多选题', cells: [['Y', '易'], ['Z', '中'], ['N', '难']] },
  { code: 'text', label: '填空题', cells: [['Y', '易'], ['Z', '中'], ['N', '难']] },
];
const DIFF_FIELD = { Y: 'easy', Z: 'mid', N: 'hard' };

(async () => {
  try {
    /* ---------- 登录 ---------- */
    const lj = await (await realFetch('/api/admin/login', { method: 'POST', body: JSON.stringify({ username: 'admin', password: 'admin@2026' }) })).json();
    if (!SESSION_COOKIE && lj.code !== 0) { console.log('登录失败: ' + JSON.stringify(lj)); process.exit(2); }
    check('管理员登录成功', !!SESSION_COOKIE || lj.code === 0);

    /* ---------- 1) 纯函数：默认时间窗 ---------- */
    const { defaultExamWindow } = await import(pathToFileURL(path.join(assetsDir, 'js/core/format.js')).href + `?t=${Date.now()}`);
    const w0 = defaultExamWindow(new Date(2026, 8, 15, 10, 0, 0));
    check('默认时间窗：开始 = 当前 + 10 分钟，结束 = +1 小时',
      w0.start === '10:10' && w0.end === '11:10' && w0.date === '2026-09-15', JSON.stringify(w0));
    const w1 = defaultExamWindow(new Date(2026, 8, 15, 23, 55, 0));
    check('默认时间窗：跨天时日期顺延', w1.date === '2026-09-16' && w1.start === '00:05' && w1.end === '01:05', JSON.stringify(w1));

    /* ---------- 挂载考试管理视图 ---------- */
    const { ExamView } = await import(pathToFileURL(path.join(assetsDir, 'js/views/admin/exam.js')).href + `?t=${Date.now()}`);
    const root = await ExamView({ router: { navigate() {}, get current() { return {}; }, refresh() {} }, can: () => true });
    const holder = document.getElementById('app-content');
    holder.innerHTML = '';
    holder.append(root);

    const newBtn = await waitFor(() => [...document.querySelectorAll('button')].find((b) => (b.textContent || '').includes('新建考试')));
    check('列表渲染出「新建考试」按钮', !!newBtn);
    newBtn.click();

    const modal = await waitFor(() => document.querySelector('.modal'));
    check('新建考试弹窗已打开', !!modal);
    await waitFor(() => document.querySelector('.modal .checkbox-group'));
    await sleep(200);

    const fieldOf = (label) => [...modal.querySelectorAll('.field')]
      .find((x) => (x.querySelector('.field-label')?.textContent || '').includes(label))
      ?.querySelector('input, select, textarea') || null;
    const rowOf = (label) => [...modal.querySelectorAll('table tbody tr')]
      .find((r) => (r.children[0]?.textContent || '').trim() === label);
    const countCell = (label, idx) => rowOf(label)?.children[idx + 1]?.querySelector('input') || null;

    /* ---------- 2) 默认时间 ---------- */
    const dateCtl = fieldOf('考试日期');
    const startCtl = fieldOf('开始时间');
    const endCtl = fieldOf('结束时间');
    const subjCtl = fieldOf('科目');

    const now = new Date();
    const expStart = new Date(now.getTime() + 10 * 60000);
    const expEnd = new Date(expStart.getTime() + 60 * 60000);
    check('新建时开始时间 = 当前 + 10 分钟', Math.abs(toMin(startCtl.value) - toMin(hhmm(expStart))) <= 1,
      `表单=${startCtl.value} 期望≈${hhmm(expStart)}`);
    check('新建时结束时间 = 开始 + 1 小时',
      endCtl.value !== '' && ((toMin(endCtl.value) - toMin(startCtl.value) + 1440) % 1440) === 60,
      `表单=${endCtl.value} 开始=${startCtl.value} 期望≈${hhmm(expEnd)}`);
    check('新建时考试日期跟随开始时间',
      dateCtl.value === `${expStart.getFullYear()}-${pad(expStart.getMonth() + 1)}-${pad(expStart.getDate())}`,
      `表单=${dateCtl.value}`);

    /* ---------- 3) 参考班级多选 ---------- */
    const classList = (await json('/api/admin/classes?per_page=200'))?.data?.list || [];
    const group = modal.querySelector('.checkbox-group');
    const boxes = [...group.querySelectorAll('input[type="checkbox"]')];
    check('参考班级渲染为多选复选框组', boxes.length > 0 && boxes.length === classList.length,
      `复选框=${boxes.length} 班级=${classList.length}`);
    check('复选框值与班级 ID 一一对应', boxes.every((b) => classList.some((c) => String(c.id) === b.value)));
    check(classList.length === 1 ? '仅有一个班级时默认选中该班级' : '多个班级时不预选',
      classList.length === 1 ? boxes[0]?.checked === true : boxes.every((b) => !b.checked),
      `已选 ${boxes.filter((b) => b.checked).map((b) => b.value).join(',')}`);

    /* ---------- 4) 题目数量只允许数字 ---------- */
    const easyCtl = countCell('判断题', 0);
    check('找到「判断题-易」数量输入框', !!easyCtl);

    if (typeof window.InputEvent === 'function') {
      const ev = new window.InputEvent('beforeinput', { data: 'a', bubbles: true, cancelable: true });
      easyCtl.dispatchEvent(ev);
      check('非数字按键在 beforeinput 阶段被拦截', ev.defaultPrevented === true);
    }
    for (const [raw, expect] of [['12a3', '123'], ['-5.5e', '55'], ['007', '7'], ['abc', '']]) {
      easyCtl.value = raw;
      easyCtl.dispatchEvent(new window.Event('input', { bubbles: true }));
      check(`数量输入「${raw}」被过滤为「${expect}」`, easyCtl.value === expect, `实际=${easyCtl.value}`);
    }

    /* ---------- 5) 失焦校验（需要先选科目） ---------- */
    const subjects = (await json('/api/admin/subjects?per_page=200'))?.data?.list || [];
    const subjId = Number(subjects[0]?.id ?? 0);
    check('存在可选科目（用于库存校验）', subjId > 0);
    subjCtl.value = String(subjId);

    const toastCount = () => document.querySelectorAll('.toast-warning').length;
    const before = toastCount();
    easyCtl.value = '99999';
    easyCtl.dispatchEvent(new window.Event('input', { bubbles: true }));
    easyCtl.focus();
    easyCtl.blur();
    const toastEl = await waitFor(() => document.querySelector('.toast-warning'));
    check('题量不足时弹出 toast 提醒', !!toastEl && toastCount() > before, `toast ${before} -> ${toastCount()}`);
    check('toast 文案含「题量不足」与实际库存对比',
      !!toastEl && /题量不足/.test(toastEl.textContent) && /题库仅/.test(toastEl.textContent),
      toastEl ? toastEl.textContent.slice(0, 90) : '');
    check('题量不足时焦点保留在该输入框', document.activeElement === easyCtl,
      `activeElement=${document.activeElement?.tagName || 'null'}`);
    check('题量不足的输入框被标红（is-invalid）', easyCtl.classList.contains('is-invalid'));

    /* ---------- 6) 反例：库存充足 / 数量为 0 不提示 ---------- */
    // 逐个科目探测：把 12 个格子都置 1，未被报缺的格子说明题库有货
    const allOnes = {};
    for (const t of MATRIX) for (const [d] of t.cells) allOnes[`${t.code}_${DIFF_FIELD[d]}_sum`] = 1;
    let okPick = null;
    for (const subj of subjects.slice(0, 6)) {
      const res = await json(`/api/admin/exams/0/quiz-count?${new URLSearchParams({ subj_id: String(subj.id), ...allOnes })}`);
      const short = res?.data?.shortfall || [];
      outer:
      for (let ti = 0; ti < MATRIX.length; ti++) {
        for (let ci = 0; ci < MATRIX[ti].cells.length; ci++) {
          const [code, label] = MATRIX[ti].cells[ci];
          if (!short.some((s) => s.type === MATRIX[ti].code && s.diff === code)) {
            okPick = { subjId: String(subj.id), ctl: countCell(MATRIX[ti].label, ci), label: `${MATRIX[ti].label}-${label}` };
            break outer;
          }
        }
      }
      if (okPick) break;
    }
    if (okPick?.ctl) {
      subjCtl.value = okPick.subjId;   // 切到题库有货的科目
      const t3 = toastCount();
      okPick.ctl.value = '1';
      okPick.ctl.dispatchEvent(new window.Event('input', { bubbles: true }));
      okPick.ctl.blur();
      await sleep(900);
      check(`库存充足（${okPick.label}=1）不弹提示`, toastCount() === t3, `toast ${t3} -> ${toastCount()}`);
      check('库存充足时不标红', !okPick.ctl.classList.contains('is-invalid'));
    } else {
      console.log('[SKIP] 所有科目题库均为空，跳过「库存充足」反例');
    }

    const t4 = toastCount();
    easyCtl.value = '0';
    easyCtl.dispatchEvent(new window.Event('input', { bubbles: true }));
    easyCtl.blur();
    await sleep(800);
    check('数量为 0 时不触发库存校验', toastCount() === t4);
    check('重新输入后清除标红', !easyCtl.classList.contains('is-invalid'));

    /* ---------- 7) 教师端「新增考试」复用同一套行为 ---------- */
    [...document.querySelectorAll('.modal-backdrop')].forEach((n) => n.remove());
    const { openExamEditor } = await import(pathToFileURL(path.join(assetsDir, 'js/views/teacher/exam-editor.js')).href + `?t=${Date.now()}`);
    const cats = (await json('/api/admin/exam-categories?per_page=200'))?.data?.list || [];
    void openExamEditor({
      id: null,
      options: { subjects, categories: cats, teachers: [], grades: [], classes: classList },
      onSaved: () => {},
    });
    const tmodal = await waitFor(() => [...document.querySelectorAll('.modal')].pop());
    check('教师端「新增考试」弹窗已打开', !!tmodal);
    await waitFor(() => tmodal && tmodal.querySelector('.checkbox-group'));
    await sleep(150);

    const tfieldOf = (label) => [...tmodal.querySelectorAll('.field')]
      .find((x) => (x.querySelector('.field-label')?.textContent || '').includes(label))
      ?.querySelector('input, select, textarea') || null;
    const tStart = tfieldOf('开始时间');
    const tEnd = tfieldOf('结束时间');
    check('教师端开始时间 = 当前 + 10 分钟', Math.abs(toMin(tStart.value) - toMin(hhmm(expStart))) <= 1, `表单=${tStart.value}`);
    check('教师端结束时间 = 开始 + 1 小时',
      tEnd.value !== '' && ((toMin(tEnd.value) - toMin(tStart.value) + 1440) % 1440) === 60,
      `表单=${tEnd.value}`);

    const tboxes = [...tmodal.querySelectorAll('.checkbox-group input[type="checkbox"]')];
    check('教师端参考班级为多选复选框组', tboxes.length === classList.length, `复选框=${tboxes.length} 班级=${classList.length}`);
    check(classList.length === 1 ? '教师端仅有一个班级时默认选中' : '教师端多个班级时不预选',
      classList.length === 1 ? tboxes[0]?.checked === true : tboxes.every((b) => !b.checked));

    const tRow = [...tmodal.querySelectorAll('table tbody tr')].find((r) => (r.children[0]?.textContent || '').trim() === '判断题');
    const tCell = tRow?.children[1]?.querySelector('input');
    check('教师端数量输入框为数字专用（text + inputmode=numeric）',
      tCell?.type === 'text' && tCell?.getAttribute('inputmode') === 'numeric', `type=${tCell?.type} inputmode=${tCell?.getAttribute('inputmode')}`);
    tCell.value = '8x9';
    tCell.dispatchEvent(new window.Event('input', { bubbles: true }));
    check('教师端数量输入「8x9」被过滤为「89」', tCell.value === '89', `实际=${tCell.value}`);

    const pass = results.filter((r) => r.ok).length;
    const fail = results.length - pass;
    console.log(`\n结果: ${pass} PASS / ${fail} FAIL （共 ${results.length} 项）`);
    process.exit(fail ? 1 : 0);
  } catch (e) {
    console.error('FATAL', e);
    process.exit(3);
  }
})();
