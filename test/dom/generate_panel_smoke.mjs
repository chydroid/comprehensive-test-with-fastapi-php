/**
 * 出题面板冒烟（纯前端，不连后端）
 *
 * 为什么单独写这一份：出题面板是全新组件，「分批推进 + 逐人点亮」全在前端，
 * PHP 单测只能锁住接口字段（plan / details），锁不住
 * 「名单有没有渲染出来、点开始后每个人有没有依次变成已出卷」。
 *
 * 用法：
 *   node test/dom/generate_panel_smoke.mjs <publicDir>
 */

import { JSDOM } from 'jsdom';
import path from 'path';
import { pathToFileURL } from 'url';

const publicDir = process.argv[2] || path.resolve(process.cwd(), 'public');
const assetsDir = path.join(publicDir, 'assets');

const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
  url: 'http://127.0.0.1:8099/',
  pretendToBeVisual: true,
});
const { window } = dom;
for (const k of ['window', 'document', 'navigator', 'location', 'history', 'localStorage', 'sessionStorage',
  'CSS', 'HTMLElement', 'Element', 'Node', 'NodeList', 'Event', 'CustomEvent', 'MouseEvent', 'KeyboardEvent',
  'InputEvent', 'FormData', 'URLSearchParams', 'URL', 'Blob', 'File', 'AbortController', 'AbortSignal',
  'DOMException', 'getComputedStyle', 'matchMedia', 'MutationObserver', 'IntersectionObserver', 'ResizeObserver',
  'Text', 'Comment', 'DocumentFragment', 'DOMParser', 'XMLHttpRequest', 'Image', 'SVGElement',
  'HTMLInputElement', 'HTMLSelectElement', 'HTMLTextAreaElement', 'HTMLFormElement']) {
  try {
    const v = window[k];
    if (v !== undefined) {
      globalThis[k] = (typeof v === 'function' && /^(getComputedStyle|matchMedia)$/.test(k)) ? v.bind(window) : v;
    }
  } catch { /* 忽略 jsdom 缺失的全局 */ }
}
globalThis.self = window;
globalThis.requestAnimationFrame = () => 0;
globalThis.cancelAnimationFrame = () => {};
window.requestAnimationFrame = () => 0;
window.cancelAnimationFrame = () => {};
window.scrollTo = () => {};
window.alert = () => {};

let pass = 0;
let fail = 0;
function ok(name, cond, info = '') {
  if (cond) { pass++; console.log(`  PASS  ${name}`); }
  else { fail++; console.log(`  FAIL  ${name}${info ? `  -> ${info}` : ''}`); }
}

/* ------------------------------ 假后端 ------------------------------ */
const STUDENTS = Array.from({ length: 7 }, (_, i) => ({
  stu_id: String(9000001 + i),
  stu_name: `考生${i + 1}`,
  questions: 0,
  has_paper: false,
}));

const batches = [];
let doneCalled = 0;

// 契约与真实 http 层一致：成功直接返回 data（信封已解包），失败 throw
const api = {
  generatePlan: async () => ({
    exam_id: 1, exam_name: '冒烟考试', exam_status: 'exam',
    exam_start: '2030-01-01 09:00:00',
    total: STUDENTS.length, pending: STUDENTS.length,
    auto_gen_in: 8,
    students: STUDENTS,
  }),
  generatePapers: async (id, body) => {
    const ids = (body && body.stu_ids) || [];
    batches.push(ids);
    return {
      pending: ids.length,
      generated: ids.length,
      skipped: 0,
      warnings: [],
      details: ids.map((s) => ({ stu_id: String(s), stu_name: '考生', generated: true, questions: 4 })),
    };
  },
};

/* ------------------------------ 执行 ------------------------------ */
const mod = await import(pathToFileURL(path.join(assetsDir, 'js/views/generate-panel.js')).href);

await mod.openGeneratePanel({
  examId: 1, examName: '冒烟考试', api, onDone: () => { doneCalled++; },
});

const text = () => document.body.textContent || '';

ok('渲染出已入场人数', text().includes('已入场 7 人'), text().slice(0, 200));
ok('渲染出待出题人数', text().includes('待出题 7 人'), text().slice(0, 200));
ok('提示自动出题倒计时', text().includes('距自动出题还有约 8 秒'), text().slice(0, 300));
ok('每个考生一行（准考证号）', STUDENTS.every((s) => text().includes(s.stu_id)), text().slice(0, 300));
ok('初始状态为排队中', (text().match(/排队中/g) || []).length === 7, String((text().match(/排队中/g) || []).length));

const btns = [...document.querySelectorAll('button')];
const genBtn = btns.find((b) => (b.textContent || '').includes('开始出题'));
ok('底部有「开始出题」按钮', !!genBtn, btns.map((b) => b.textContent).join('|'));

// 点击后等待分批推进完成（按钮文案变为「重新出题」）
genBtn?.click();
for (let i = 0; i < 60 && !(text().includes('重新出题')); i++) {
  await new Promise((r) => setTimeout(r, 50));
}

ok('出题分两批推进（7 人 / 每批 5）', batches.length === 2, JSON.stringify(batches));
ok('第一批 5 人', (batches[0] || []).length === 5, JSON.stringify(batches[0]));
ok('第二批 2 人', (batches[1] || []).length === 2, JSON.stringify(batches[1]));
ok('每人都被点名为已出卷', (text().match(/已出卷 4 题/g) || []).length === 7, String((text().match(/已出卷 4 题/g) || []).length));
ok('汇总显示新生成 7 份', text().includes('新生成 7 份'), text().slice(-300));
ok('完成后回调刷新列表', doneCalled === 1, String(doneCalled));

/* 全部已有卷时不应再发起任何出题请求 */
document.querySelectorAll('.modal-backdrop').forEach((n) => n.remove());
batches.length = 0;
await mod.openGeneratePanel({
  examId: 1, examName: '冒烟考试2',
  api: {
    generatePlan: async () => ({
      exam_id: 1, exam_status: 'paper', total: 2, pending: 0, auto_gen_in: null,
      students: [
        { stu_id: '9000001', stu_name: '甲', questions: 4, has_paper: true },
        { stu_id: '9000002', stu_name: '乙', questions: 4, has_paper: true },
      ],
    }),
    generatePapers: async (id, body) => { batches.push(body.stu_ids); return { ok: true, result: {} }; },
  },
});
ok('全部已有卷时提示无需重复生成', text().includes('均已出卷'), text().slice(0, 300));
ok('全部已有卷时不发起出题请求', batches.length === 0, JSON.stringify(batches));

console.log(`\n结果：${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
