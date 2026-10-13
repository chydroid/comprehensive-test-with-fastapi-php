/**
 * 题目搜索视图冒烟（纯前端，打桩 http，不连后端）
 *
 * 验证考生端「题目搜索」：选科目 + 输入题干关键词(≥3字)后渲染匹配题目，
 * 选项里正确项带 is-correct（绿色）；<3 字 / 未选科目给出对应提示；有下一页时出现「加载更多」。
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

/* ------------------------------ 假后端 ------------------------------ */
const SUBJECTS = [
  { id: 1, subj_name: '国际海上避碰规则' },
  { id: 203, subj_name: '法治政府创建考试题库' },
];

// 覆盖三种题型；correct 标记由后端计算，这里直接给最终值
const QUESTIONS = [
  {
    id: 1, subj_name: '法治政府创建考试题库', quiz_class: 'radio2', quiz_diff_label: '中', quiz_kp: '第一部分',
    quiz_title: '下列哪一项不属于习近平法治思想的核心要义（）。', quiz_key: 'D',
    quiz_option_list: [
      { key: 'A', text: '坚持党对全面依法治国的领导', correct: false },
      { key: 'B', text: '坚持以人民为中心', correct: false },
      { key: 'C', text: '坚持中国特色社会主义法治道路', correct: false },
      { key: 'D', text: '坚持实事求是', correct: true },
    ],
  },
  {
    id: 2, subj_name: '法治政府创建考试题库', quiz_class: 'checkbox', quiz_diff_label: '中', quiz_kp: '第七部分',
    quiz_title: '行政机关按照招标、拍卖程序确定（）后，应当作出准予行政许可的决定。', quiz_key: 'BD',
    quiz_option_list: [
      { key: 'A', text: '标的额', correct: false },
      { key: 'B', text: '中标人', correct: true },
      { key: 'C', text: '申请人', correct: false },
      { key: 'D', text: '买受人', correct: true },
    ],
  },
  {
    id: 3, subj_name: '法治政府创建考试题库', quiz_class: 'radio1', quiz_diff_label: '中', quiz_kp: '第一部分',
    quiz_title: '中国走向世界，必须善于运用法治，构建人类命运共同体。（）', quiz_key: 'A',
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

let lastSearch = null;
globalThis.fetch = window.fetch = async (url) => {
  const u = new URL(String(url), 'http://127.0.0.1:8099');
  const p = u.pathname + u.search;
  if (p.includes('/public/subjects')) return jsonOk(SUBJECTS);
  if (p.includes('/student/quiz-search')) {
    const sp = u.searchParams;
    const subj = Number(sp.get('subj_id') || 0);
    const kw = sp.get('keyword') || '';
    lastSearch = { subj, kw, quiz_class: sp.get('quiz_class') || '' };
    if (subj <= 0) return jsonOk({ list: [], total: 0, page: 1, per_page: 0, total_pages: 0, keyword: kw, hint: '请先选择科目' });
    if (kw.length < 3) return jsonOk({ list: [], total: 0, page: 1, per_page: 0, total_pages: 0, keyword: kw, hint: '请输入至少 3 个字的题干关键词' });
    const per = Number(sp.get('per_page') || 20);
    const total = 25;
    return jsonOk({
      list: QUESTIONS.slice(0, Math.min(per, QUESTIONS.length)),
      total, page: Number(sp.get('page') || 1), per_page: per,
      total_pages: Math.ceil(total / per), keyword: kw,
    });
  }
  return jsonOk({});
};

/* ------------------------------ 执行 ------------------------------ */
const mod = await import(pathToFileURL(path.join(assetsDir, 'js/views/student/search.js')).href);
const root = mod.QuizSearchView();
document.body.append(root);

const text = () => document.body.textContent || '';
const sel = () => document.querySelector('.quiz-search select[name=subj_id]');
const kwInput = () => document.querySelector('.quiz-search input[type=search]');

// 等科目异步加载完成（init 会二次 renderFilters 重建 select）：等到出现 value=203 的选项
for (let i = 0; i < 60 && ![...(sel()?.options || [])].some((o) => o.value === '203'); i++) await wait(50);

ok('科目下拉已渲染（占位 + 2 科目）', !!sel() && sel().options.length === 3, sel() ? String(sel().options.length) : 'no select');

// 1) 选科目（关键词为空 → 提示输关键词）
sel().value = '203';
sel().dispatchEvent(new window.Event('change', { bubbles: true }));
await wait(120);
ok('未输关键词时提示至少 3 字', text().includes('请输入至少 3 个字的题干关键词'), text().slice(0, 160));

// 2) 输入 4 字关键词 → 触发检索
kwInput().value = '法治思想';
kwInput().dispatchEvent(new window.Event('input', { bubbles: true }));
for (let i = 0; i < 40 && !text().includes('核心要义'); i++) await wait(50);

ok('带上了科目与关键词请求', lastSearch && lastSearch.subj === 203 && lastSearch.kw === '法治思想', JSON.stringify(lastSearch));
ok('渲染匹配总数文案', text().includes('共 25 题'), text().slice(0, 200));
ok('渲染单选题题干', text().includes('核心要义'));
ok('渲染多选题题干', text().includes('招标、拍卖'));
ok('渲染判断题题干', text().includes('人类命运共同体'));

const correctItems = [...document.querySelectorAll('.quiz-search .option-item.is-correct')];
ok('正确选项共 4 个带 is-correct', correctItems.length === 4, String(correctItems.length));

// 按选项文本精确匹配（避免「对」误中「党对…」等子串）
const opt = (t) => [...document.querySelectorAll('.quiz-search .option-item')]
  .find((n) => (n.querySelector('.option-text')?.textContent || '').trim() === t);
ok('单选正确项「坚持实事求是」为绿色(is-correct)', !!opt('坚持实事求是')?.classList.contains('is-correct'));
ok('单选干扰项「坚持以人民为中心」非绿色', !!opt('坚持以人民为中心') && !opt('坚持以人民为中心').classList.contains('is-correct'));
ok('多选正确项「买受人」为绿色', !!opt('买受人')?.classList.contains('is-correct'));
ok('多选干扰项「标的额」非绿色', !!opt('标的额') && !opt('标的额').classList.contains('is-correct'));
ok('判断题正确项「对」为绿色', !!opt('对')?.classList.contains('is-correct'));
ok('判断题干扰项「错」非绿色', !!opt('错') && !opt('错').classList.contains('is-correct'));
ok('卡片展示正确答案', text().includes('正确答案：D') && text().includes('正确答案：BD'));

// 3) 有下一页 → 加载更多
ok('显示「加载更多」', [...document.querySelectorAll('button')].some((b) => (b.textContent || '').includes('加载更多')));

// 4) 关键词改为 2 字 → 提示
kwInput().value = '法治';
kwInput().dispatchEvent(new window.Event('input', { bubbles: true }));
for (let i = 0; i < 40 && text().includes('核心要义'); i++) await wait(50);
ok('关键词不足 3 字时提示', text().includes('请输入至少 3 个字的题干关键词'), text().slice(0, 160));

// 5) 取消选科目 → 提示先选科目
sel().value = '';
sel().dispatchEvent(new window.Event('change', { bubbles: true }));
await wait(120);
ok('未选科目时提示先选科目', text().includes('请先选择一个科目'), text().slice(0, 160));

console.log(`\n结果：${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
