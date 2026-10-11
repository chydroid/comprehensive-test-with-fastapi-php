// Mobile exam-runner smoke: verify answer-sheet drawer toggle / scrim / bottom bar (JS behavior).
// Usage: node mobile_exam_smoke.mjs [publicDir]
import { JSDOM } from 'jsdom';
import path from 'path';
import { pathToFileURL } from 'url';

const publicDir = process.argv[2] || path.resolve(process.cwd(), '../../public');
const assetsDir = path.join(publicDir, 'assets');

const dom = new JSDOM('<!DOCTYPE html><html><body><div id="app"></div></body></html>', {
  url: 'http://127.0.0.1:8099/exam',
  pretendToBeVisual: true,
});
const { window } = dom;
for (const k of ['window', 'document', 'navigator', 'location', 'history', 'localStorage', 'sessionStorage', 'CSS', 'HTMLElement', 'Element', 'Node', 'NodeList', 'Event', 'CustomEvent', 'MouseEvent', 'KeyboardEvent', 'InputEvent', 'FormData', 'URLSearchParams', 'URL', 'Blob', 'File', 'AbortController', 'AbortSignal', 'DOMException', 'getComputedStyle', 'MutationObserver', 'IntersectionObserver', 'ResizeObserver', 'Text', 'Comment', 'DocumentFragment', 'DOMParser', 'XMLHttpRequest', 'Image', 'SVGElement', 'HTMLInputElement', 'HTMLSelectElement', 'HTMLTextAreaElement', 'HTMLFormElement']) {
  try { const v = window[k]; if (v !== undefined) globalThis[k] = (typeof v === 'function' && /^(getComputedStyle|matchMedia)$/.test(k)) ? v.bind(window) : v; } catch {}
}
globalThis.self = window;
globalThis.localStorage = window.localStorage;
globalThis.sessionStorage = window.sessionStorage;
globalThis.matchMedia = () => ({ matches: false, media: '', addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
window.matchMedia = globalThis.matchMedia;
globalThis.requestAnimationFrame = () => 0;
globalThis.cancelAnimationFrame = () => {};
window.requestAnimationFrame = () => 0;
window.cancelAnimationFrame = () => {};
window.scrollTo = () => {};

const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok: !!ok });
  console.log(`[${ok ? 'PASS' : 'FAIL'}] ${name}${!ok && detail ? ` -> ${detail}` : ''}`);
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function waitFor(fn, timeout = 5000) {
  const end = Date.now() + timeout;
  for (;;) { const v = fn(); if (v) return v; if (Date.now() > end) return null; await sleep(50); }
}

(async () => {
  try {
    const { createExamRunner } = await import(pathToFileURL(path.join(assetsDir, 'js/views/exam-runner.js')).href + `?t=${Date.now()}`);

    const makePaper = (paperId) => ({
      paper_id: paperId,
      question: { quiz_title: `Q${paperId}`, quiz_class: 'radio2', quiz_option_list: [{ key: 'A', text: 'OptA' }, { key: 'B', text: 'OptB' }] },
      navigation: {
        total: 3, done: 1,
        groups: [{ type: 'radio2', type_label: 'Single', items: [{ paper_id: 1, answered: true }, { paper_id: 2, answered: false }, { paper_id: 3, answered: false }] }],
      },
    });

    const cfg = {
      mode: 'exam',
      title: 'Exam',
      exitUrl: '/student',
      // real API returns raw data (http.js already unwrapped); withLoading wraps it once.
      loadPaper: (paperId) => Promise.resolve(makePaper(paperId)),
      saveAnswer: () => Promise.resolve({ navigation: null }),
      submit: () => Promise.resolve({ score: 80 }),
      loadReview: () => Promise.resolve({ score: 80, total_score: 100, summary: [], papers: [] }),
    };

    const runner = createExamRunner(cfg);
    const host = document.createElement('div');
    host.append(runner.node);
    document.getElementById('app').append(host);

    await runner.start();
    await waitFor(() => runner.node.querySelector('.option-item'));

    const node = runner.node;
    check('root has .exam-layout', node.classList.contains('exam-layout'));
    check('topbar .exam-topbar exists', !!node.querySelector('.exam-topbar'));
    check('progress+timer nodes exist', !!node.querySelector('.exam-progress') && !!node.querySelector('.exam-timer'));
    const optCount = node.querySelectorAll('.option-item').length;
    check('options rendered (2)', optCount === 2, `got ${optCount}`);
    check('answer-sheet exists', !!node.querySelector('.answer-sheet'));
    check('bottom bar exists', !!node.querySelector('.exam-bottombar'));

    const sheetBtn = node.querySelector('.exam-bottombar .sheet-toggle');
    check('sheet toggle button exists', !!sheetBtn);
    const scrim = node.querySelector('.answer-sheet-scrim');
    check('sheet scrim exists', !!scrim);
    check('initially collapsed', !node.classList.contains('is-sheet-open'));

    if (sheetBtn) sheetBtn.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    check('open after toggle', node.classList.contains('is-sheet-open'));

    if (scrim) scrim.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    check('collapsed after scrim click', !node.classList.contains('is-sheet-open'));

    if (sheetBtn) sheetBtn.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    const cell = node.querySelector('.sheet-cell');
    check('sheet cell clickable', !!cell, `cells=${node.querySelectorAll('.sheet-cell').length}`);
    if (cell) cell.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await sleep(80);
    check('collapsed after cell tap', !node.classList.contains('is-sheet-open'));

    runner.dispose && runner.dispose();
  } catch (e) {
    check('no exception', false, e && (e.stack || e.message));
  }

  const failed = results.filter((r) => !r.ok);
  console.log(`\nRESULT: ${results.length - failed.length}/${results.length} PASS`);
  process.exit(failed.length ? 1 : 0);
})();
