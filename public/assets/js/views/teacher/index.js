/**
 * 教师端视图：考试管理 / 监考 / 成绩导出。
 * 教师只可见与操作「自己监考」的考试（后端按 tea_name 过滤）。
 */

import { el, clear, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, card, badge, table, field, input, notify, emptyStated,
  segmented, pagination, alertBox, openModal, statCard, descList,
  copyWithToast,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { teacherApi } from '../../api/index.js';
import { openExamEditor, deleteExam, openExamStudents } from './exam-editor.js';
import { fmtDateTime, fmtScore, fmtNumber } from '../../core/format.js';
import { loadAppSettings, appSettingInt, entryWindowText } from '../../core/app-settings.js';

const EXAM_STATUS = {
  testing: { label: '考试中', tone: 'success' },
  exam:    { label: '已编排', tone: 'brand' },
  paper:   { label: '已组卷', tone: 'info' },
  over:    { label: '已结束', tone: '' },
  overBak: { label: '已结束', tone: '' },
};

export function examStatusBadge(s) {
  const m = EXAM_STATUS[s] || { label: s || '未知', tone: '' };
  return badge(m.label, { tone: m.tone, dot: true });
}

const STU_STATUS = {
  waiting: { label: '待考', tone: '' },
  online:  { label: '答题中', tone: 'success' },
  locked:  { label: '已锁定', tone: 'warning' },
  over:    { label: '已交卷', tone: 'info' },
};

function stuStatusBadge(s) {
  const key = String(s || 'waiting');
  const m = STU_STATUS[key] || (key.startsWith('over') ? STU_STATUS.over : { label: key, tone: '' });
  return badge(m.label, { tone: m.tone, dot: true });
}

/* ============================ 考试管理 ============================ */
export function TeacherExamsView({ router }) {
  const root = el('div.stack');
  const toolbarSlot = el('div');
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '200px' } });
  const pageSlot = el('div');
  // 入场窗口等文案随后台设置变化，异步取回即可（未就绪时用默认值渲染）
  void loadAppSettings();

  const state = { page: 1, per_page: 20, keyword: '', list: [], total: 0, options: {} };

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [el('h1.page-title', { text: '考试管理' }), el('p.page-sub', { text: '你负责监考的考试场次' })]),
      el('div.page-head-actions', {}, [
        button('新增考试', {
          variant: 'primary', size: 'sm', iconName: 'plus',
          onClick: () => openExamEditor({ id: null, options: state.options, onSaved: () => load() }),
        }),
        button('刷新', { variant: 'secondary', size: 'sm', iconName: 'refresh-cw', onClick: () => load() }),
      ]),
    ]),
    toolbarSlot, tableSlot, pageSlot,
  );

  function renderToolbar() {
    clear(toolbarSlot);
    const kw = input({ placeholder: '搜索考试名称…', value: state.keyword, name: 'keyword' });
    kw.addEventListener('keydown', (e) => { if (e.key === 'Enter') { state.keyword = kw.value.trim(); state.page = 1; load(); } });
    toolbarSlot.append(el('div.toolbar', {}, [
      el('div.toolbar-left', {}, [kw]),
      el('div.toolbar-right', {}, [
        button('搜索', { variant: 'secondary', size: 'sm', iconName: 'search', onClick: () => { state.keyword = kw.value.trim(); state.page = 1; load(); } }),
        button('重置', { variant: 'ghost', size: 'sm', onClick: () => { state.keyword = ''; kw.value = ''; state.page = 1; load(); } }),
      ]),
    ]));
  }

  async function load() {
    const res = await withLoading(tableSlot, () => teacherApi.exams({ page: state.page, per_page: state.per_page, keyword: state.keyword }));
    if (!res.ok) return;
    state.list = res.result.list || [];
    state.total = res.result.total || 0;
    state.options = res.result.options || {};
    renderTable();
    renderPager();
  }

  function renderTable() {
    const t = table({
      columns: [
        { key: 'exam_name', title: '考试名称', render: (r) => el('div', {}, [
          el('strong', { text: r.exam_name }),
          el('div.muted.small', { text: r.subj_name || '' }),
        ]) },
        { key: 'exam_status', title: '状态', render: (r) => examStatusBadge(r.exam_status) },
        { key: 'exam_start', title: '开始', render: (r) => el('span.muted', { text: r.exam_start ? fmtDateTime(r.exam_start) : '—' }) },
        { key: 'exam_end', title: '结束', render: (r) => el('span.muted', { text: r.exam_end ? fmtDateTime(r.exam_end) : '—' }) },
        { key: 'exam_score', title: '总分', align: 'right', render: (r) => el('span', { text: fmtScore(r.exam_score) }) },
        { key: '_acts', title: '操作', align: 'right', render: (r) => {
          const st = String(r.exam_status || '');
          const notTesting = st === 'exam' || st === 'paper';
          const pwdReady = !!(r.exam_pwd && String(r.exam_pwd) !== '0');
          return el('div.row.gap-xs.end', {}, [
            // 教师端注册的路由是 /monitor（不是 /teacher/monitor），
          // 此前写死 /teacher/monitor → 点击落到「页面不存在」。
          button('监考', { variant: 'secondary', size: 'xs', iconName: 'eye', onClick: () => router.navigate(`/monitor?exam_id=${r.id}`) }),
            notTesting
              ? button(pwdReady ? '重置口令' : '开放入场', {
                  variant: pwdReady ? 'ghost' : 'secondary', size: 'xs', iconName: 'login',
                  onClick: () => openForEntry(r),
                })
              : null,
            st === 'exam'
              ? button('出题', { variant: 'secondary', size: 'xs', iconName: 'sparkles', onClick: () => generatePapers(r) })
              : null,
            notTesting
              ? button('开考', { variant: 'primary', size: 'xs', iconName: 'play', onClick: () => startExam(r) })
              : null,
            button('考生', { variant: 'ghost', size: 'xs', iconName: 'users', onClick: () => openExamStudents(r) }),
            notTesting
              ? button('编辑', {
                  variant: 'ghost', size: 'xs', iconName: 'edit',
                  onClick: () => openExamEditor({ id: r.id, options: state.options, onSaved: () => load() }),
                })
              : null,
            notTesting
              ? button('删除', { variant: 'danger', size: 'xs', iconName: 'trash', onClick: () => deleteExam(r, () => load()) })
              : null,
            button('详情', { variant: 'ghost', size: 'xs', iconName: 'info', onClick: () => showDetail(r.id) }),
          ]);
        } },
      ],
      rows: state.list,
      emptyText: '暂无你负责的考试',
    });
    mount(tableSlot, t);
  }

  async function startExam(r) {
    const ok = await new Promise((resolve) => {
      openModal({
        title: '确认开考',
        size: 'sm',
        body: el('p', { text: `确定立即开始「${r.exam_name}」吗？开考后考生将无法再进入考场。` }),
        footer: el('div.row.gap-sm', {}, [
          button('取消', { variant: 'secondary', onClick: () => resolve(false) }),
          button('立即开考', { variant: 'primary', onClick: () => resolve(true) }),
        ]),
      });
    });
    if (!ok) return;

    const res = await withLoading(tableSlot, () => teacherApi.startExam(r.id));
    if (!res.ok) return;
    const pwd = res.result?.exam_pwd || res.result?.pwd || '';
    openModal({
      title: '考试已开始',
      size: 'sm',
      body: el('div.stack', {}, [
        el('p', { text: `「${r.exam_name}」已开考，请将考场口令告知考生：` }),
        el('div', {
          style: {
            textAlign: 'center', padding: 'var(--sp-6)',
            background: 'var(--bg-sunken)', border: '1px solid var(--border-subtle)',
            borderRadius: 'var(--radius-md)',
          },
        }, [
          el('div.fs-sm.c-secondary.mb-2', { text: '考场口令' }),
          el('div.mono.fw-700', {
            style: { fontSize: 'var(--fs-4xl)', letterSpacing: '.12em', color: 'var(--brand-600)' },
            text: String(pwd || '—'),
          }),
        ]),
      ]),
      footer: button('知道了', { variant: 'primary', onClick: () => document.body.querySelector('.modal-backdrop')?.click() }),
    });
    load();
  }

  /** 开放入场：生成考场口令，状态保持未开考 */
  async function openForEntry(r) {
    const pwdReady = !!(r.exam_pwd && String(r.exam_pwd) !== '0');
    if (pwdReady) {
      const ok = await new Promise((resolve) => {
        openModal({
          title: '重新生成口令',
          size: 'sm',
          body: el('p', { text: '重新生成后原口令立即失效，已进入考场的考生需重新输入新口令。确定继续？' }),
          footer: el('div.row.gap-sm', {}, [
            button('取消', { variant: 'secondary', onClick: () => resolve(false) }),
            button('重新生成', { variant: 'warning', onClick: () => resolve(true) }),
          ]),
        });
      });
      if (!ok) return;
    }
    const res = await withLoading(tableSlot, () => teacherApi.openExam(r.id));
    if (!res.ok) return;
    const pwd = res.result?.exam_pwd || res.result?.pwd || '';
    openModal({
      title: '已开放入场',
      size: 'sm',
      body: el('div.stack', {}, [
        alertBox(`「${r.exam_name}」已开放入场，请将考场口令告知考生。考生${entryWindowText()}。`, { type: 'success' }),
        el('div', {
          style: {
            textAlign: 'center', padding: 'var(--sp-6)',
            background: 'var(--bg-sunken)', border: '1px solid var(--border-subtle)',
            borderRadius: 'var(--radius-md)',
          },
        }, [
          el('div.fs-sm.c-secondary.mb-2', { text: '考场口令' }),
          el('div.mono.fw-700', {
            style: { fontSize: 'var(--fs-4xl)', letterSpacing: '.12em', color: 'var(--brand-600)' },
            text: String(pwd || '—'),
          }),
        ]),
        button('复制口令', { variant: 'secondary', iconName: 'copy', block: true,
          onClick: () => copyWithToast(pwd, '考场口令') }),
      ]),
      footer: button('知道了', { variant: 'primary', onClick: () => document.body.querySelector('.modal-backdrop')?.click() }),
    });
    load();
  }

  /** 出题：为参考班级每位考生随机生成一套试卷 */
  async function generatePapers(r) {
    const ok = await new Promise((resolve) => {
      openModal({
        title: '开始出题',
        size: 'sm',
        body: el('p', { text: `将为本场考试每位考生随机生成一套试卷（已生成的不会被覆盖）。确定开始出题？` }),
        footer: el('div.row.gap-sm', {}, [
          button('取消', { variant: 'secondary', onClick: () => resolve(false) }),
          button('开始出题', { variant: 'primary', onClick: () => resolve(true) }),
        ]),
      });
    });
    if (!ok) return;

    const res = await withLoading(tableSlot, () => teacherApi.generatePapers(r.id, {}));
    if (!res.ok) return;
    const d = res.result || {};
    notify.success(`出题完成：生成 ${d.generated ?? 0} 份${d.skipped ? `，跳过 ${d.skipped} 份` : ''}`);
    if (Array.isArray(d.warnings) && d.warnings.length) {
      notify.warning(d.warnings.map((w) => `${w.label || w.type}${w.diff_label || ''} 需 ${w.need} 题、库存 ${w.have}`).join('；'));
    }
    load();
  }

  async function showDetail(id) {
    const res = await withLoading(root, () => teacherApi.exam(id));
    if (!res.ok) return;
    const d = res.result || {};
    openModal({
      title: d.exam_name || '考试详情',
      size: 'lg',
      body: el('div.stack', {}, [
        descList([
          ['考试编号', String(d.id ?? '')],
          ['科目', d.subj_name || '—'],
          ['状态', (EXAM_STATUS[d.exam_status] || {}).label || d.exam_status || '—'],
          ['开始时间', d.exam_start ? fmtDateTime(d.exam_start) : '—'],
          ['结束时间', d.exam_end ? fmtDateTime(d.exam_end) : '—'],
          ['总分', fmtScore(d.exam_score)],
        ]),
      ]),
    });
  }

  function renderPager() {
    clear(pageSlot);
    const totalPages = Math.ceil(state.total / state.per_page) || 0;
    if (totalPages <= 1) return;
    pageSlot.append(pagination({
      page: state.page, total: state.total, per_page: state.per_page,
      onPage: (p) => { state.page = p; load(); },
    }));
  }

  renderToolbar();
  load();
  return root;
}

