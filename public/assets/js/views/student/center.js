/**
 * 考生个人中心视图集合：资料 / 修改密码 / 我的成绩 / 待考考试。
 * 全部数据来自 /api/student/*，字段与后端实测响应一致。
 */

import { el, clear, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, card, statCard, table, badge, field, input, select,
  emptyStated, descList, notify,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { studentApi } from '../../api/index.js';
import { fmtDateTime, fmtScore, fmtNumber, fmtDate } from '../../core/format.js';

/** 考场状态 → 文案/色调 */
export const STU_STATUS = {
  waiting: { label: '待考', tone: '' },
  online:  { label: '答题中', tone: 'success' },
  locked:  { label: '已锁定', tone: 'warning' },
  over:    { label: '已交卷', tone: 'info' },
  overBak: { label: '已交卷', tone: 'info' },
};

export function studentStatusBadge(status) {
  const s = String(status || 'waiting');
  const meta = STU_STATUS[s] || (s.startsWith('over') ? STU_STATUS.over : { label: s || '未知', tone: '' });
  return badge(meta.label, { tone: meta.tone, dot: true });
}

/* ============================ 我的成绩 ============================ */
export function StudentScoresView() {
  const root = el('div.stack');
  const statsSlot = el('div.grid-stats');
  const tableSlot = el('div');
  root.append(el('div.page-head', {}, [
    el('div', {}, [el('h1.page-title', { text: '我的成绩' }), el('p.page-sub', { text: '历次考试得分与状态' })]),
  ]), statsSlot, tableSlot);

  (async () => {
    const res = await withLoading(tableSlot, () => studentApi.scores());
    if (!res.ok) return;
    const { list = [], stats = {}, history = [] } = res.result;

    mount(statsSlot, [
      statCard({ label: '考试次数', value: fmtNumber(stats.total), iconName: 'clipboard' }),
      statCard({ label: '已完成', value: fmtNumber(stats.finished), iconName: 'check-circle', tone: 'success' }),
      statCard({ label: '平均分', value: fmtScore(stats.avg_score), iconName: 'trending-up', tone: 'brand' }),
      statCard({ label: '最高分', value: fmtScore(stats.best_score), iconName: 'award', tone: 'warning' }),
    ]);

    const rows = [...list, ...history].map((r) => ({ ...r, _bak: !list.includes(r) }));
    const t = table({
      columns: [
        { key: 'exam_name', label: '考试名称', render: (r) => el('span', { text: r.exam_name || `考试 #${r.exam_id}` }) },
        { key: 'subj_name', label: '科目', render: (r) => el('span.muted', { text: r.subj_name || '—' }) },
        { key: 'exam_start', label: '考试时间', render: (r) => el('span.muted', { text: r.exam_start ? fmtDateTime(r.exam_start) : '—' }) },
        { key: 'stu_status', label: '状态', render: (r) => studentStatusBadge(r.stu_status) },
        { key: 'stu_score', label: '得分', align: 'right', render: (r) => el('strong', { text: fmtScore(r.stu_score) }) },
      ],
      rows,
      emptyText: '还没有考试成绩',
    });
    mount(tableSlot, t);
  })();

  return root;
}

/* ============================ 待考考试 ============================ */
export function StudentExamsView({ router }) {
  const root = el('div.stack');
  const listSlot = el('div');
  root.append(el('div.page-head', {}, [
    el('div', {}, [el('h1.page-title', { text: '我的考试' }), el('p.page-sub', { text: '已分配给你的考试场次' })]),
    el('div.page-head-actions', {}, [button('刷新', { variant: 'secondary', size: 'sm', iconName: 'refresh-cw', onClick: () => reload() })]),
  ]), listSlot);

  async function reload() {
    const res = await withLoading(listSlot, () => studentApi.exams());
    if (!res.ok) return;
    const list = res.result.list || [];
    if (!list.length) {
      mount(listSlot, emptyStated('暂无可参加的考试', { iconName: 'calendar', desc: '请等待管理员编排考试后通知' }));
      return;
    }
    mount(listSlot, el('div.grid-2', {}, list.map((e) => examCard(e))));
  }

  function examCard(e) {
    const active = e.exam_status === 'testing';
    return el('div.card.exam-card', {}, [
      el('div.exam-card-head', {}, [
        el('div', {}, [
          el('h3.exam-card-title', { text: e.exam_name }),
          el('div.row.gap-xs', {}, [
            e.subj_name ? badge(e.subj_name, { tone: 'brand' }) : null,
            e.category_name ? badge(e.category_name) : null,
          ]),
        ]),
        active ? badge('进行中', { tone: 'success', dot: true, pulse: true }) : badge(statusText(e.exam_status)),
      ]),
      el('div.exam-card-meta.desc-list', {}, [
        dlRow('开始时间', e.exam_start ? fmtDateTime(e.exam_start) : '—'),
        dlRow('结束时间', e.exam_end ? fmtDateTime(e.exam_end) : '—'),
        dlRow('总分', `${fmtScore(e.exam_score)} 分`),
        dlRow('我的状态', e.stu_status ? (STU_STATUS[e.stu_status]?.label || e.stu_status) : '未开始'),
      ]),
      el('div.exam-card-foot', {}, [
        active
          ? button('进入考场', { variant: 'primary', iconName: 'log-in', block: true, onClick: () => router.navigate(`/exam?exam_id=${e.id}`) })
          : button('未开始', { variant: 'secondary', block: true, disabled: true }),
      ]),
    ]);
  }

  function dlRow(k, v) {
    return el('div.dl-row', {}, [el('span.dl-key', { text: k }), el('span.dl-val', { text: typeof v === 'string' ? v : String(v) })]);
  }

  function statusText(s) {
    return ({ exam: '已编排', paper: '已组卷', over: '已结束', overBak: '已结束' })[s] || s || '—';
  }

  reload();
  return root;
}

/* ============================ 个人资料 ============================ */
export function StudentInfoView() {
  const root = el('div.stack');
  const formSlot = el('div');

  (async () => {
    const [infoRes, optRes] = await Promise.all([
      withLoading(formSlot, () => studentApi.info()),
      studentApi.options().catch(() => null),
    ]);
    if (!infoRes.ok) return;
    const info = infoRes.result;
    const opts = optRes || { grades: [], classes: [] };

    const nameIn = input({ name: 'stu_name', value: info.stu_name || '', required: true, maxlength: 50 });
    const sexSel = select(
      [{ value: '男', label: '男' }, { value: '女', label: '女' }],
      { name: 'stu_sex', value: info.stu_sex || '男' },
    );
    const gradeSel = select(
      (opts.grades || []).map((g) => ({ value: g.grade_name || g.id, label: g.grade_name || g.id })),
      { name: 'grade_id', value: info.grade_id || '', placeholder: '请选择单位' },
    );
    const classSel = select(
      (opts.classes || []).map((c) => ({ value: c.class_name || c.id, label: c.class_name || c.id })),
      { name: 'class_id', value: info.class_id || '', placeholder: '请选择班级' },
    );

    const form = el('form', { class: 'form-grid', on: { submit: (ev) => { ev.preventDefault(); save(); } } }, [
      field('准考证号', input({ value: String(info.id), disabled: true }), { hint: '准考证号不可修改' }),
      field('姓名', nameIn, { required: true }),
      field('性别', sexSel),
      field('单位', gradeSel),
      field('班级', classSel),
      el('div.form-actions', {}, [
        button('保存修改', { variant: 'primary', type: 'submit', iconName: 'save' }),
        button('重置', { variant: 'secondary', onClick: () => location.reload() }),
      ]),
    ]);

    async function save() {
      const res = await withLoading(form, () => studentApi.updateInfo({
        stu_name: nameIn.value.trim(),
        stu_sex: sexSel.value,
        grade_id: gradeSel.value,
        class_id: classSel.value,
      }));
      if (res.ok) {
        notify.success('资料已保存');
      } else {
        notify.error(res.error?.message || '保存失败');
      }
    }

    mount(formSlot, card({ title: '基本资料', iconName: 'user', body: form }));
  })();

  root.append(el('div.page-head', {}, [
    el('div', {}, [el('h1.page-title', { text: '个人资料' }), el('p.page-sub', { text: '维护你的考生信息' })]),
  ]), formSlot);
  return root;
}

/* ============================ 修改密码 ============================ */
export function StudentPasswordView() {
  const root = el('div.stack');
  const oldIn = input({ name: 'old_password', type: 'password', required: true, autocomplete: 'current-password' });
  const newIn = input({ name: 'new_password', type: 'password', required: true, autocomplete: 'new-password' });
  const reIn = input({ name: 'confirm_password', type: 'password', required: true, autocomplete: 'new-password' });
  const errSlot = el('div');

  const form = el('form', { class: 'form-grid', on: { submit: (ev) => { ev.preventDefault(); submit(); } } }, [
    field('当前密码', oldIn, { required: true }),
    field('新密码', newIn, { required: true, hint: '至少 6 位，建议字母 + 数字组合' }),
    field('确认新密码', reIn, { required: true }),
    errSlot,
    el('div.form-actions', {}, [button('确认修改', { variant: 'primary', type: 'submit', iconName: 'key' })]),
  ]);

  async function submit() {
    clear(errSlot);
    if (newIn.value.length < 6) {
      errSlot.append(el('div.alert.alert-danger', {}, [el('span', { text: '新密码至少 6 位' })]));
      return;
    }
    if (newIn.value !== reIn.value) {
      errSlot.append(el('div.alert.alert-danger', {}, [el('span', { text: '两次输入的新密码不一致' })]));
      return;
    }
    const res = await withLoading(form, () => studentApi.updatePassword({
      old_password: oldIn.value,
      new_password: newIn.value,
    }));
    if (res.ok) {
      notify.success('密码修改成功');
      form.reset();
    } else {
      errSlot.append(el('div.alert.alert-danger', {}, [el('span', { text: res.error?.message || '修改失败' })]));
    }
  }

  root.append(el('div.page-head', {}, [
    el('div', {}, [el('h1.page-title', { text: '修改密码' }), el('p.page-sub', { text: '定期更换密码保障账号安全' })]),
  ]), card({ title: '安全设置', iconName: 'shield', body: form }));
  return root;
}
