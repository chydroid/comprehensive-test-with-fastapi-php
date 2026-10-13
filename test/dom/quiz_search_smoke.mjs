/**
 * 题目搜索视图冒烟（纯前端，打桩 http，不连后端）
 *
 * 覆盖新交互（去掉结果分页）：
 *  - 选科目 + 输入≥3字 → 一次性取该关键词完整命中集（all=1），渲染全部匹配；
 *  - 继续输入（追加，已加载关键词仍为子串）→ 只在已加载结果上本地精化，不再发请求；
 *  - 关键词变宽/改变 → 重新取；
 *  - 题型筛选纯本地；<3字/未选科目给提示；不再有「加载更多」分页。
 *
 * 用法：node test/dom/quiz_search_smoke.mjs <publicDir>
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
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const text = () => document.body.textContent || '';
const optLists = () => document.querySelectorAll('.quiz-search .option-list').length;
const correctCount = () => document.querySelectorAll('.quiz-search .option-item.is-correct').length;
const opt = (t) => [...document.querySelectorAll('.quiz-search .option-item')]
  .find((n) => (n.querySelector('.option-text')?.textContent || '').trim() === t);
const sel = () => document.querySelector('.quiz-search select[name=subj_id]');
const kwInput = () => document.querySelector('.quiz-search input[type=search]');
async function typeKeyword(v) {
  kwInput().value = v;
  kwInput().dispatchEvent(new window.Event('input', { bubbles: true }));
}
// 轮询直到 cond 满足或超时
async function until(cond, max = 60) {
  for (let i = 0; i < max && !cond(); i++) await wait(50);
}

/* ------------------------------ 假后端 ------------------------------ */
const SUBJECTS = [
  { id: 1, subj_name: '国际海上避碰规则' },
  { id: 203, subj_name: '法治政府创建考试题库' },
];

// 三题题干都含「法治思想」；仅 Q1 含连续的「法治思想核心」；仅 Q2 含「实践要求」
const QUESTIONS = [
  {
    id: 1, subj_name: '法治政府创建考试题库', quiz_class: 'radio2', quiz_diff_label: '中', quiz_kp: '第一部分',
    quiz_title: '习近平法治思想核心要义包括下列哪一项（）。', quiz_key: 'D',
    quiz_option_list: [
      { key: 'A', text: '坚持党对全面依法治国的领导', correct: false },
      { key: 'B', text: '坚持以人民为中心', correct: false },
      { key: 'C', text: '坚持中国特色社会主义法治道路', correct: false },
      { key: 'D', text: '坚持实事求是', correct: true },
    ],
  },
  {
    id: 2, subj_name: '法治政府创建考试题库', quiz_class: 'checkbox', quiz_diff_label: '中', quiz_kp: '第一部分',
    quiz_title: '习近平法治思想的实践要求包括：（）。', quiz_key: 'AB',
    quiz_option_list: [
      { key: 'A', text: '依法治国、依法执政、依法行政共同推进', correct: true },
      { key: 'B', text: '法治国家、法治政府、法治社会一体建设', correct: true },
      { key: 'C', text: '坚持以经济建设为中心', correct: false },
      { key: 'D', text: '坚持GDP至上', correct: false },
    ],
  },
  {
    id: 3, subj_name: '法治政府创建考试题库', quiz_class: 'radio1', quiz_diff_label: '中', quiz_kp: '第一部分',
    quiz_title: '习近平法治思想是推进全面依法治国的根本指导思想。（）', quiz_key: 'A',
    quiz_option_list: [
      { key: 'A', text: '对', correct: true },
      { key: 'B', text: '错', correct: false },
    ],
  },
];

const jsonOk = (data) => ({
  ok: true, status: 200,
  headers: { get: (k) => (String(k).toLowerCase() === 'content-type' ? 'application/json' : '') },
  json: async () => ({ code: 0, message: 'ok', data }),
});

let searchCalls = 0;
let lastSearch = null;
globalThis.fetch = window.fetch = async (url) => {
  const u = new URL(String(url), 'http://127.0.0.1:8099');
  const p = u.pathname + u.search;
  if (p.includes('/public/subjects')) return jsonOk(SUBJECTS);
  if (p.includes('/student/quiz-search')) {
    const sp = u.searchParams;
    const subj = Number(sp.get('subj_id') || 0);
    const kw = sp.get('keyword') || '';
    if (subj <= 0) return jsonOk({ list: [], total: 0, hint: '请先选择科目' });
    if (kw.length < 3) return jsonOk({ list: [], total: 0, hint: '请输入至少 3 个字的题干关键词' });
    searchCalls++;
    lastSearch = { subj, kw, all: sp.get('all') || '' };
    // 简化：始终返回全部 3 题，由前端本地过滤（验证"取完整集 + 本地精化"）
    return jsonOk({ list: QUESTIONS, total: QUESTIONS.length, page: 1, per_page: QUESTIONS.length, total_pages: 1, keyword: kw, all: sp.get('all') === '1' });
  }
  return jsonOk({});
};

