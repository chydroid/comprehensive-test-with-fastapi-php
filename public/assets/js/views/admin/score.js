/**
 * 管理后台 —— 成绩管理
 * 选择已结束考试 → 成绩明细 → 排序 / 备份 / 导出 CSV / 查看备份记录
 */

import { el, mount, clear } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, badge, card, openModal, confirmDialog, notify, alertBox,
  table, descList, emptyStated, segmented, skeletonRows, statCard,
} from '../../ui/components.js';
import { adminApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';
import { fmtDate, fmtDateTime, fmtScore, fmtNumber } from '../../core/format.js';
import { EXAM_STATUS, statusBadge } from './exam.js';

const ROSTER_STATUS = {
  waiting: { label: '待考', tone: 'warning' },
  online:  { label: '在线', tone: 'success' },
  locked:  { label: '已锁定', tone: 'danger' },
  over:    { label: '已交卷', tone: 'success' },
};

export function ScoreView({ router, query }) {
  const root = el('div.stack');
  const headSlot = el('div.page-head');
  const pickerSlot = el('div');
  const statsSlot = el('div.grid-stats');
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '200px' } });

  root.append(headSlot, pickerSlot, statsSlot, tableSlot);

  let examId = Number(query?.exam_id) || 0;
  let exams = [];
  let list = [];
  let currentExam = null;
  let canBackup = true;
  let orderBy = 'stuid';
  let order = 'asc';

  async function init() {
    mount(tableSlot, skeletonRows(6, 5));
    try {
      const data = await adminApi.scores(examId ? { exam_id: examId, orderby: orderBy, order } : {});
      exams = data?.exams || [];
      list = data?.list || [];
      currentExam = data?.exam || null;
      canBackup = data?.can_backup ?? true;
      examId = Number(data?.exam_id || examId) || 0;
      render();
    } catch (e) {
      mount(tableSlot, el('div.alert.alert-danger', { text: e?.message || '成绩加载失败' }));
    }
  }

  function render() {
    renderHead();
    renderPicker();
    renderStats();
    renderTable();
  }

  function renderHead() {
    clear(headSlot);
    headSlot.append(
      el('div', {}, [
        el('h2.page-title', { text: '成绩管理' }),
        el('div.page-desc', {
          text: currentExam
            ? `${currentExam.exam_name} · 满分 ${fmtScore(currentExam.exam_score)} 分`
            : '选择一场已结束的考试以查看成绩明细',
        }),
      ]),
      el('div.page-actions', {}, [
        examId ? button('导出 CSV', { variant: 'secondary', iconName: 'download',
          onClick: (e) => withLoading(e.currentTarget, async () => {
            const name = await adminApi.exportScores({ exam_id: examId });
            notify.success(`已导出 ${name}`);
          }, { silent: true }) }) : null,
        examId ? button(canBackup ? '备份成绩' : '已备份', {
          variant: canBackup ? 'primary' : 'secondary',
          iconName: 'database', disabled: !canBackup,
          onClick: () => doBackup(),
        }) : null,
        button('备份记录', { variant: 'secondary', iconName: 'database', onClick: () => openBackups() }),
      ].filter(Boolean))
    );
  }

  function renderPicker() {
    clear(pickerSlot);
    if (!exams.length) {
      pickerSlot.append(alertBox('暂无已结束的考试。考试结束后才能查看与导出成绩。', { type: 'info' }));
      return;
    }
    const seg = segmented(
      [{ key: '0', label: '全部考试' }, ...exams.map((e) => ({ key: String(e.id), label: e.exam_name }))],
      String(examId),
        (k) => {
          examId = Number(k) || 0;
          const next = `/scores${examId ? `?exam_id=${examId}` : ''}`;
          // 同 monitor：hash 变化时由路由重建视图取数，避免一次点击发两个请求
          if (location.hash.replace(/^#/, '') === next) init();
          else router.navigate(next);
        }
    );
    seg.style.overflowX = 'auto';
    seg.style.maxWidth = '100%';
    pickerSlot.append(seg);
  }

  function renderStats() {
    clear(statsSlot);
    if (!examId || !list.length) return;

    const scored = list.filter((r) => r.stu_status === 'over' && r.stu_score !== null && r.stu_score !== undefined);
    const values = scored.map((r) => Number(r.stu_score));
    const avg = values.length ? values.reduce((a, b) => a + b, 0) / values.length : 0;
    const max = values.length ? Math.max(...values) : 0;
    const min = values.length ? Math.min(...values) : 0;
    const pass = currentExam?.exam_score
      ? values.filter((v) => v >= Number(currentExam.exam_score) * 0.6).length
      : 0;

    statsSlot.append(
      statCard({ label: '应考人数', value: fmtNumber(list.length), iconName: 'users', tone: '' }),
      statCard({ label: '已交卷', value: fmtNumber(scored.length), iconName: 'check-circle', tone: 'success' }),
      statCard({ label: '平均分', value: fmtScore(avg), iconName: 'trending', tone: 'info' }),
      statCard({ label: '最高 / 最低', value: `${fmtScore(max)} / ${fmtScore(min)}`, iconName: 'award', tone: 'warning' }),
    );
  }

  function renderTable() {
    if (!examId) {
      mount(tableSlot, emptyStated('请选择一场考试', { iconName: 'clipboard', desc: '从上方选择考试后查看成绩明细' }));
      return;
    }
    if (!list.length) {
      mount(tableSlot, emptyStated('该考试暂无成绩记录', { iconName: 'clipboard' }));
      return;
    }

    const sorted = [...list].sort((a, b) => {
      const av = a[orderBy] ?? '';
      const bv = b[orderBy] ?? '';
      const cmp = typeof av === 'number' || typeof bv === 'number'
        ? Number(av) - Number(bv)
        : String(av).localeCompare(String(bv), 'zh-CN');
      return order === 'desc' ? -cmp : cmp;
    });

    mount(tableSlot, table({
      columns: [
        { key: 'rank', title: '排名', width: '70px', align: 'center',
          render: (_r, i) => {
            const rank = i + 1;
            const medal = rank <= 3 ? ['🥇', '🥈', '🥉'][rank - 1] : null;
            return el('span', {}, [
              medal ? el('span', { text: medal }) : el('span.mono.c-tertiary', { text: String(rank) }),
            ]);
          } },
        { key: 'stu_id', title: '准考证号', width: '120px', sortable: true,
          render: (r) => el('span.mono', { text: String(r.stu_id) }) },
        { key: 'stu_name', title: '姓名', width: '130px', sortable: true,
          render: (r) => el('span.fw-500', { text: r.stu_name || '—' }) },
        { key: 'class_id', title: '班级 / 单位', width: '180px',
          render: (r) => el('div.fs-sm', {}, [
            el('div', { text: r.class_id || '—' }),
            r.grade_id ? el('div.fs-xs.c-tertiary', { text: r.grade_id }) : null,
          ].filter(Boolean)) },
        { key: 'stu_status', title: '状态', width: '100px', align: 'center', sortable: true,
          render: (r) => {
            const m = ROSTER_STATUS[r.stu_status] || { label: r.stu_status || '—', tone: '' };
            return badge(m.label, { tone: m.tone, dot: true });
          } },
        { key: 'stu_score', title: '得分', width: '110px', align: 'right', sortable: true,
          render: (r) => {
            if (r.stu_score === null || r.stu_score === undefined) return el('span.c-tertiary', { text: '—' });
            const total = Number(currentExam?.exam_score ?? 0);
            const v = Number(r.stu_score);
            const tone = total ? (v >= total * 0.6 ? 'c-success' : 'c-danger') : '';
            return el('span', { class: `mono fw-700 ${tone}`, text: fmtScore(v) });
          } },
        { key: 'rate', title: '得分率', width: '100px', align: 'right',
          render: (r) => {
            const total = Number(currentExam?.exam_score ?? 0);
            if (!total || r.stu_score === null || r.stu_score === undefined) return el('span.c-tertiary', { text: '—' });
            const pct = (Number(r.stu_score) / total) * 100;
            return el('span.mono.fs-sm', { text: `${pct.toFixed(1)}%` });
          } },
      ],
      rows: sorted,
      onSort: (key) => {
        if (orderBy === key) order = order === 'asc' ? 'desc' : 'asc';
        else { orderBy = key; order = 'desc'; }
        renderTable();
      },
      sort: orderBy,
      order,
    }));
  }

  /* ============================ 备份 ============================ */
  async function doBackup() {
    const ok = await confirmDialog('确定备份本场考试的成绩吗？', {
      title: '备份成绩', confirmText: '备份', tone: 'warning',
      detail: '备份会将当前成绩写入归档表，并将考试状态置为「已归档」。每场考试仅可备份一次。',
    });
    if (!ok) return;

    const { ok: done, error, result } = await withLoading(null, () => adminApi.backupScores({ exam_id: examId }), { silent: true });
    if (done) {
      notify.success(`备份成功，共 ${result?.backed_up ?? 0} 条记录`);
      init();
    } else notify.error(error?.message || '备份失败');
  }

  async function openBackups() {
    const body = el('div');
    mount(body, skeletonRows(5, 4));
    const dlg = openModal({ title: '成绩备份记录', body, size: 'lg' });

    try {
      const data = await adminApi.backups();
      const rows = data?.list || [];
      if (!rows.length) {
        mount(body, emptyStated('暂无备份记录', { iconName: 'database' }));
        return;
      }
      mount(body, el('div.stack', {}, [
        alertBox(`共 ${rows.length} 条备份记录`, { type: 'info' }),
        table({
          size: 'sm',
          columns: [
            { key: 'stu_id', title: '准考证号', width: '110px',
              render: (r) => el('span.mono', { text: String(r.stu_id) }) },
            { key: 'stu_name', title: '姓名', width: '110px' },
            { key: 'exam_name', title: '考试', render: (r) => el('span.fs-sm.truncate', {
              style: { maxWidth: '260px', display: 'inline-block' }, text: r.exam_name || '—' }) },
            { key: 'stu_score', title: '得分', align: 'right', width: '80px',
              render: (r) => el('span.mono.fw-600', { text: fmtScore(r.stu_score) }) },
            { key: 'backup_time', title: '备份时间', width: '160px',
              render: (r) => el('span.fs-xs.c-secondary', { text: fmtDateTime(r.backup_time) }) },
          ],
          rows,
        }),
      ]));
    } catch (e) {
      mount(body, alertBox(e?.message || '加载备份记录失败', { type: 'danger' }));
    }
  }

  init();
  // 统一视图契约：返回 { node, dispose }，避免后续维护者在成绩页加轮询/订阅时
  // 因遗漏 dispose 而重演定时器泄漏（BUG-246）。
  return { node: root, dispose: () => {} };
}
