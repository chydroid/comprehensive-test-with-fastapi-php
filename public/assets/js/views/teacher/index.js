/**
 * 教师端视图：考试管理 / 监考 / 成绩导出。
 * 教师只可见与操作「自己监考」的考试（后端按 tea_name 过滤）。
 */

import { el, clear, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, card, badge, table, field, input, notify, emptyStated,
  segmented, pagination, alertBox, openModal, statCard, descList,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { teacherApi } from '../../api/index.js';
import { openExamEditor, deleteExam, openExamStudents } from './exam-editor.js';
import { fmtDateTime, fmtScore, fmtNumber } from '../../core/format.js';

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
        { key: '_acts', title: '操作', align: 'right', render: (r) => el('div.row.gap-xs.end', {}, [
          button('监考', { variant: 'secondary', size: 'xs', iconName: 'eye', onClick: () => router.navigate(`/teacher/monitor?exam_id=${r.id}`) }),
          r.exam_status !== 'testing'
            ? button('开考', {
                variant: 'primary', size: 'xs', iconName: 'play',
                onClick: () => startExam(r),
              })
            : null,
          button('考生', { variant: 'ghost', size: 'xs', iconName: 'users', onClick: () => openExamStudents(r) }),
          r.exam_status === 'exam' || r.exam_status === 'paper'
            ? button('编辑', {
                variant: 'ghost', size: 'xs', iconName: 'edit',
                onClick: () => openExamEditor({ id: r.id, options: state.options, onSaved: () => load() }),
              })
            : null,
          r.exam_status === 'exam' || r.exam_status === 'paper'
            ? button('删除', { variant: 'danger', size: 'xs', iconName: 'trash', onClick: () => deleteExam(r, () => load()) })
            : null,
          button('详情', { variant: 'ghost', size: 'xs', iconName: 'info', onClick: () => showDetail(r.id) }),
        ]) },
      ],
      rows: state.list,
      emptyText: '暂无你负责的考试',
    });
    mount(tableSlot, t);
  }

  async function startExam(r) {
    const res = await withLoading(tableSlot, () => teacherApi.startExam(r.id));
    if (!res.ok) return;
    const pwd = res.result?.exam_pwd || res.result?.pwd || '';
    openModal({
      title: '考试已开始',
      size: 'sm',
      body: el('div.stack', {}, [
        el('p', { text: `「${r.exam_name}」已开考，请将考场口令告知考生：` }),
        el('div.pwd-display', { text: String(pwd || '—') }),
      ]),
      footer: button('知道了', { variant: 'primary', onClick: () => document.body.querySelector('.modal-backdrop')?.click() }),
    });
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
  const toolbarSlot = el('div');
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '220px' } });

  const state = { examId: Number(query?.exam_id || 0), exams: [], list: [], summary: {}, exam: null, timer: null };

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [el('h1.page-title', { text: '监考中心' }), el('p.page-sub', { text: '实时掌握考场状态，可锁定、强制交卷' })]),
      el('div.page-head-actions', {}, [
        button('刷新', { variant: 'secondary', size: 'sm', iconName: 'refresh-cw', onClick: () => init() }),
      ]),
    ]),
    pickerSlot, statsSlot, toolbarSlot, tableSlot,
  );

  async function init() {
    const res = await withLoading(tableSlot, () => teacherApi.monitor(state.examId ? { exam_id: state.examId } : {}));
    if (!res.ok) return;
    if (!state.examId) {
      state.exams = res.result.exams || [];
      renderPicker();
      if (state.exams.length) {
        state.examId = state.exams[0].id;
        return init();
      }
      clear(statsSlot); clear(toolbarSlot);
      mount(tableSlot, emptyStated('暂无需要监考的考试', { iconName: 'shield' }));
      return;
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
    renderToolbar();
    renderTable();
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
      el('div.toolbar-left', {}, [el('span.muted', { text: `${state.list.length} 名考生 · 每 10 秒自动刷新` })]),
      el('div.toolbar-right', {}, [
        button('全部锁定', { variant: 'secondary', size: 'sm', iconName: 'lock', onClick: () => bulk('lockAll') }),
        button('全部解锁', { variant: 'secondary', size: 'sm', iconName: 'unlock', onClick: () => bulk('unlockAll') }),
        button('全员交卷', { variant: 'warning', size: 'sm', iconName: 'send', onClick: () => bulk('submitAll') }),
        button('结束考试', { variant: 'danger', size: 'sm', iconName: 'power', onClick: () => bulk('overAll', true) }),
      ]),
    ]));
  }

  async function bulk(action, danger = false) {
    if (danger) {
      const ok = await new Promise((resolve) => {
        openModal({
          title: '确认结束考试',
          size: 'sm',
          body: el('p', { text: '结束整场考试后考生将无法继续作答，且会立即判分。确认继续？' }),
          footer: el('div.row.gap-sm', {}, [
            button('取消', { variant: 'secondary', onClick: () => resolve(false) }),
            button('确认结束', { variant: 'danger', onClick: () => resolve(true) }),
          ]),
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
        { key: '_acts', title: '操作', align: 'right', render: (r) => el('div.row.gap-xs.end', {}, [
          r.stu_status === 'locked'
            ? button('解锁', { variant: 'secondary', size: 'xs', iconName: 'unlock', onClick: () => rowAction('unlock', r) })
            : button('锁定', { variant: 'secondary', size: 'xs', iconName: 'lock', onClick: () => rowAction('lock', r) }),
          button('交卷', { variant: 'ghost', size: 'xs', iconName: 'send', onClick: () => rowAction('submit', r) }),
        ]) },
      ],
      rows: state.list,
      emptyText: '本场考试暂无考生',
    });
    mount(tableSlot, t);
  }

  async function rowAction(action, r) {
    const res = await withLoading(tableSlot, () => teacherApi[action]({ exam_id: state.examId, stu_id: r.stu_id }));
    if (res.ok) notify.success('操作成功');
    init();
  }

  init();
  state.timer = setInterval(() => { if (state.examId) init(); }, 10000);

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
    if (!res.ok) return;
    state.exams = res.result.exams || [];
    state.list = res.result.list || [];
    state.exam = res.result.exam || null;
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
