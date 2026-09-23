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
import { notify, button, openModal, confirmDialog } from '../ui/components.js';
import { withLoading } from '../core/bootstrap.js';
import { fmtScore, fmtDuration } from '../core/format.js';
import { appSettingBool } from '../core/app-settings.js';
import { getCsrfToken } from '../core/http.js';

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
  // 移动端：底部「答题卡」按钮（桌面端由 CSS 隐藏，答题卡在左栏常显），
  // 点击切换底部抽屉式答题卡。
  const sheetBtn = button('答题卡', { variant: 'ghost', iconName: 'grid', class: 'sheet-toggle', onClick: () => root.classList.toggle('is-sheet-open') });
  const bottomBar = el('div.exam-bottombar', {}, [
    el('div.exam-bottombar-left', {}, [prevBtn]),
    el('div.exam-bottombar-right', {}, [sheetBtn, nextBtn, submitBtn]),
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
  // 移动端底部抽屉答题卡的蒙层：点击关闭
  const sheetScrim = el('div.answer-sheet-scrim', { on: { click: () => root.classList.remove('is-sheet-open') } });
  // 答题卡在左侧边栏（桌面/平板），题目内容在右侧：DOM 顺序与 grid 模板列序一致，
  // 故 sheetCard 排在 main 之前。移动端仍为底部抽屉，不受此顺序影响。
  root.append(topbar, el('div.exam-body', {}, [sheetCard, main]), sheetScrim);

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
          on: { click: () => { root.classList.remove('is-sheet-open'); if (state.dirty) saveCurrent().then(() => go(it.paper_id)); else go(it.paper_id); } },
        }));
      }
      sheetGrid.append(row);
    }
  }

  // 当前题干配图节点。renderQuestion 在每次点选后都会被重绘（单选换项 / 多选切换），
  // 而配图是插在 stemNode 之后的常驻节点、此前从不清理 —— 于是点一次选项就多叠一张图。
  // 每次重绘前先移除上一张，保证题干下始终只有一张配图。
  let picNode = null;

  // B1 防作弊：切屏 / 失焦监听与浮水印（仅正式考试 + 启用防作弊时挂载）。
  // 三者都在视图销毁时清理，避免离开答题页后继续上报或残留 DOM。
  let watermarkNode = null;
  let visibilityHandler = null;
  let blurHandler = null;

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
        scheduleAutosave();
      });
      optionNode.append(inp);
      return;
    }

    const selectable = opts.length > 0
      ? opts
      : [{ key: 'A', text: '正确' }, { key: 'B', text: '错误' }];

    // B1 选项乱序：按本卷该题的展示顺序重排选项；答案键仍是原始字母，不影响判分
    const order = (q.option_order || '').trim();
    let selectableList = selectable;
    if (order) {
      const byKey = new Map(selectable.map((o) => [o.key, o]));
      const reordered = order.split('').map((k) => byKey.get(k)).filter(Boolean);
      if (reordered.length === selectable.length) selectableList = reordered;
    }

    const current = cur == null ? splitAnswer(q.stu_key) : splitAnswer(cur);

    for (const o of selectableList) {
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
          scheduleAutosave();
          renderQuestion();
        } else {
          state.answers.set(state.paperId, o.key);
          state.dirty = true;
          scheduleAutosave();
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
  // 防抖自动保存：作答变更后延迟落盘，避免「答完不翻页直接关页/刷新」丢失当前题（BUG-245）。
  let autosaveTimer = null;

  function scheduleAutosave() {
    if (autosaveTimer) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(() => {
      autosaveTimer = null;
      if (state.dirty && !state.finished) saveCurrent();
    }, 1200);
  }

  async function go(paperId) {
    if (paperId < 1 || paperId > state.nav.total) return;
    if (paperId === state.paperId && state.question) return;
    if (state.dirty) {
      const saved = await saveCurrent();
      if (!saved) {
        // 保存失败（网络抖动 / 已被强制收卷）：不再硬性拦死翻页，
        // 询问用户是否丢弃本题作答后离开，避免考生被困在当前题（BUG-244）。
        const leave = await confirmDialog('当前题答案保存失败，仍要离开吗？离开将不会保存本题作答。', {
          title: '保存失败', confirmText: '仍要离开', cancelText: '留在本题', tone: 'warning',
        });
        if (!leave) return;
        state.dirty = false;
      }
    }
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
    if (autosaveTimer) { clearTimeout(autosaveTimer); autosaveTimer = null; }
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

  /**
   * 卸载前强制落盘当前题：用 keepalive fetch 把脏答案送出去，
   * 页面关闭 / 刷新 / 切到其他 App 时都不会丢当前题。
   *
   * 为什么不能在 beforeunload 里 await fetch：beforeunload 触发后页面立即卸载，
   * 普通 async 请求来不及发出。keepalive 是专门为「页面卸载后仍要送达」
   * 设计的请求选项（Chrome 88+ / Firefox 88+ / Safari 14+）。
   *
   * 真正的硬断电（进程直接死）任何事件都触发不了，只能靠防抖自动保存兜底，
   * 所以这里只负责把「当前题在 1.2s 防抖窗口内」这一种常见丢失场景堵上。
   */
  function flushOnUnload() {
    if (state.finished || !state.dirty) return;
    const val = localAnswer(state.paperId);
    if (val == null) { state.dirty = false; return; }
    try {
      fetch('/api/exam/paper/save', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': getCsrfToken(),
        },
        body: JSON.stringify({ paper_id: state.paperId, stu_key: val }),
        keepalive: true,
        credentials: 'same-origin',
      });
    } catch (_) { /* 忽略：卸载时失败不影响主流程 */ }
    state.dirty = false;
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
    if (state.dirty) {
      // 交卷前必须先把当前题落库：保存失败时先重试一次；仍失败则阻断交卷，
      // 避免基于数据库旧数据交卷导致最后一题答案丢失。
      let saved = await saveCurrent();
      if (!saved) saved = await saveCurrent();
      if (!saved) {
        state.submitting = false;
        notify.error('答案保存失败，交卷已中止，请检查网络后重试');
        return;
      }
    }
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
    // 若 loadReview 抛错（如 403/网络问题），此前未捕获：既会让 doSubmit 的 Promise 链断裂，
    // 又会让交卷请求的 403 触发全局 onForbidden → 跳回入口，用户看到的就是"交卷成功后跳回"。
    // 现在兜住异常：data 为 null 时仍渲染基础交卷结果卡（得分等信息缺失但不影响"已交卷"状态）。
    let data = null;
    if (cfg.loadReview) {
      try { data = await cfg.loadReview(); } catch (_) { /* 静默：交卷本身已成功 */ }
    }
    root.append(renderResult(data));
  }

  function stopTimer() {
    if (state.timer) { clearInterval(state.timer); state.timer = null; }
  }

  /**
   * B1 防作弊：在正式考试且启用防作弊时，挂载
   *  - 浮水印（平铺考生标识，pointer-events:none，降低截屏泄题动机）；
   *  - 切屏 / 失焦监听（上报异常行为）。
   * 仅当调用方提供 cfg.reportCheat 回调时挂载监听（其余模式无上报入口）。
   */
  function setupCheatGuard() {
    if (cfg.mode !== 'exam' || !appSettingBool('enable_cheat_guard') || typeof cfg.reportCheat !== 'function') {
      return;
    }
    if (cfg.cheatLabel) {
      watermarkNode = createWatermark(cfg.cheatLabel);
      root.append(watermarkNode);
    }
    visibilityHandler = () => {
      if (document.hidden) reportCheat('tab_hidden', '切换标签页 / 窗口最小化');
    };
    document.addEventListener('visibilitychange', visibilityHandler);
    blurHandler = () => reportCheat('blur', '答题窗口失去焦点');
    window.addEventListener('blur', blurHandler);
  }

  function teardownCheatGuard() {
    if (visibilityHandler) document.removeEventListener('visibilitychange', visibilityHandler);
    if (blurHandler) window.removeEventListener('blur', blurHandler);
    if (watermarkNode) watermarkNode.remove();
    visibilityHandler = null;
    blurHandler = null;
    watermarkNode = null;
  }

  // 上报异常行为：旁路、fire-and-forget，绝不阻塞答题或抛错
  function reportCheat(type, detail) {
    try {
      const p = cfg.reportCheat(type, detail);
      if (p && typeof p.catch === 'function') p.catch(() => {});
    } catch (_) { /* 忽略上报失败 */ }
  }

  function createWatermark(label) {
    const wm = el('div.exam-watermark', { 'aria-hidden': 'true' });
    const total = 54;
    for (let i = 0; i < total; i++) {
      wm.append(el('span.exam-watermark-item', { text: label }));
    }
    return wm;
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
    dispose: () => {
      stopTimer();
      teardownCheatGuard();
      window.removeEventListener('beforeunload', flushOnUnload);
      window.removeEventListener('pagehide', flushOnUnload);
    },
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
      setupCheatGuard();
      // 页面卸载前（关标签 / 刷新 / 切到其它 App）强制落盘当前题，
      // 缩小「答完不翻页直接关页」在 1.2s 防抖窗口内的丢失面。
      window.addEventListener('beforeunload', flushOnUnload);
      window.addEventListener('pagehide', flushOnUnload);
    },
    /** 供外部（练习逐题模式）刷新当前题 */
    getState: () => state,
  };
}
