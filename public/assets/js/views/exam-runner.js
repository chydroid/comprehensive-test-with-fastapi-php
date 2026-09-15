/**
 * 答题引擎（共享视图）—— 正式考试 / 模拟考试 / 练习均复用。
 *
 * 设计要点：
 * - 后端从不下发答案（P0-4），故本视图只负责采集作答、本地暂存与提交；
 * - 作答状态由 navigation.groups 驱动（题型分组 + 已答标记），不用本地猜测；
 * - 正式考试带服务端时间对齐与超时自动交卷；模拟考试带倒计时但可自行交卷。
 */

import { el, clear, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { notify, button, openModal } from '../ui/components.js';
import { withLoading } from '../core/bootstrap.js';
import { fmtScore, fmtDuration } from '../core/format.js';

/** 题型 → 展示元信息 */
export const QTYPE = {
  radio1:   { label: '判断题', kind: 'single', keys: ['A', 'B'] },
  radio2:   { label: '单选题', kind: 'single' },
  checkbox: { label: '多选题', kind: 'multi' },
  text:     { label: '填空题', kind: 'fill' },
  longtext: { label: '问答题', kind: 'text' },
};

/**
 * 构建答题界面。
 *
 * @param {object} cfg
 * @param {string} cfg.mode            'exam' | 'mock' | 'exercise'
 * @param {string} cfg.title           标题（考试名 / 模拟考试）
 * @param {() => Promise} cfg.loadPaper   (paperId) => 后端取卷响应 data
 * @param {(paperId, answer) => Promise} cfg.saveAnswer
 * @param {() => Promise} cfg.submit
 * @param {() => Promise} cfg.loadReview  (可选) 结果/解析数据
 * @param {string} cfg.examEnd          ISO 时间串（可选，用于超时）
 * @param {string} cfg.exitUrl         退出后跳转地址
 * @param {() => Promise} [cfg.onExit] 退出前的清理钩子（如调用登出接口）
 * @returns {{node, dispose}}
 */
export function createExamRunner(cfg) {
  const state = {
    paperId: 1,
    nav: { total: 0, done: 0, groups: [] },
    question: null,
    answers: new Map(),
    dirty: false,
    finished: false,
    remaining: null,
    timer: null,
    submitting: false,
  };

  const root = el('div.exam-layout');

  /* ---------- 顶部状态条 ---------- */
  const titleNode = el('div.exam-title', {}, [
    icon(cfg.mode === 'exam' ? 'shield-check' : 'edit-3', { size: 18 }),
    el('span', { text: cfg.title || '答题中' }),
  ]);
  const progressNode = el('div.exam-progress');
  const timerNode = el('div.exam-timer');
  const topbar = el('div.exam-topbar', {}, [
    titleNode,
    el('div.exam-topbar-right', {}, [progressNode, timerNode]),
  ]);

  /* ---------- 题干区 ---------- */
  const stemNode = el('div.question-stem');
  const optionNode = el('div.option-list');
  const questionCard = el('div.question-card', {}, [
    el('div.question-head', {}, [el('span.question-no'), el('span.question-type')]),
    stemNode,
    optionNode,
  ]);

  /* ---------- 底部操作 ---------- */
  const prevBtn = button('上一题', { variant: 'secondary', iconName: 'chevron-left', onClick: () => go(state.paperId - 1) });
  const nextBtn = button('下一题', { variant: 'primary', iconName: 'chevron-right', onClick: () => go(state.paperId + 1) });
  const submitBtn = button('交卷', { variant: 'danger', iconName: 'check-circle', onClick: () => confirmSubmit() });
  const bottomBar = el('div.exam-bottombar', {}, [
    el('div.exam-bottombar-left', {}, [prevBtn]),
    el('div.exam-bottombar-right', {}, [nextBtn, submitBtn]),
  ]);

  /* ---------- 答题卡 ---------- */
  const sheetGrid = el('div.sheet-grid');
  const sheetLegend = el('div.sheet-legend', {}, [
    legendItem('is-current', '当前'),
    legendItem('is-answered', '已答'),
    legendItem('', '未答'),
  ]);
  const sheetCard = el('div.answer-sheet.card', {}, [
    el('div.answer-sheet-head', {}, [
      icon('grid', { size: 16 }),
      el('span', { text: '答题卡' }),
      el('span.sheet-count'),
    ]),
    sheetGrid,
    sheetLegend,
  ]);

  function legendItem(cls, text) {
    return el('span.sheet-legend-item', {}, [
      el('i', { class: `sheet-cell ${cls}`.trim() }),
      el('span', { text }),
    ]);
  }

  const main = el('div.stack', {}, [questionCard, bottomBar]);
  root.append(topbar, el('div.exam-body', {}, [main, sheetCard]));

  /* ---------- 逻辑 ---------- */

  function setAnswered(paperId, answered) {
    for (const g of state.nav.groups || []) {
      for (const it of g.items) {
        if (it.paper_id === paperId) it.answered = answered;
      }
    }
  }

  function localAnswer(paperId) {
    return state.answers.has(paperId) ? state.answers.get(paperId) : null;
  }

  function renderProgress() {
    clear(progressNode);
    progressNode.append(el('span', { text: `已答 ${state.nav.done} / ${state.nav.total}` }));
  }

  function renderSheet() {
    clear(sheetGrid);
    const countNode = sheetCard.querySelector('.sheet-count');
    if (countNode) countNode.textContent = `${state.nav.done}/${state.nav.total}`;

    for (const g of state.nav.groups || []) {
      sheetGrid.append(el('div.sheet-group-label', { text: g.type_label || g.type }));
      const row = el('div.sheet-row');
      for (const it of g.items) {
        const cls = [
          'sheet-cell',
          it.paper_id === state.paperId ? 'is-current' : '',
          it.answered ? 'is-answered' : '',
        ].filter(Boolean).join(' ');
        row.append(el('button', {
          class: cls,
          text: String(it.paper_id),
          title: `${g.type_label} 第 ${it.paper_id} 题`,
          on: { click: () => { if (state.dirty) saveCurrent().then(() => go(it.paper_id)); else go(it.paper_id); } },
        }));
      }
      sheetGrid.append(row);
    }
  }

  // 当前题干配图节点。renderQuestion 在每次点选后都会被重绘（单选换项 / 多选切换），
  // 而配图是插在 stemNode 之后的常驻节点、此前从不清理 —— 于是点一次选项就多叠一张图。
  // 每次重绘前先移除上一张，保证题干下始终只有一张配图。
  let picNode = null;

  function renderQuestion() {
    const q = state.question;
    if (!q) return;
    stemNode.textContent = q.quiz_title || '';
    clear(optionNode);

    const type = q.quiz_class;
    const meta = QTYPE[type] || { kind: 'single' };
    const opts = q.quiz_option_list || [];
    const cur = localAnswer(state.paperId);
    const isFill = meta.kind === 'fill' || meta.kind === 'text';

    // 配图（先清理上一张，避免重复叠加）
    if (picNode) { picNode.remove(); picNode = null; }
    const pic = q.quiz_pic_name
      ? el('img.question-pic', { src: `/uploads/${q.quiz_pic_name}`, alt: '题目配图', loading: 'lazy' })
      : null;
    if (pic) {
      picNode = pic;
      pic.addEventListener('error', () => { pic.remove(); if (picNode === pic) picNode = null; });
      stemNode.after(pic);
    }

    if (isFill) {
      const inp = el('input', {
        class: 'input blank-input',
        placeholder: meta.kind === 'fill' ? '请输入答案' : '请输入作答内容',
        value: cur == null ? (q.stu_key || '') : String(cur),
      });
      inp.addEventListener('input', () => {
        state.answers.set(state.paperId, inp.value.trim());
        state.dirty = true;
      });
      optionNode.append(inp);
      return;
    }

    const selectable = opts.length > 0
      ? opts
      : [{ key: 'A', text: '正确' }, { key: 'B', text: '错误' }];

    const current = cur == null ? splitAnswer(q.stu_key) : splitAnswer(cur);

    for (const o of selectable) {
      const on = current.includes(o.key);
      const item = el('div', {
        class: `option-item${on ? ' is-selected' : ''}`,
        dataset: { key: o.key },
      }, [
        el('span.option-key', { text: o.key }),
        el('span.option-text', { text: o.text }),
      ]);
      item.addEventListener('click', () => {
        if (state.finished) return;
        if (meta.kind === 'multi') {
          const set = new Set(splitAnswer(state.answers.get(state.paperId) ?? q.stu_key));
          if (set.has(o.key)) set.delete(o.key); else set.add(o.key);
          const val = [...set].sort().join('');
          state.answers.set(state.paperId, val);
          state.dirty = true;
          renderQuestion();
        } else {
          state.answers.set(state.paperId, o.key);
          state.dirty = true;
          renderQuestion();
        }
      });
      optionNode.append(item);
    }
  }

  function splitAnswer(v) {
    const s = String(v == null ? '' : v).toUpperCase().replace(/[^A-Z]/g, '');
    return s ? s.split('') : [];
  }

  function renderAll() {
    const qn = questionCard.querySelector('.question-no');
    const qt = questionCard.querySelector('.question-type');
    if (qn) qn.textContent = `第 ${state.paperId} / ${state.nav.total} 题`;
    if (qt && state.question) qt.textContent = state.question.quiz_type_label || '';
    renderProgress();
    renderSheet();
    renderQuestion();
    prevBtn.disabled = state.paperId <= 1;
    nextBtn.disabled = state.paperId >= state.nav.total;
  }

  // 翻页序号：连点「下一题」会并发多个 loadPaper，谁后返回谁生效，
  // 最终停在哪题取决于响应顺序而非用户点击。用序号丢弃过期结果。
  let navSeq = 0;

  async function go(paperId) {
    if (paperId < 1 || paperId > state.nav.total) return;
    if (paperId === state.paperId && state.question) return;
    if (state.dirty && !(await saveCurrent())) return;
    const token = ++navSeq;
    const res = await withLoading(questionCard, () => cfg.loadPaper(paperId), { silent: true });
    if (token !== navSeq) return; // 已有更新的翻页请求，丢弃本次结果
    if (!res.ok) return;
    state.paperId = res.result.paper_id || paperId;
    state.question = res.result.question;
    state.nav = res.result.navigation || state.nav;
    state.dirty = false;
    renderAll();
  }

  async function saveCurrent() {
    const val = localAnswer(state.paperId);
    if (val == null) { state.dirty = false; return true; }
    // 保存失败（网络抖动 / 已被强制收卷）时不能让异常冒泡成 unhandled rejection：
    // 答题卡跳题处是 saveCurrent().then(...)，doSubmit 里也是直接 await。
    try {
      const res = await cfg.saveAnswer(state.paperId, val);
      if (res && res.navigation) state.nav = res.navigation;
      setAnswered(state.paperId, true);
      state.dirty = false;
      if (res && res.submitted) {
        state.finished = true;
        await finish();
        return false;
      }
      return true;
    } catch (e) {
      notify.error('答案保存失败，请检查网络后重试');
      return false;
    }
  }

  async function confirmSubmit() {
    if (state.submitting || state.finished) return;
    const unanswered = state.nav.total - state.nav.done;
    const ok = await new Promise((resolve) => {
      // 必须持有 openModal 的返回值并在按钮里关闭：此前只 resolve 不 close，
      // 遮罩会永久停留挡住交卷结果页，body 的滚动锁也不会解除。
      const dlg = openModal({
        title: '确认交卷',
        size: 'sm',
        body: el('div.stack', {}, [
          el('p', { text: '交卷后不可再修改答案，确认现在交卷吗？' }),
          unanswered > 0
            ? el('div.alert.alert-warning', {}, [icon('alert-triangle', { size: 15 }), el('span', { text: `还有 ${unanswered} 道题未作答` })])
            : el('div.alert.alert-success', {}, [icon('check-circle', { size: 15 }), el('span', { text: '所有题目均已作答' })]),
        ]),
        footer: el('div.row.gap-sm', {}, [
          button('再检查一下', { variant: 'secondary', onClick: () => { dlg.close(); resolve(false); } }),
          button('确认交卷', { variant: 'danger', onClick: () => { dlg.close(); resolve(true); } }),
        ]),
        // ESC / 点击遮罩 / 右上角 X 关闭时也要结束等待
        onClose: () => resolve(false),
      });
    });
    if (!ok) return;
    await doSubmit();
  }

  async function doSubmit() {
    if (state.submitting) return;
    state.submitting = true;
    if (state.dirty) await saveCurrent();
    const res = await withLoading(submitBtn, () => cfg.submit());
    state.submitting = false;
    if (!res.ok) return;
    state.finished = true;
    stopTimer();
    if (res.result && res.result.already_submitted) {
      notify.info('本场考试已交卷');
    } else {
      notify.success(`交卷成功，得分 ${fmtScore(res.result?.score ?? 0)}`);
    }
    await finish();
  }

  async function finish() {
    stopTimer();
    clear(root);
    // http.js 已解包信封，loadReview() 返回的就是 data 本身，
    // 此前写 `.result || (await ...)` 使左侧恒为 undefined，每次交卷都多发一次请求。
    const data = cfg.loadReview ? await cfg.loadReview() : null;
    root.append(renderResult(data));
  }

  function stopTimer() {
    if (state.timer) { clearInterval(state.timer); state.timer = null; }
  }

  function startTimer() {
    if (!cfg.examEnd) return;
    const end = new Date(String(cfg.examEnd).replace(/-/g, '/')).getTime();
    if (!Number.isFinite(end)) return;
    const tick = () => {
      const left = Math.max(0, Math.floor((end - Date.now()) / 1000));
      state.remaining = left;
      clear(timerNode);
      timerNode.classList.toggle('is-urgent', left <= 300);
      timerNode.append(icon('clock', { size: 15 }), el('span', { text: fmtDuration(left) }));
      if (left <= 0) {
        stopTimer();
        // 自动交卷在定时器回调里触发，异常必须就地兜住，否则倒计时归零时
        // 一次保存失败就会让交卷静默不执行，且抛出的 Promise 无人处理。
        if (!state.finished) doSubmit().catch(() => {});
      }
    };
    tick();
    state.timer = setInterval(tick, 1000);
  }

  /**
   * 退出答题：先执行 onExit 清理钩子（如考场登出），失败也不阻塞跳转。
   */
  async function exit() {
    try { await cfg.onExit?.(); } catch (_) { /* 忽略 */ }
    location.assign(cfg.exitUrl || '/student');
  }

  function renderResult(data) {
    const area = el('div.result-area.stack');
    if (!data) {
      area.append(el('div.card.pad-lg.center', {}, [
        el('div', {}, [icon('check-circle', { size: 44 })]),
        el('h2', { text: '已交卷' }),
        el('p.muted', { text: '本次答题已提交。' }),
        button('返回', { variant: 'primary', onClick: () => exit() }),
      ]));
      return area;
    }

    const score = data.score ?? 0;
    const total = data.total_score ?? data.exam_score ?? 100;
    const pass = total > 0 && score / total >= 0.6;
    area.append(el('div.result-hero.card', {}, [
      el('div.result-score' + (pass ? '.is-pass' : '.is-fail'), {}, [
        el('span', { text: fmtScore(score) }),
        el('small', { text: `/ ${total}` }),
      ]),
      el('div.result-meta', {}, [
        el('h2', { text: pass ? '恭喜通过' : '继续加油' }),
        el('p.muted', { text: data.exam_name || '' }),
      ]),
    ]));

    if (Array.isArray(data.summary) && data.summary.length) {
      const rows = data.summary.map((s) => ({ label: s.label, value: `${s.right}/${s.total} 题 · ${fmtScore(s.score)} 分` }));
      area.append(el('div.card', {}, [el('div.card-body.desc-list', {}, rows.map((r) => el('div.dl-row', {}, [
        el('span.dl-key', { text: r.label }), el('span.dl-val', { text: r.value }),
      ])))]));
    }

    const wrong = data.wrong || [];
    if (wrong.length) {
      area.append(el('div.card', {}, [
        el('div.card-head', {}, [el('div.card-title', {}, [icon('x-circle', { size: 16 }), el('span', { text: `错题回顾（${wrong.length}）` })])]),
        el('div.card-body.stack', {}, wrong.map((w) => explainBlock(w))),
      ]));
    } else if (Array.isArray(data.papers) && data.papers.length) {
      area.append(el('div.card', {}, [
        el('div.card-head', {}, [el('div.card-title', {}, [icon('list', { size: 16 }), el('span', { text: '答题详情' })])]),
        el('div.card-body.stack', {}, data.papers.map((p) => explainBlock({
          quiz_title: p.quiz_title,
          quiz_class_name: p.quiz_type_label || p.quiz_class,
          quiz_option_list: p.quiz_option_list || [],
          stu_key: p.stu_key,
          quiz_key: p.quiz_key,
          is_correct: p.is_correct,
        }))),
      ]));
    }

    area.append(el('div.row.center.gap-sm', {}, [
      button('返回', { variant: 'primary', iconName: 'home', onClick: () => exit() }),
      cfg.onRetry ? button('再练一次', { variant: 'secondary', iconName: 'refresh-cw', onClick: cfg.onRetry }) : null,
    ]));
    return area;
  }

  function explainBlock(w) {
    const box = el('div.explain-block');
    box.append(el('div.explain-title', {}, [
      el('span.tag', { text: w.quiz_class_name || '' }),
      el('span', { text: w.quiz_title || '' }),
    ]));
    const opts = w.quiz_option_list || [];
    if (opts.length) {
      const correct = splitAnswer(w.quiz_key);
      const mine = splitAnswer(w.stu_key);
      const list = el('div.explain-options');
      for (const o of opts) {
        const isC = correct.includes(o.key);
        const isW = !isC && mine.includes(o.key);
        list.append(el('div.option-item' + (isC ? '.is-correct' : isW ? '.is-wrong' : ''), {}, [
          el('span.option-key', { text: o.key }),
          el('span.option-text', { text: o.text }),
        ]));
      }
      box.append(list);
    }
    box.append(el('div.explain-answer', {}, [
      el('span.explain-key', { text: '正确答案：' + (w.quiz_key || '—') }),
      el('span.explain-mine.muted', { text: '我的答案：' + (w.stu_key || '未作答') }),
    ]));
    return box;
  }

  return {
    node: root,
    dispose: stopTimer,
    async start() {
      const res = await withLoading(root, () => cfg.loadPaper(1), { text: '正在加载试卷…' });
      if (!res.ok) {
        clear(root);
        root.append(el('div.card.pad-lg.center', {}, [
          icon('alert-circle', { size: 40 }),
          el('h3', { text: '试卷加载失败' }),
          el('p.muted', { text: res.error?.message || '请稍后重试' }),
          button('返回', { variant: 'primary', onClick: () => exit() }),
        ]));
        return;
      }
      state.paperId = res.result.paper_id || 1;
      state.question = res.result.question;
      state.nav = res.result.navigation || state.nav;
      renderAll();
      startTimer();
    },
    /** 供外部（练习逐题模式）刷新当前题 */
    getState: () => state,
  };
}
