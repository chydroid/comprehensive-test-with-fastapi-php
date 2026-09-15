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
import { passwordHintText, appSettingInt } from '../../core/app-settings.js';

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
        { key: 'exam_name', title: '考试名称', render: (r) => el('span', { text: r.exam_name || `考试 #${r.exam_id}` }) },
        { key: 'subj_name', title: '科目', render: (r) => el('span.muted', { text: r.subj_name || '—' }) },
        { key: 'exam_start', title: '考试时间', render: (r) => el('span.muted', { text: r.exam_start ? fmtDateTime(r.exam_start) : '—' }) },
        { key: 'stu_status', title: '状态', render: (r) => studentStatusBadge(r.stu_status) },
        { key: 'stu_score', title: '得分', align: 'right', render: (r) => el('strong', { text: fmtScore(r.stu_score) }) },
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
  const promptSlot = el('div');
  const listSlot = el('div');
  root.append(el('div.page-head', {}, [
    el('div', {}, [el('h1.page-title', { text: '我的考试' }), el('p.page-sub', { text: '已分配给你的考试场次' })]),
    el('div.page-head-actions', {}, [button('刷新', { variant: 'secondary', size: 'sm', iconName: 'refresh-cw', onClick: () => reload() })]),
  ]), promptSlot, listSlot);

  async function reload() {
    const res = await withLoading(listSlot, () => studentApi.exams());
    if (!res.ok) return;
    const list = res.result.list || [];
    renderPrompt(list);
    if (!list.length) {
      mount(listSlot, emptyStated('暂无可参加的考试', { iconName: 'calendar', desc: '请等待管理员编排考试后通知' }));
      return;
    }
    mount(listSlot, el('div.grid-2', {}, list.map((e) => examCard(e))));
  }

  /** 登录后若存在可进入/已在场的考试，顶部给出醒目的进入提示 */
  function renderPrompt(list) {
    clear(promptSlot);
    const target = list.find((e) => e.state === 'answering')
      || list.find((e) => e.state === 'in_room')
      || list.find((e) => e.state === 'open');
    if (!target) return;

    const answering = target.state === 'answering';
    promptSlot.append(el('div.card.exam-notice', {}, el('div.card-body.flex.items-center.gap-4', {}, [
      el('div.exam-notice-icon', {}, [icon('bell', { size: 22 })]),
      el('div', { style: { flex: '1' } }, [
        el('div.fw-700', { text: answering ? `《${target.exam_name}》已开考，你已在考场中` : `你有一场考试可以进入：《${target.exam_name}》` }),
        el('div.fs-sm.c-secondary.mt-1', {
          text: answering
            ? '点击「继续答题」回到答题界面。'
            : (target.hint || '请凭考场口令入场，口令请向监考教师索取。'),
        }),
      ]),
      button(answering ? '继续答题' : '进入考场', { variant: 'primary', iconName: 'log-in', onClick: () => enterExam(target) }),
    ])));
    notify.info(answering ? `《${target.exam_name}》已开考` : `你有一场考试可以进入：${target.exam_name}`);
  }

  function enterExam(e) {
    // 正式考试是独立入口页（/exam），通过 hash query 预填考试编号
    location.assign(`/exam#/?exam_id=${e.id}`);
  }

  function examCard(e) {
    const state = e.state || 'closed';
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
        active ? badge('进行中', { tone: 'success', dot: true, pulse: true }) : badge(stateLabel(state, e), { tone: stateTone(state) }),
      ]),
      el('div.exam-card-meta.desc-list', {}, [
        dlRow('开始时间', e.exam_start ? fmtDateTime(e.exam_start) : '—'),
        dlRow('结束时间', e.exam_end ? fmtDateTime(e.exam_end) : '—'),
        dlRow('总分', `${fmtScore(e.exam_score)} 分`),
        dlRow('入场开放', e.entry_opens_at ? fmtDateTime(e.entry_opens_at) : '—'),
        dlRow('我的状态', e.stu_status ? (STU_STATUS[e.stu_status]?.label || e.stu_status) : '未入场'),
      ]),
      el('div.exam-card-foot', {}, [footAction(e, state)]),
      hintOf(e, state) ? el('div.fs-xs.c-secondary.mt-2', { text: hintOf(e, state) }) : null,
    ]);
  }

  function footAction(e, state) {
    if (state === 'open') {
      return button('进入考场', { variant: 'primary', iconName: 'log-in', block: true, onClick: () => enterExam(e) });
    }
    if (state === 'in_room') {
      return button('返回考场（等待开考）', { variant: 'primary', iconName: 'log-in', block: true, onClick: () => enterExam(e) });
    }
    if (state === 'answering') {
      return button('继续答题', { variant: 'primary', iconName: 'edit-3', block: true, onClick: () => enterExam(e) });
    }
    if (state === 'upcoming') {
      return button('未到入场时间', { variant: 'secondary', block: true, disabled: true });
    }
    if (state === 'submitted') {
      return button('已交卷', { variant: 'secondary', block: true, disabled: true });
    }
    return button('不可入场', { variant: 'secondary', block: true, disabled: true });
  }

  function hintOf(e, state) {
    // 服务端已按后台「考试规则」生成说明文案（含入场窗口分钟数），优先使用
    if (e.hint) return e.hint;
    if (state === 'upcoming') return e.entry_opens_at ? `入场将于 ${fmtDateTime(e.entry_opens_at)} 开放。` : '尚未到入场时间。';
    if (state === 'closed' && !e.pwd_ready) return '考场尚未开放入场，请等待监考教师开放。';
    if (state === 'closed') return '考试已开始或已结束，无法进入考场。';
    return '';
  }

  function stateLabel(state, e) {
    return ({
      open: '可入场',
      in_room: '等待开考',
      answering: '答题中',
      upcoming: '未到入场时间',
      closed: e && !e.pwd_ready ? '未开放' : '不可入场',
      submitted: '已交卷',
    })[state] || '—';
  }

  function stateTone(state) {
    return ({ open: 'success', in_room: 'info', answering: 'success', upcoming: '', closed: 'warning', submitted: 'info' })[state] || '';
  }

  function dlRow(k, v) {
    return el('div.dl-row', {}, [el('span.dl-key', { text: k }), el('span.dl-val', { text: typeof v === 'string' ? v : String(v) })]);
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
      const gradeOpts = refOptions(opts.grades, 'grade_name');
      const classOpts = refOptions(opts.classes, 'class_name');
      const gradeSel = select(gradeOpts, {
        name: 'grade_id',
        value: refValue(gradeOpts, info.grade_id, info.grade_name),
        placeholder: '请选择单位',
      });
      const classSel = select(classOpts, {
        name: 'class_id',
        value: refValue(classOpts, info.class_id, info.class_name),
        placeholder: '请选择班级',
      });

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
  const oldIn = input({ name: 'old_pwd', type: 'password', required: true, autocomplete: 'current-password' });
  const newIn = input({ name: 'new_pwd', type: 'password', required: true, autocomplete: 'new-password' });
  const reIn = input({ name: 'new_pwd2', type: 'password', required: true, autocomplete: 'new-password' });
  const errSlot = el('div');

  const form = el('form', { class: 'form-grid', on: { submit: (ev) => { ev.preventDefault(); submit(); } } }, [
    field('当前密码', oldIn, { required: true }),
    field('新密码', newIn, { required: true, hint: `${passwordHintText()}，建议字母 + 数字组合` }),
    field('确认新密码', reIn, { required: true }),
    errSlot,
    el('div.form-actions', {}, [button('确认修改', { variant: 'primary', type: 'submit', iconName: 'key' })]),
  ]);

  async function submit() {
    clear(errSlot);
    if (newIn.value.length < appSettingInt('password_min_length', 6)) {
      errSlot.append(el('div.alert.alert-danger', {}, [el('span', { text: `新密码${passwordHintText()}` })]));
      return;
    }
    if (newIn.value !== reIn.value) {
      errSlot.append(el('div.alert.alert-danger', {}, [el('span', { text: '两次输入的新密码不一致' })]));
      return;
    }
    // 字段名必须与后端 StudentController::savePwd 一致（old_pwd / new_pwd / new_pwd2）。
    // 此前发 old_password/new_password 且漏发二次确认 → 恒 400「参数 old_pwd 不能为空」。
    const res = await withLoading(form, () => studentApi.updatePassword({
      old_pwd: oldIn.value,
      new_pwd: newIn.value,
      new_pwd2: reIn.value,
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

/* ==================== 单位 / 班级下拉（名称→ID 归一） ==================== */

/**
 * 选项一律用「ID 作为 value、名称作为 label」。
 *
 * stuinfo.grade_id / class_id 是 varchar：旧系统表单提交的是名称，而排卷
 * （Student::byClassIds）、待考列表（FIND_IN_SET(class_id, exam.stu_class)）、
 * 考场准入（Exam::isStudentEligible）全都按 ID 匹配。本页此前提交名称，
 * 保存一次个人资料就会让该考生从此看不到任何考试，故统一改为提交 ID。
 */
function refOptions(rows, nameKey) {
  return (rows || []).map((r) => ({ value: String(r.id), label: r[nameKey] || String(r.id) }));
}

/** 回填值：先按 ID 命中，再按名称命中，都不中且原值非空则补一条历史值选项 */
function refValue(options, idValue, nameValue) {
  const id = String(idValue ?? '');
  if (id === '') return '';
  if (options.some((o) => o.value === id)) return id;
  const hit = options.find((o) => o.label === String(nameValue ?? ''));
  if (hit) return hit.value;
  // 例如班级已被删除：保留原值，避免「打开即清空、保存即丢失归属」
  options.push({ value: id, label: `${nameValue || id}（历史值，建议重新选择）` });
  return id;
}