/* ============================ 监考 ============================ */
export function TeacherMonitorView({ router, query }) {
  const root = el('div.stack');
  const pickerSlot = el('div');
  const statsSlot = el('div.grid-stats');
  const controlSlot = el('div');
  const toolbarSlot = el('div');
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '220px' } });

  const state = { examId: Number(query?.exam_id || 0), exams: [], list: [], summary: {}, exam: null, timer: null };

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [el('h1.page-title', { text: '监考中心' }), el('p.page-sub', { text: '实时掌握考场状态，可开放入场、出题、开考与锁定' })]),
      el('div.page-head-actions', {}, [
        button('刷新', { variant: 'secondary', size: 'sm', iconName: 'refresh-cw', onClick: () => init() }),
      ]),
    ]),
    pickerSlot, statsSlot, controlSlot, toolbarSlot, tableSlot,
  );

  // 并发保护：轮询 tick 与「锁定/交卷/结束考试」后的手动刷新可能同时触发，
  // 多个请求并行时后返回的响应会覆盖较新的数据，导致名单闪烁 / 回退到旧数据。
  // 注意只拦外部触发，load() 内部的一次自我递归不受影响。
  let inflight = false;
  async function init() {
    if (inflight) return;
    inflight = true;
    try {
      await load();
    } finally {
      inflight = false;
    }
  }

  async function load() {
    // 自动刷新间隔来自后台设置；取回后再建立轮询
    await loadAppSettings();
    let res = await withLoading(tableSlot, () => teacherApi.monitor(state.examId ? { exam_id: state.examId } : {}));
    if (!res.ok) return;
    if (!state.examId) {
      state.exams = res.result.exams || [];
      renderPicker();
      if (!state.exams.length) {
        clear(statsSlot); clear(controlSlot); clear(toolbarSlot);
        mount(tableSlot, emptyStated('暂无需要监考的考试', { iconName: 'shield' }));
        return;
      }
      // 默认选中第一场监考，并在同一次调用内把详情取回来。
      // 注意：这里不能写 `return init()` —— init() 的并发保护此刻仍持有 inflight，
      // 递归进去会被 `if (inflight) return` 直接拦掉，导致统计卡 / 考场控制 / 考生名单
      // 全部不渲染，点「监考中心」只看到一个空壳（无 exam_id 进入时必然触发）。
      state.examId = state.exams[0].id;
      res = await withLoading(tableSlot, () => teacherApi.monitor({ exam_id: state.examId }));
      if (!res.ok) return;
    }
    state.exams = res.result.exams || state.exams;
    state.list = res.result.list || [];
    state.summary = res.result.summary || {};
    state.exam = res.result.exam || null;
    if (state.examId && !state.exams.some((e) => e.id === state.examId) && state.exam) {
      state.exams = [state.exam, ...state.exams];
    }
    renderPicker();
    renderStats();
    renderControl();
    renderToolbar();
    renderTable();
  }

  /* ---------- 考场控制：开放入场 / 出题 / 开考 ---------- */
  function renderControl() {
    clear(controlSlot);
    const exam = state.exam;
    if (!exam) return;

    const status = String(exam.exam_status || '');
    const pwd = String(exam.exam_pwd || '') === '0' ? '' : String(exam.exam_pwd || '');
    const pwdReady = pwd !== '';
    const over = status.startsWith('over');
    const notTesting = !over && status !== 'testing';

    const actions = [];
    if (notTesting) {
      actions.push(button(pwdReady ? '重新生成口令' : '开放入场', {
        variant: pwdReady ? 'secondary' : 'primary', size: 'sm', iconName: 'login',
        onClick: () => openForEntry(),
      }));
      if (status === 'exam') {
        actions.push(button('开始出题', { variant: 'primary', size: 'sm', iconName: 'sparkles', onClick: () => generatePapers() }));
      }
      actions.push(button('开考', { variant: 'primary', size: 'sm', iconName: 'play', onClick: () => startExam() }));
    }

    const hint = over ? '考试已结束，不再接受入场'
      : status === 'testing' ? '考试进行中，考生正在作答'
      : status === 'paper' ? '已出题完毕，等待开考（到达开考时间将自动开考）'
      : pwdReady ? '入场已开放，等待监考出题'
      : '尚未开放入场，考生暂时无法进入考场';

    const body = el('div.stack', {}, [
      el('div.flex.items-center.gap-3.flex-wrap', {}, [
        examStatusBadge(status),
        el('span.fs-sm.c-secondary', { text: hint }),
      ]),
      pwdReady ? el('div.flex.items-center.gap-4.flex-wrap', {
        style: {
          padding: 'var(--sp-3) var(--sp-4)', background: 'var(--bg-sunken)',
          border: '1px solid var(--border-subtle)', borderRadius: 'var(--radius-md)',
        },
      }, [
        el('div', {}, [
          el('div.fs-xs.c-tertiary', { text: '考场口令' }),
          el('div.mono.fw-700', {
            style: { fontSize: 'var(--fs-2xl)', letterSpacing: '.12em', color: 'var(--brand-600)' },
            text: pwd,
          }),
        ]),
        button('复制', { variant: 'ghost', size: 'sm', iconName: 'copy', onClick: () => copyWithToast(pwd, '考场口令') }),
      ]) : null,
      el('div.fs-xs.c-tertiary', { text: `流程：开放入场 → 告知考生口令 → 考生${entryWindowText()} → 开始出题 → 到点自动开考或手动开考` }),
    ].filter(Boolean));

    controlSlot.append(card({ iconName: 'shield', title: '考场控制', actions, body }));
  }

  async function openForEntry() {
    const pwdReady = !!state.exam?.exam_pwd && String(state.exam.exam_pwd) !== '0';
    if (pwdReady) {
      const ok = await new Promise((resolve) => {
        openModal({
          title: '重新生成口令',
          size: 'sm',
          body: el('p', { text: '重新生成后原口令立即失效，已进入考场的考生需重新输入新口令。确定继续？' }),
          footer: el('div.row.gap-sm', {}, [
            button('取消', { variant: 'secondary', onClick: () => resolve(false) }),
            button('重新生成', { variant: 'warning', onClick: () => resolve(true) }),
          ]),
        });
      });
      if (!ok) return;
    }
    const res = await withLoading(controlSlot, () => teacherApi.openExam(state.examId));
    if (!res.ok) return;
    const pwd = res.result?.exam_pwd || res.result?.pwd || '';
    showPwdModal('已开放入场', `「${state.exam?.exam_name || ''}」已开放入场，请将考场口令告知考生。考生${entryWindowText()}。`, pwd, () => init());
  }

  async function generatePapers() {
    const ok = await new Promise((resolve) => {
      openModal({
        title: '开始出题',
        size: 'sm',
        body: el('p', { text: '将为本场考试每位考生随机生成一套试卷（已生成的不会被覆盖）。确定开始出题？' }),
        footer: el('div.row.gap-sm', {}, [
          button('取消', { variant: 'secondary', onClick: () => resolve(false) }),
          button('开始出题', { variant: 'primary', onClick: () => resolve(true) }),
        ]),
      });
    });
    if (!ok) return;
    const res = await withLoading(controlSlot, () => teacherApi.generatePapers(state.examId, {}));
    if (!res.ok) return;
    const d = res.result || {};
    notify.success(`出题完成：生成 ${d.generated ?? 0} 份${d.skipped ? `，跳过 ${d.skipped} 份` : ''}`);
    if (Array.isArray(d.warnings) && d.warnings.length) {
      notify.warning(d.warnings.map((w) => `${w.label || w.type}${w.diff_label || ''} 需 ${w.need} 题、库存 ${w.have}`).join('；'));
    }
    init();
  }

  async function startExam() {
    const ok = await new Promise((resolve) => {
      // 持有返回值并在按钮里关闭，否则遮罩永久停留、页面无法滚动
      const dlg = openModal({
        title: '确认开考',
        size: 'sm',
        body: el('p', { text: '确定立即开考吗？开考后考生将无法再入场，且立即进入答题界面。' }),
        footer: el('div.row.gap-sm', {}, [
          button('取消', { variant: 'secondary', onClick: () => { dlg.close(); resolve(false); } }),
          button('立即开考', { variant: 'primary', onClick: () => { dlg.close(); resolve(true); } }),
        ]),
        onClose: () => resolve(false),
      });
    });
    if (!ok) return;
    const res = await withLoading(controlSlot, () => teacherApi.startExam(state.examId));
    if (!res.ok) return;
    const pwd = res.result?.exam_pwd || res.result?.pwd || '';
    showPwdModal('考试已开始', `「${state.exam?.exam_name || ''}」已开考，请将考场口令告知考生：`, pwd, () => init());
  }

  function showPwdModal(title, message, pwd, after) {
    const dlg = openModal({
      title,
      size: 'sm',
      body: el('div.stack', {}, [
        alertBox(message, { type: 'success' }),
        el('div', {
          style: {
            textAlign: 'center', padding: 'var(--sp-6)',
            background: 'var(--bg-sunken)', border: '1px solid var(--border-subtle)',
            borderRadius: 'var(--radius-md)',
          },
        }, [
          el('div.fs-sm.c-secondary.mb-2', { text: '考场口令' }),
          el('div.mono.fw-700', {
            style: { fontSize: 'var(--fs-4xl)', letterSpacing: '.12em', color: 'var(--brand-600)' },
            text: String(pwd || '—'),
          }),
        ]),
        button('复制口令', { variant: 'secondary', iconName: 'copy', block: true,
          onClick: () => copyWithToast(pwd, '考场口令') }),
      ]),
      // 此前用 document.body.querySelector('.modal-backdrop')?.click() 关闭：
      // 遮罩监听的是 mousedown，.click() 只派发 click 事件，弹窗根本关不掉。
      footer: button('知道了', { variant: 'primary', onClick: () => { dlg.close(); after?.(); } }),
    });
  }

  function renderPicker() {
    clear(pickerSlot);
    if (!state.exams.length) return;
    pickerSlot.append(card({
      iconName: 'calendar',
      body: segmented(
        state.exams.map((e) => ({ key: String(e.id), label: e.exam_name })),
        String(state.examId),
        (k) => { state.examId = Number(k); init(); },
      ),
    }));
  }

  function renderStats() {
    const s = state.summary || {};
    mount(statsSlot, [
      statCard({ label: '应考人数', value: fmtNumber(s.total ?? state.list.length), iconName: 'users' }),
      statCard({ label: '答题中', value: fmtNumber(s.online ?? 0), iconName: 'activity', tone: 'success' }),
      statCard({ label: '已交卷', value: fmtNumber(s.over ?? 0), iconName: 'check-circle', tone: 'brand' }),
      statCard({ label: '已锁定', value: fmtNumber(s.locked ?? 0), iconName: 'lock', tone: 'warning' }),
    ]);
  }

  function renderToolbar() {
    clear(toolbarSlot);
    toolbarSlot.append(el('div.toolbar', {}, [
      // 刷新间隔来自后台设置（monitor_refresh_seconds），此前文案写死 10 秒，改配置后会撒谎
      el('div.toolbar-left', {}, [el('span.muted', { text: `${state.list.length} 名考生 · 每 ${appSettingInt('monitor_refresh_seconds', 10)} 秒自动刷新` })]),
      el('div.toolbar-right', {}, [
        button('全部锁定', { variant: 'secondary', size: 'sm', iconName: 'lock', onClick: () => bulk('lockAll') }),
        button('全部解锁', { variant: 'secondary', size: 'sm', iconName: 'unlock', onClick: () => bulk('unlockAll') }),
        button('全员交卷', { variant: 'warning', size: 'sm', iconName: 'send', onClick: () => bulk('submitAll') }),
        button('结束考试', { variant: 'danger', size: 'sm', iconName: 'stop', onClick: () => bulk('overAll', true) }),
      ]),
    ]));
  }

  async function bulk(action, danger = false) {
    if (danger) {
      const ok = await new Promise((resolve) => {
        // 持有返回值并在按钮里关闭，否则遮罩永久停留、页面无法滚动
        const dlg = openModal({
          title: '确认结束考试',
          size: 'sm',
          body: el('p', { text: '结束整场考试后考生将无法继续作答，且会立即判分。确认继续？' }),
          footer: el('div.row.gap-sm', {}, [
            button('取消', { variant: 'secondary', onClick: () => { dlg.close(); resolve(false); } }),
            button('确认结束', { variant: 'danger', onClick: () => { dlg.close(); resolve(true); } }),
          ]),
          onClose: () => resolve(false),
        });
      });
      if (!ok) return;
    }
    const res = await withLoading(toolbarSlot, () => teacherApi[action]({ exam_id: state.examId }));
    if (res.ok) notify.success(res.result?.message || '操作成功');
    init();
  }

  function renderTable() {
    const t = table({
      columns: [
        { key: 'stu_id', title: '准考证号' },
        { key: 'stu_name', title: '姓名' },
        { key: 'stu_sex', title: '性别', render: (r) => el('span.muted', { text: r.stu_sex || '—' }) },
        { key: 'grade_id', title: '单位', render: (r) => el('span.muted', { text: r.grade_id || '—' }) },
        { key: 'class_id', title: '班级', render: (r) => el('span.muted', { text: r.class_id || '—' }) },
        { key: 'stu_status', title: '状态', render: (r) => stuStatusBadge(r.stu_status) },
        { key: 'stu_score', title: '得分', align: 'right', render: (r) => el('strong', { text: fmtScore(r.stu_score) }) },
        { key: '_acts', title: '操作', align: 'right', render: (r) => {
          // 已交卷考生不再提供锁定/解锁/交卷：后端 updateStatus 会拒绝从 over 迁出，
          // 而接口仍回「已锁定」，形成「提示成功、实际没变」的假反馈。
          if (r.stu_status === 'over') return el('span.fs-xs.c-tertiary', { text: '已结束' });
          return el('div.row.gap-xs.end', {}, [
            r.stu_status === 'locked'
              ? button('解锁', { variant: 'secondary', size: 'xs', iconName: 'unlock', onClick: () => rowAction('unlock', r) })
              : button('锁定', { variant: 'secondary', size: 'xs', iconName: 'lock', onClick: () => rowAction('lock', r) }),
            button('交卷', { variant: 'ghost', size: 'xs', iconName: 'send', onClick: () => rowAction('submitOne', r) }),
          ]);
        } },
      ],
      rows: state.list,
      emptyText: '本场考试暂无考生',
    });
    mount(tableSlot, t);
  }

  /**
   * 行内单人操作。
   *
   * 收卷必须走 submitOne（单个考生）而非 submit（全员）——后者会让监考员
   * 「想收 1 人却把全场判了分」，且判分不可撤销，属数据事故。
   */
  async function rowAction(action, r) {
    if (action === 'submitOne') {
      const sure = await new Promise((resolve) => {
        // 持有返回值并在按钮里关闭，否则遮罩永久停留、页面无法滚动
        const dlg = openModal({
          title: '确认收卷',
          size: 'sm',
          body: el('p', { text: `确定对考生「${r.stu_name || r.stu_id}」强制收卷并判分吗？收卷后该考生不能再作答，且成绩即被封存。` }),
          footer: el('div.row.gap-sm', {}, [
            button('取消', { variant: 'secondary', onClick: () => { dlg.close(); resolve(false); } }),
            button('确认收卷', { variant: 'danger', onClick: () => { dlg.close(); resolve(true); } }),
          ]),
          onClose: () => resolve(false),
        });
      });
      if (!sure) return;
    }
    const res = await withLoading(tableSlot, () => teacherApi[action]({ exam_id: state.examId, stu_id: r.stu_id }));
    if (res.ok) notify.success(res.result?.message || '操作成功');
    init();
  }

  init();
  state.timer = setInterval(() => { if (state.examId) init(); }, appSettingInt('monitor_refresh_seconds', 10) * 1000);

  return { node: root, dispose: () => clearInterval(state.timer) };
}

