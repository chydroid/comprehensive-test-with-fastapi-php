/**
 * 管理后台 —— 仪表盘
 */

import { el, mount, clear } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  card, statCard, table, badge, button, emptyStated, skeletonRows, progressBar,
} from '../../ui/components.js';
import { fmtDate, fmtDateTime, fmtScore, fmtNumber, fmtRelative } from '../../core/format.js';
import { adminApi } from '../../api/index.js';

const STATUS_META = {
  testing: { label: '进行中', tone: 'success', icon: 'activity' },
  exam:    { label: '待开考', tone: 'warning', icon: 'clock' },
  paper:   { label: '已组卷', tone: 'info',    icon: 'file' },
  over:    { label: '已结束', tone: '',        icon: 'check-circle' },
  overBak: { label: '已归档', tone: '',        icon: 'database' },
};

const TYPE_LABELS = { radio1: '判断题', radio2: '单选题', checkbox: '多选题', text: '填空题', longtext: '问答题' };

export async function DashboardView({ router }) {
  const root = el('div.stack-lg');

  const head = el('div.page-head', {}, [
    el('div', {}, [
      el('h2.page-title', { text: '仪表盘' }),
      el('div.page-desc', { text: '系统概览与近期动态' }),
    ]),
    el('div.page-actions', {}, [
      button('刷新', { variant: 'secondary', iconName: 'refresh', onClick: () => reload() }),
    ]),
  ]);

  const statsSlot = el('div.grid-stats');
  const bodySlot = el('div');

  root.append(head, statsSlot, bodySlot);
  mount(bodySlot, skeletonRows(6, 4));

  async function reload() {
    try {
      const data = await adminApi.dashboard();
      render(data);
    } catch (e) {
      mount(bodySlot, el('div.alert.alert-danger', { text: e?.message || '仪表盘加载失败' }));
    }
  }

  function render(d) {
    const { quiz = {}, counts = {}, exams = {}, current_exams = [], recent_scores = [] } = d || {};

    /* 统计卡 */
    clear(statsSlot);
    const byType = quiz.by_type || {};
    statsSlot.append(
      statCard({ label: '题库总量', value: fmtNumber(quiz.total), iconName: 'database', tone: '' }),
      statCard({ label: '考生人数', value: fmtNumber(counts.students), iconName: 'users', tone: 'info' }),
      statCard({ label: '考试总数', value: fmtNumber(exams.total), iconName: 'clipboard', tone: 'warning' }),
      statCard({ label: '进行中考试', value: fmtNumber(exams.testing), iconName: 'activity', tone: exams.testing ? 'success' : '' }),
    );

    const cols = el('div.grid-2');

    /* 题库题型分布 */
    const totalTyped = Object.values(byType).reduce((a, b) => a + Number(b || 0), 0) || 1;
    const typeBox = el('div.stack-sm');
    for (const [key, label] of Object.entries(TYPE_LABELS)) {
      const n = Number(byType[key] || 0);
      const pct = (n / totalTyped) * 100;
      typeBox.append(el('div', {}, [
        el('div.flex.items-center.justify-between.mb-1', {}, [
          el('span.fs-sm', { text: label }),
          el('span.fs-sm.c-secondary.mono', { text: `${fmtNumber(n)} · ${pct.toFixed(1)}%` }),
        ]),
        progressBar(pct, { tone: pct > 40 ? 'tone-warning' : '' }),
      ]));
    }

    /* 实体概览 */
    const entityRows = [
      ['科目', counts.subjects, 'book'],
      ['考试类别', counts.categories, 'flag'],
      ['教师', counts.teachers, 'teacher'],
      ['单位', counts.grades, 'school'],
      ['班级', counts.classes, 'layers'],
      ['公告', counts.news, 'bell'],
      ['管理员', counts.admins, 'shield'],
    ];
    const entityGrid = el('div', {
      style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(110px,1fr))', gap: 'var(--sp-3)' },
    });
    for (const [label, value, iconName] of entityRows) {
      entityGrid.append(el('div', {
        style: {
          padding: 'var(--sp-3)', background: 'var(--bg-sunken)',
          border: '1px solid var(--border-subtle)', borderRadius: 'var(--radius-md)',
        },
      }, [
        el('div.flex.items-center.gap-2.c-tertiary.fs-xs.mb-1', {}, [
          icon(iconName, { size: 14 }), el('span', { text: label }),
        ]),
        el('div.fw-700', { style: { fontSize: 'var(--fs-xl)' }, text: fmtNumber(value) }),
      ]));
    }

    cols.append(
      card({ title: '题库题型分布', iconName: 'chart', body: typeBox }),
      card({ title: '数据概览', iconName: 'layers', body: entityGrid }),
    );

    /* 进行中考试 */
    const currentCard = card({
      title: '进行中的考试',
      iconName: 'activity',
      actions: [
        badge(`实时 ${current_exams.length} 场`, { tone: current_exams.length ? 'success' : '', dot: true, pulse: current_exams.length > 0 }),
      ],
      body: current_exams.length
        ? table({
            size: 'sm',
            columns: [
              { key: 'exam_name', title: '考试名称', render: (r) => el('div', {}, [
                  el('div.fw-500', { text: r.exam_name }),
                  el('div.fs-xs.c-tertiary', { text: r.subj_name || '—' }),
                ]) },
              { key: 'exam_class', title: '班级', render: (r) => r.exam_class || r.stu_class || '—' },
              { key: 'exam_start', title: '时间', render: (r) => el('div.fs-sm.mono', {}, [
                  el('div', { text: fmtDateTime(r.exam_start) }),
                  el('div.c-tertiary', { text: fmtDateTime(r.exam_end) }),
                ]) },
              { key: 'exam_status', title: '状态', align: 'center', render: (r) => {
                  const m = STATUS_META[r.exam_status] || { label: r.exam_status, tone: '' };
                  return badge(m.label, { tone: m.tone, dot: true, pulse: r.exam_status === 'testing' });
                } },
              { key: '__ops', title: '操作', align: 'right', render: () => button('监考', {
                  variant: 'secondary', size: 'sm', iconName: 'eye',
                  onClick: () => router.navigate('/monitor'),
                }) },
            ],
            rows: current_exams,
          })
        : emptyStated('当前没有进行中的考试', { iconName: 'check-circle', desc: '所有考试均已结束或尚未开始' }),
    });

    /* 近期成绩 */
    const recentCard = card({
      title: '近期考试统计',
      iconName: 'trending',
      body: recent_scores.length
        ? table({
            size: 'sm',
            columns: [
              { key: 'exam_name', title: '考试名称', render: (r) => el('div', {}, [
                  el('div.fw-500.truncate', { text: r.exam_name, title: r.exam_name }),
                  el('div.fs-xs.c-tertiary', { text: fmtDate(r.exam_start || '') || r.subj_name || '' }),
                ]) },
              { key: 'stu_count', title: '人数', align: 'right', render: (r) => fmtNumber(r.stu_count) },
              { key: 'avg_score', title: '平均分', align: 'right', render: (r) => el('span.mono.fw-600', { text: fmtScore(r.avg_score) }) },
              { key: 'max_score', title: '最高', align: 'right', render: (r) => el('span.mono.c-success', { text: fmtScore(r.max_score) }) },
              { key: 'min_score', title: '最低', align: 'right', render: (r) => el('span.mono.c-danger', { text: fmtScore(r.min_score) }) },
            ],
            rows: recent_scores,
          })
        : emptyStated('暂无成绩数据', { iconName: 'clipboard' }),
    });

    clear(bodySlot);
    bodySlot.append(cols, currentCard, recentCard);
  }

  await reload();
  return root;
}
