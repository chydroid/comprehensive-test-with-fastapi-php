/**
 * 模拟考试视图：自主组卷 → 限时答题（复用 exam-runner）→ 成绩与错题回顾。
 */

import { el, clear, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, card, badge, field, input, select, table, notify, alertBox,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { exerciseApi } from '../../api/index.js';
import { createExamRunner, QTYPE } from '../exam-runner.js';
import { fmtScore, fmtDuration } from '../../core/format.js';

/** 模拟考试默认分值（与后端 VALUE_MAP 一致） */
const MOCK_VALUE = { radio1: 2, radio2: 2, checkbox: 3, text: 5 };

/* ============================ 组卷配置 ============================ */
export function MockSetupView({ router }) {
  const root = el('div.stack');
  const bodySlot = el('div');

  const state = {
    subjects: [], subjId: 0, counts: {},
    want: { radio1: 0, radio2: 0, checkbox: 0, text: 0 },
    // 服务端下发的配额（后台「系统设置 → 模拟考试与练习」）：每日场次上限 / 已用 /
    // 剩余 / 单场题目总数上限 / 是否暂停及暂停原因
    limits: {
      daily_limit: 5, used_today: 0, remaining: 5, max_questions: 100,
      paused: false, pause_reason: '',
    },
  };

  root.append(el('div.page-head', {}, [
    el('div', {}, [
      el('h1.page-title', { text: '模拟考试' }),
      el('p.page-sub', { text: '自主配置题型数量，系统随机组卷' }),
    ]),
    el('div.page-head-actions', {}, [
      button('返回', { variant: 'ghost', size: 'sm', iconName: 'arrow-left', onClick: () => router.navigate('/exercise') }),
    ]),
  ]), bodySlot);

  /**
   * 因「策略暂停」或「今日场次用尽」而无法组卷时的说明节点；可组卷时返回 null。
   * 提示文案与后端拒绝口径一致，避免考生配好一卷才被拒。
   */
  function quotaBlocked() {
    const l = state.limits;
    if (l.paused) {
      // 身已在考场：硬约束，后台开关也放不开，指引进考场处理掉考试。
      const self = l.pause_reason === 'self_in_exam';
      return alertBox(
        self
          ? '你正在参加正式考试，不能同时进行模拟考试。请先交卷或退出考场后再回来。'
          : '当前有正在进行的正式考试，按考场纪律要求已暂停模拟考试。请考试结束后再试。',
        { type: 'warning', title: self ? '你正在考试中' : '模拟考试已暂停' },
      );
    }
    if (Number(l.remaining) <= 0) {
      return alertBox(`你今天的模拟考试场次已用完（每日上限 ${l.daily_limit} 场），请明天再试。`, {
        type: 'warning', title: '今日场次已用尽',
      });
    }
    return null;
  }

  (async () => {
    const res = await withLoading(bodySlot, () => exerciseApi.mockConfig());
    if (!res.ok) return;
    state.subjects = res.result.subjects || [];
    state.limits = { ...state.limits, ...(res.result.limits || {}) };
    if (!state.subjects.length) { mount(bodySlot, alertBox('暂无可用科目', { type: 'warning' })); return; }

    const blocked = quotaBlocked();
    if (blocked) { mount(bodySlot, blocked); return; }

    state.subjId = state.subjects[0].id;
    await loadCounts();
    render();
  })();

  async function loadCounts() {
    const res = await exerciseApi.mockCounts({ subj_id: state.subjId }).catch(() => null);
    state.counts = (res && res.counts) || { radio1: 0, radio2: 0, checkbox: 0, text: 0 };
  }

  function render() {
    clear(bodySlot);
    // 单场题目总数上限（后台可配，默认 100），与后端 mock_max_questions 同口径
    const maxQ = Math.max(1, Number(state.limits.max_questions) || 100);
    // 除当前题型外已占用的题数，用于把「抽题数量」就地钳制到剩余额度内
    const usedByOthers = (t) => Object.entries(state.want)
      .reduce((sum, [k, n]) => (k === t ? sum : sum + n), 0);

    const subjSel = select(
      state.subjects.map((s) => ({ value: String(s.id), label: s.subj_name })),
      { name: 'subj_id', value: String(state.subjId) },
    );
    subjSel.addEventListener('change', async () => {
      state.subjId = Number(subjSel.value);
      await loadCounts();
      render();
    });

    const rows = [];
    for (const t of ['radio1', 'radio2', 'checkbox', 'text']) {
      // 可抽上限同时受「题库数量」与「单场题目总数上限」约束
      const max = Math.min(state.counts[t] || 0, maxQ);
      const numIn = input({ type: 'number', value: String(state.want[t] || 0), min: 0, max });
      numIn.addEventListener('input', () => {
        const room = Math.max(0, maxQ - usedByOthers(t));
        const v = Math.max(0, Math.min(max, room, Number(numIn.value) || 0));
        state.want[t] = v;
        numIn.value = String(v);
        updateSummary();
      });
      rows.push({ type: t, label: QTYPE[t]?.label || t, max, numIn });
    }

    const totalNode = el('strong');
    const scoreNode = el('strong');
    function updateSummary() {
      const total = Object.values(state.want).reduce((a, b) => a + b, 0);
      const score = Object.entries(state.want).reduce((sum, [t, n]) => sum + n * (MOCK_VALUE[t] || 0), 0);
      const over = total > maxQ;
      totalNode.textContent = `${total} 题${over ? `（超出上限 ${maxQ}）` : ''}`;
      scoreNode.textContent = `${score} 分`;
      startBtn.disabled = total <= 0 || over || !!state.limits.paused || Number(state.limits.remaining) <= 0;
    }

    const startBtn = button('开始模拟考试', {
      variant: 'primary', iconName: 'play', block: true,
      onClick: async () => {
        const payload = { subj_id: state.subjId };
        for (const [t, n] of Object.entries(state.want)) payload[`${t}_count`] = n;
        const res = await withLoading(startBtn, () => exerciseApi.mockStart(payload));
        if (!res.ok) {
          // 题库不足时后端在 error.data 里回传逐题型的缺口明细
          const detail = res.error?.data;
          notify.error(res.error?.message || '组卷失败');
          if (Array.isArray(detail) && detail.length) {
            detail.forEach((d) => notify.warning(`${d.label || d.type}：需要 ${d.need} 题，题库仅 ${d.have} 题`));
          }
          return;
        }
        notify.success(`已生成 ${res.result.total} 题试卷`);
        router.navigate(`/exercise/mock/take?exam_id=${res.result.exam_id}`);
      },
    });

    const t = table({
      columns: [
        { key: 'label', title: '题型' },
        { key: 'max', title: '可抽数量', align: 'right', render: (r) => el('span.muted', { text: String(r.max) }) },
        { key: 'val', title: '每题分值', align: 'right', render: (r) => el('span', { text: `${MOCK_VALUE[r.type] || 0} 分` }) },
        { key: 'want', title: '抽取数量', align: 'right', render: (r) => r.numIn },
      ],
      rows,
      emptyText: '',
    });

    bodySlot.append(card({
      iconName: 'edit-3',
      body: el('div.stack', {}, [
        el('div.form-grid', {}, [field('考试科目', subjSel)]),
        el('div.table-wrap', {}, [t]),
        el('div.desc-list', {}, [
          el('div.dl-row', {}, [el('span.dl-key', { text: '总题数' }), el('span.dl-val', {}, [totalNode])]),
          el('div.dl-row', {}, [el('span.dl-key', { text: '总分' }), el('span.dl-val', {}, [scoreNode])]),
          el('div.dl-row', {}, [
            el('span.dl-key', { text: '单场题目上限' }),
            el('span.dl-val', { text: `${maxQ} 题` }),
          ]),
          el('div.dl-row', {}, [
            el('span.dl-key', { text: '今日剩余场次' }),
            el('span.dl-val', {
              text: `${state.limits.remaining} / ${state.limits.daily_limit} 场`,
            }),
          ]),
        ]),
        startBtn,
      ]),
    }));
    updateSummary();
  }

  return root;
}

/* ============================ 模拟答题 ============================ */
export function MockTakeView({ router, query }) {
  const examId = Number(query?.exam_id || 0);
  const host = el('div.stack');

  if (!examId) {
    host.append(alertBox('缺少 exam_id 参数', { type: 'danger' }));
    return host;
  }

  const runner = createExamRunner({
    mode: 'mock',
    title: '模拟考试',
    exitUrl: '/exercise',
    loadPaper: (paperId) => exerciseApi.mockPaper({ exam_id: examId, paper_id: paperId }),
    saveAnswer: (paperId, answer) => exerciseApi.mockSave({ exam_id: examId, paper_id: paperId, stu_key: answer }),
    submit: () => exerciseApi.mockSubmit({ exam_id: examId }),
    loadReview: () => exerciseApi.mockOver({ exam_id: examId }),
    onRetry: () => router.navigate('/exercise/mock'),
    // 退出时通知服务端结束本次模拟（清掉临时状态）
    onExit: () => exerciseApi.mockLogout({ exam_id: examId }),
  });

  mount(host, runner.node);
  runner.start();

  // 必须返回 dispose：此前直接返回 host，答题引擎内部的 1 秒倒计时定时器
  // 在离开页面后仍继续运行，归零时会对已卸载的试卷触发一次自动交卷。
  return { node: host, dispose: () => runner.dispose() };
}

/* ============================ 错题回顾 ============================ */
export function MockReviewView({ router, query }) {
  const examId = Number(query?.exam_id || 0);
  const root = el('div.stack');
  const bodySlot = el('div');

  root.append(el('div.page-head', {}, [
    el('div', {}, [el('h1.page-title', { text: '错题回顾' }), el('p.page-sub', { text: '只看做错的题，逐题对照正确答案' })]),
    el('div.page-head-actions', {}, [
      button('返回', { variant: 'ghost', size: 'sm', iconName: 'arrow-left', onClick: () => router.navigate('/exercise') }),
    ]),
  ]), bodySlot);

  (async () => {
    const res = await withLoading(bodySlot, () => exerciseApi.mockReview({ exam_id: examId }));
    if (!res.ok) { mount(bodySlot, alertBox(res.error?.message || '加载失败', { type: 'danger' })); return; }
    const d = res.result;
    const wrong = d.wrong || [];

    const statSlot = el('div.grid-stats', {}, [
      stat({ label: '总题数', value: d.total_count, iconName: 'list' }),
      stat({ label: '答对', value: d.right_count, iconName: 'check-circle', tone: 'success' }),
      stat({ label: '答错', value: wrong.length, iconName: 'x-circle', tone: 'danger' }),
      stat({ label: '得分', value: `${fmtScore(d.score)}/${fmtScore(d.total_score)}`, iconName: 'award', tone: 'brand' }),
    ]);

    if (!wrong.length) {
      mount(bodySlot, el('div.stack', {}, [
        statSlot,
        el('div.card.pad-lg.center', {}, [
          icon('party-popper', { size: 42 }),
          el('h3', { text: '全部答对，太棒了！' }),
          button('再做一套', { variant: 'primary', onClick: () => router.navigate('/exercise/mock') }),
        ]),
      ]));
      return;
    }

    const listNode = el('div.stack', {}, wrong.map((w) => {
      const box = el('div.card');
      box.append(el('div.card-head', {}, [
        el('div.card-title', {}, [badge(w.quiz_class_name || w.quiz_class, { tone: 'brand' }), el('span', { text: `第 ${w.paper_id} 题` })]),
      ]));
      const body = el('div.card-body.stack');
      body.append(el('div.question-stem', { text: w.quiz_title }));
        if (w.quiz_pic_name) {
          // 只保留 addEventListener（内联 onerror 在 CSP script-src 'self' 下不执行）
          const img = el('img.question-pic', { src: `/uploads/${w.quiz_pic_name}`, alt: '题目配图' });
          img.addEventListener('error', () => img.remove());
          body.append(img);
        }
      const opts = w.quiz_option_list || [];
      if (opts.length) {
        const correct = keys(w.quiz_key);
        const mine = keys(w.stu_key);
        const list = el('div.option-list');
        for (const o of opts) {
          const cls = correct.includes(o.key) ? ' is-correct' : (mine.includes(o.key) ? ' is-wrong' : '');
          list.append(el('div.option-item' + cls, {}, [
            el('span.option-key', { text: o.key }),
            el('span.option-text', { text: o.text }),
          ]));
        }
        body.append(list);
      }
      body.append(el('div.explain-answer', {}, [
        el('span.explain-key', { text: '正确答案：' + (w.quiz_key || '—') }),
        el('span.explain-mine.muted', { text: '我的答案：' + (w.stu_key || '未作答') }),
      ]));
      box.append(body);
      return box;
    }));

    mount(bodySlot, el('div.stack', {}, [statSlot, listNode]));
  })();

  function keys(v) {
    return String(v || '').toUpperCase().replace(/[^A-Z]/g, '').split('').filter(Boolean);
  }

  function stat(cfg) {
    return el('div.stat-card', {}, [
      el('div.stat-icon', {}, [icon(cfg.iconName, { size: 18 })]),
      el('div', {}, [el('div.stat-value', { text: String(cfg.value) }), el('div.stat-label', { text: cfg.label })]),
    ]);
  }

  return root;
}