/* ------------------------------ 执行 ------------------------------ */
const mod = await import(pathToFileURL(path.join(assetsDir, 'js/views/student/search.js')).href);
document.body.append(mod.QuizSearchView());

// 等科目异步加载完成（出现 value=203 选项）
for (let i = 0; i < 60 && ![...(sel()?.options || [])].some((o) => o.value === '203'); i++) await wait(50);

ok('科目下拉已渲染（占位 + 2 科目）', !!sel() && sel().options.length === 3, sel() ? String(sel().options.length) : 'no select');

// 1) 选科目但未输关键词 → 提示，且不发起检索
sel().value = '203';
sel().dispatchEvent(new window.Event('change', { bubbles: true }));
await wait(120);
ok('未输关键词时提示至少 3 字', text().includes('请输入至少 3 个字的题干关键词'), text().slice(0, 160));
ok('未输关键词不发起检索', searchCalls === 0, String(searchCalls));

// 2) 输入 4 字关键词 → 取完整集(all=1)，渲染全部 3 题
await typeKeyword('法治思想');
await until(() => text().includes('核心要义'));
ok('带上了科目+关键词+all=1', lastSearch && lastSearch.subj === 203 && lastSearch.kw === '法治思想' && lastSearch.all === '1', JSON.stringify(lastSearch));
ok('渲染全部 3 题（无分页）', optLists() === 3, String(optLists()));
ok('渲染总数文案', text().includes('的题目：3 题'), text().slice(0, 200));
ok('正确选项共 4 个绿色(is-correct)', correctCount() === 4, String(correctCount()));
ok('单选正确项「坚持实事求是」绿', !!opt('坚持实事求是')?.classList.contains('is-correct'));
ok('多选正确项「一体建设」绿', !!opt('法治国家、法治政府、法治社会一体建设')?.classList.contains('is-correct'));
ok('判断题正确项「对」绿', !!opt('对')?.classList.contains('is-correct'));
ok('卡片展示正确答案', text().includes('正确答案：D') && text().includes('正确答案：AB'));
ok('没有「加载更多」分页按钮', ![...document.querySelectorAll('button')].some((b) => (b.textContent || '').includes('加载更多')));

// 3) 题型筛选（纯本地）：点多选 → 仅 Q2；点全部 → 回 3 题
const segBtn = (label) => [...document.querySelectorAll('.quiz-search .segmented button')].find((b) => (b.textContent || '').includes(label));
segBtn('多选')?.click();
await wait(60);
ok('题型=多选 本地筛选出 1 题', optLists() === 1, String(optLists()));
ok('题型=多选 未发新请求', searchCalls === 1, String(searchCalls));
segBtn('全部')?.click();
await wait(60);
ok('题型=全部 恢复 3 题', optLists() === 3, String(optLists()));

// 4) 继续输入（追加）→ 本地精化，不发新请求：仅剩 Q1
await typeKeyword('法治思想核心');
await until(() => optLists() === 1);
ok('追加输入后仅剩 1 题(核心要义)', optLists() === 1 && text().includes('核心要义') && !text().includes('实践要求'), String(optLists()));
ok('追加输入未发新请求(本地精化)', searchCalls === 1, String(searchCalls));
ok('精化后正确项 1 个(D)', correctCount() === 1, String(correctCount()));

// 5) 关键词变宽/改变 → 重新取：仅剩 Q2
await typeKeyword('实践要求');
await until(() => text().includes('实践要求') && optLists() === 1);
ok('改变关键词后重新取(第 2 次)', searchCalls === 2, String(searchCalls));
ok('改变关键词后仅剩 1 题(实践要求)', optLists() === 1, String(optLists()));
ok('改变关键词后正确项 2 个(A,B)', correctCount() === 2, String(correctCount()));

// 6) 关键词不足 3 字 → 提示
await typeKeyword('法');
await until(() => text().includes('请输入至少 3 个字'));
ok('关键词不足 3 字时提示', text().includes('请输入至少 3 个字的题干关键词'), text().slice(0, 160));

// 7) 取消选科目 → 提示先选科目
sel().value = '';
sel().dispatchEvent(new window.Event('change', { bubbles: true }));
await wait(120);
ok('未选科目时提示先选科目', text().includes('请先选择一个科目'), text().slice(0, 160));

console.log(`\n结果：${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