/* ============================ 成绩 ============================ */
export function TeacherScoresView({ router, query }) {
  const root = el('div.stack');
  const pickerSlot = el('div');
  const statsSlot = el('div.grid-stats');
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '200px' } });
  const state = { examId: Number(query?.exam_id || 0), exams: [], list: [], exam: null };

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [el('h1.page-title', { text: '成绩查询' }), el('p.page-sub', { text: '查看已结束考试的成绩并可导出' })]),
      el('div.page-head-actions', {}, [
        button('导出 CSV', { variant: 'secondary', size: 'sm', iconName: 'download', onClick: () => exportCsv() }),
      ]),
    ]),
    pickerSlot, statsSlot, tableSlot,
  );

  async function load() {
    const res = await withLoading(tableSlot, () => teacherApi.scores(state.examId ? { exam_id: state.examId } : {}));
    if (!res.ok) {
      // 此前直接 return，页面只剩空白，用户无从判断发生了什么（表现为"点开就是白板"）。
      mount(tableSlot, alertBox(res.error?.message || '成绩加载失败，请稍后重试', { type: 'danger' }));
      return;
    }
    state.exams = res.result.exams || [];
    state.list = res.result.list || [];
    state.exam = res.result.exam || null;

    // 名下没有已结束的考试时给出明确空态，而不是留一片空白
    if (!state.exams.length) {
      mount(tableSlot, emptyStated('暂无已结束的考试', {
        iconName: 'calendar',
        desc: '只有由您监考的考试结束后，成绩才会出现在这里',
      }));
      return;
    }

    if (!state.examId && state.exams.length) {
      state.examId = state.exams[0].id;
      return load();
    }
    renderPicker();
    renderStats();
    renderTable();
  }

  function renderPicker() {
    clear(pickerSlot);
    if (!state.exams.length) return;
    pickerSlot.append(card({
      iconName: 'calendar',
      body: segmented(
        state.exams.slice(0, 12).map((e) => ({ key: String(e.id), label: e.exam_name })),
        String(state.examId),
        (k) => { state.examId = Number(k); load(); },
      ),
    }));
  }

  function renderStats() {
    const scores = state.list.map((r) => Number(r.stu_score) || 0).filter((v) => v >= 0);
    const n = scores.length;
    const avg = n ? (scores.reduce((a, b) => a + b, 0) / n) : 0;
    const max = n ? Math.max(...scores) : 0;
    const min = n ? Math.min(...scores) : 0;
    mount(statsSlot, [
      statCard({ label: '参考人数', value: fmtNumber(n), iconName: 'users' }),
      statCard({ label: '平均分', value: fmtScore(avg), iconName: 'trending-up', tone: 'brand' }),
      statCard({ label: '最高分', value: fmtScore(max), iconName: 'award', tone: 'success' }),
      statCard({ label: '最低分', value: fmtScore(min), iconName: 'trending-down', tone: 'warning' }),
    ]);
  }

  function renderTable() {
    const sorted = [...state.list].sort((a, b) => (Number(b.stu_score) || 0) - (Number(a.stu_score) || 0));
    const total = Number(state.exam?.exam_score) || 0;
    const t = table({
      columns: [
        { key: '_rank', title: '排名', render: (r) => el('span.rank-badge', { text: String(sorted.indexOf(r) + 1) }) },
        { key: 'stu_id', title: '准考证号' },
        { key: 'stu_name', title: '姓名' },
        { key: 'grade_id', title: '单位', render: (r) => el('span.muted', { text: r.grade_id || '—' }) },
        { key: 'class_id', title: '班级', render: (r) => el('span.muted', { text: r.class_id || '—' }) },
        { key: 'stu_score', title: '得分', align: 'right', render: (r) => el('strong', { text: fmtScore(r.stu_score) }) },
        { key: '_rate', title: '得分率', align: 'right', render: (r) => {
          const v = total > 0 ? (Number(r.stu_score) / total) * 100 : 0;
          return el('span.muted', { text: `${v.toFixed(1)}%` });
        } },
      ],
      rows: sorted,
      emptyText: '该考试暂无成绩',
    });
    mount(tableSlot, t);
  }

  async function exportCsv() {
    if (!state.examId) { notify.warning('请先选择考试'); return; }
    const res = await withLoading(tableSlot, () => teacherApi.exportScores({ exam_id: state.examId }));
    if (res.ok) notify.success('导出成功');
  }

  load();
  return root;
}
