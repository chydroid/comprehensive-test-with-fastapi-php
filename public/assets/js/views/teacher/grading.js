/**
 * 教师端视图：「主观题批改」（A4）。
 *
 * 为什么需要这一页：问答题（longtext）没有可自动比对的答案键，交卷时
 * 后端只计算客观题得分。若没有人工批阅入口，含问答题的考试满分永远拿不满，
 * 考生看到的成绩是残缺的。这里提供「待批总览 → 逐题给分 → 保存重算总分」闭环。
 *
 * 口径与「成绩查询」保持一致：只列本人监考的**已结束**考试，
 * 只批阅已交卷（stu_status 以 over 开头）的考生。
 */

import { el, mount } from '../../core/dom.js';
import {
  button, card, badge, table, field, input, textarea, notify, emptyStated,
  segmented, alertBox, openModal, statCard, confirmDialog,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { teacherApi } from '../../api/index.js';

/** 题型标签（与后端 Quiz::TYPE_LABELS 一致） */
const TYPE_LABELS = {
  radio1: '判断题', radio2: '单选题', checkbox: '多选题', text: '填空题', longtext: '问答题',
};

export function TeacherGradingView({ query } = {}) {
  const root = el('div.stack');
  const pickerSlot = el('div');
  const statsSlot = el('div.grid-stats');
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '200px' } });
  const state = { examId: Number(query?.exam_id || 0), exams: [], exam: null, overview: null };

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [
        el('h1.page-title', { text: '主观题批改' }),
        el('p.page-sub', { text: '为问答题逐题评分，保存后自动重算考生总分' }),
      ]),
    ]),
    pickerSlot, statsSlot, tableSlot,
  );

  async function load() {
    const res = await withLoading(tableSlot, () => teacherApi.scores(state.examId ? { exam_id: state.examId } : {}));
    if (!res.ok) {
      mount(tableSlot, alertBox(res.error?.message || '加载失败，请稍后重试', { type: 'danger' }));
      return;
    }
    state.exams = res.result.exams || [];
    state.exam = res.result.exam || null;

    if (!state.exams.length) {
      mount(tableSlot, emptyStated('暂无可批阅的考试', {
        iconName: 'calendar',
        desc: '只有由您监考、且已结束的考试才会出现在这里',
      }));
      return;
    }
    if (!state.examId) {
      state.examId = state.exams[0].id;
      return load();
    }
    renderPicker();
    await loadOverview();
  }

  async function loadOverview() {
    const res = await withLoading(tableSlot, () => teacherApi.subjectiveList(state.examId));
    if (!res.ok) {
      mount(tableSlot, alertBox(res.error?.message || '加载待批列表失败', { type: 'danger' }));
      return;
    }
    state.exam = { ...(res.result.exam || {}), ...(state.exam || {}) };
    state.overview = res.result.overview || { list: [] };
    renderStats();
    renderTable();
  }

  function renderPicker() {
    pickerSlot.replaceChildren();
    if (!state.exams.length) return;
    pickerSlot.append(card({
      iconName: 'calendar',
      body: segmented(
        state.exams.slice(0, 12).map((e) => ({ key: String(e.id), label: e.exam_name })),
        String(state.examId),
        (k) => { state.examId = Number(k); loadOverview(); },
      ),
    }));
  }

  function renderStats() {
    const ov = state.overview || {};
    const totalItems = Number(ov.total_items) || 0;
    const pendingItems = Number(ov.pending_items) || 0;
    const graded = Math.max(0, totalItems - pendingItems);
    const fullScore = Number(state.exam?.longtext_val) || 0;
    mount(statsSlot, [
      statCard({ label: '已交卷考生', value: String(Number(ov.students) || 0), iconName: 'users' }),
      statCard({ label: '待批题数', value: String(pendingItems), iconName: 'edit-3', tone: pendingItems > 0 ? 'warning' : 'success' }),
      statCard({ label: '已批题数', value: String(graded), iconName: 'check', tone: 'brand' }),
      statCard({ label: '每题满分', value: fullScore > 0 ? String(fullScore) : '—', iconName: 'award' }),
    ]);
  }

  function renderTable() {
    const list = (state.overview?.list || []);
    if (!list.length) {
      mount(tableSlot, emptyStated('本场考试没有需要批阅的问答题', {
        iconName: 'clipboard',
        desc: '请确认组卷时已配置问答题数量；或本场尚无考生交卷',
      }));
      return;
    }
    const t = table({
      columns: [
        { key: 'stu_id', title: '准考证号' },
        { key: 'stu_name', title: '姓名', render: (r) => r.stu_name || '—' },
        { key: 'class_id', title: '班级', render: (r) => el('span.muted', { text: r.class_id || '—' }) },
        { key: '_progress', title: '批阅进度', render: (r) => {
          const items = Number(r.items) || 0;
          const pending = Number(r.pending) || 0;
          const done = items - pending;
          return badge(`${done}/${items}`, { tone: pending === 0 ? 'success' : 'warning', dot: true });
        } },
        { key: 'stu_score', title: '当前总分', align: 'right', render: (r) => el('strong', { text: String(Number(r.stu_score) || 0) }) },
        { key: '_op', title: '操作', align: 'right', render: (r) => button('批阅', {
          size: 'sm',
          variant: Number(r.pending) > 0 ? 'primary' : 'secondary',
          onClick: () => openGrading(r),
        }) },
      ],
      rows: list,
      emptyText: '暂无数据',
    });
    mount(tableSlot, t);
  }

  /* ---------------- 批阅弹窗 ---------------- */

  async function openGrading(row) {
    const res = await withLoading(tableSlot, () => teacherApi.subjectivePaper(state.examId, row.stu_id));
    if (!res.ok) {
      notify.error(res.error?.message || '加载答题卡失败');
      return;
    }
    const data = res.result || {};
    const items = data.items || [];
    if (!items.length) {
      notify.warning('该考生没有问答题作答记录');
      return;
    }
    const fullScore = Number(data.full_score) || 0;

    const inputs = new Map();  // paper_id -> { scoreCtl, commentCtl }
    const body = el('div.stack');
    const sumLine = el('div.fs-sm.c-secondary');

    items.forEach((it, i) => {
      const scoreCtl = input({
        type: 'number',
        value: it.graded ? String(it.quiz_score ?? '') : '',
        placeholder: '0',
        class: 'input-sm',
      });
      scoreCtl.min = '0';
      if (fullScore > 0) scoreCtl.max = String(fullScore);
      scoreCtl.style.width = '96px';

      const commentCtl = textarea({
        name: `comment_${it.paper_id}`,
        value: it.quiz_comment || '',
        placeholder: '评语（选填，最多 500 字）',
        rows: 2,
      });
      inputs.set(Number(it.paper_id), { scoreCtl, commentCtl });

      const answer = String(it.stu_key ?? '');
      body.append(card({
        title: `第 ${i + 1} 题 · ${TYPE_LABELS[it.quiz_class] || it.quiz_class}`,
        iconName: 'edit-3',
        body: el('div.stack', {}, [
          el('div.pre-wrap.fw-500', { text: it.quiz_title || '（题干缺失）' }),
          it.quiz_pic_name ? el('div.fs-sm.c-secondary', { text: '（该题含图片，请在题库中查看原图）' }) : null,
          el('div.fs-sm', {}, [
            el('span.c-secondary', { text: '参考答案：' }),
            el('span', { text: String(it.quiz_key ?? '') || '（未设置参考答案）' }),
          ]),
          el('div.fs-sm', {}, [
            el('span.c-secondary', { text: '考生作答：' }),
            answer === ''
              ? el('span.c-warning', { text: '（未作答）' })
              : el('span.pre-wrap', { text: answer }),
          ]),
          el('div.flex.gap-4.items-end', {}, [
            field('本题得分', scoreCtl, {
              hint: fullScore > 0 ? `0 – ${fullScore} 分` : '该场未设置问答题分值',
            }),
          ]),
          field('评语', commentCtl),
        ].filter(Boolean)),
      }));
    });

    const recalc = () => {
      let got = 0;
      let filled = 0;
      for (const { scoreCtl } of inputs.values()) {
        const v = Number(scoreCtl.value);
        if (scoreCtl.value !== '' && Number.isFinite(v)) { got += v; filled++; }
      }
      sumLine.textContent = `当前填写合计 ${got} 分（${filled}/${items.length} 题）`
        + (fullScore > 0 ? `，问答题满分 ${fullScore * items.length} 分` : '');
    };
    body.addEventListener('input', recalc);
    recalc();

    const closeBtn = button('关闭', { variant: 'secondary', onClick: () => modal.close() });
    const revokeBtn = button('撤销批阅', {
      variant: 'ghost',
      onClick: async () => {
        const yes = await confirmDialog('确定撤销该考生的全部主观题批阅吗？', {
          detail: '撤销后所有问答题得分与评语将被清空，总分回落为客观题得分。',
          confirmText: '撤销批阅',
        });
        if (!yes) return;
        const r = await teacherApi.subjectiveRevoke(state.examId, row.stu_id);
        if (r.ok) {
          notify.success(`已撤销，当前总分 ${r.result?.breakdown?.score ?? 0} 分`);
          modal.close();
          loadOverview();
        } else {
          notify.error(r.error?.message || '撤销失败');
        }
      },
    });
    const saveBtn = button('保存批阅', {
      variant: 'primary',
      iconName: 'check',
      onClick: async (e) => {
        const payload = [];
        for (const [paperId, { scoreCtl, commentCtl }] of inputs) {
          const raw = scoreCtl.value.trim();
          payload.push({
            paper_id: paperId,
            score: raw === '' ? 0 : Math.max(0, Number(raw) || 0),
            comment: commentCtl.value.trim(),
          });
        }
        const r = await withLoading(e.currentTarget, () => teacherApi.subjectiveGrade(state.examId, row.stu_id, { items: payload }));
        if (r.ok) {
          const b = r.result?.breakdown || {};
          notify.success(`已保存，总分 ${b.score ?? 0} 分（客观题 ${b.objective ?? 0} + 主观题 ${b.subjective ?? 0}）`);
          modal.close();
          loadOverview();
        } else {
          notify.error(r.error?.message || '保存失败');
        }
      },
    });

    const modal = openModal({
      title: `批阅 · ${row.stu_name || row.stu_id}`,
      size: 'lg',
      closable: false,
      body: el('div.stack', {}, [sumLine, body]),
      footer: [revokeBtn, el('div.flex-1'), closeBtn, saveBtn],
    });
  }

  load();
  return root;
}
