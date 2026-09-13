/**
 * 管理后台 —— 站点配置 / 系统维护 / 个人设置
 */

import { el, mount, clear } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, badge, card, openModal, confirmDialog, notify, alertBox,
  table, descList, emptyStated, field, input, textarea, select, codeBlock,
} from '../../ui/components.js';
import { adminApi, exerciseApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';
import { fmtNumber, fmtDateTime } from '../../core/format.js';

/* ============================ 站点配置 ============================ */
export function ConfigView() {
  const root = el('div.stack');
  const formSlot = el('div');

  const FIELDS = [
    { name: 'site_title', label: '站点标题', placeholder: '如：网上理论考核系统', maxlength: 100, colSpan: 2 },
    { name: 'copyright', label: '版权信息', placeholder: '如：XX 海事局 版权所有', maxlength: 255, colSpan: 2 },
    { name: 'icp', label: '备案号', placeholder: '如：京 ICP 备 12345678 号', maxlength: 100 },
    { name: 'phone', label: '联系电话', placeholder: '如：010-12345678', maxlength: 50 },
    { name: 'address', label: '联系地址', placeholder: '如：XX 市 XX 区 XX 路 1 号', maxlength: 255, colSpan: 2 },
    { name: 'site_desc', label: '站点描述', type: 'textarea', rows: 4, maxlength: 500, colSpan: 2,
      placeholder: '用于首页与搜索引擎展示的站点简介' },
  ];

  const controls = {};

  async function load() {
    mount(formSlot, el('div.stack-sm', {}, [
      el('div.skeleton.skeleton-title'),
      el('div.skeleton.skeleton-text'),
      el('div.skeleton.skeleton-text'),
    ]));
    try {
      const data = await adminApi.config();
      renderForm(data || {});
    } catch (e) {
      mount(formSlot, alertBox(e?.message || '配置加载失败', { type: 'danger' }));
    }
  }

  function renderForm(values) {
    const form = el('div.form-grid');
    for (const f of FIELDS) {
      let ctl;
      if (f.type === 'textarea') {
        ctl = textarea({ name: f.name, value: values[f.name] ?? '', placeholder: f.placeholder || '', rows: f.rows || 4 });
      } else {
        ctl = input({ name: f.name, value: values[f.name] ?? '', placeholder: f.placeholder || '', maxlength: f.maxlength || '' });
      }
      controls[f.name] = ctl;
      const wrap = field(f.label, ctl);
      if (f.colSpan === 2) wrap.classList.add('span-2');
      form.append(wrap);
    }

    const saveBtn = button('保存配置', { variant: 'primary', iconName: 'save' });
    const resetBtn = button('重置', { variant: 'secondary', iconName: 'refresh', onClick: () => load() });

    saveBtn.addEventListener('click', async () => {
      const payload = {};
      for (const f of FIELDS) payload[f.name] = controls[f.name].value.trim();

      const changed = Object.entries(payload).filter(([k, v]) => v !== (values[k] ?? ''));
      if (!changed.length) { notify.info('配置没有变化'); return; }

      const { ok, error } = await withLoading(saveBtn, () => adminApi.saveConfig(payload), { silent: true });
      if (ok) {
        notify.success('配置已保存');
        load();
      } else {
        notify.error(error?.message || '保存失败');
      }
    });

    clear(formSlot);
    formSlot.append(card({
      title: '站点信息',
      iconName: 'settings',
      body: form,
      footer: el('div.flex.justify-end.gap-2', {}, [resetBtn, saveBtn]),
    }));
  }

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [
        el('h2.page-title', { text: '站点配置' }),
        el('div.page-desc', { text: '以下信息会展示在前台门户、考生端页脚等位置' }),
      ]),
    ]),
    formSlot,
  );

  load();
  return root;
}

/* ============================ 系统维护 ============================ */
export function SystemView({ router }) {
  const root = el('div.stack');
  const infoSlot = el('div');

  async function load() {
    mount(infoSlot, el('div.skeleton.skeleton-title'));
    try {
      const data = await adminApi.system();
      render(data || {});
    } catch (e) {
      mount(infoSlot, alertBox(e?.message || '系统信息加载失败', { type: 'danger' }));
    }
  }

  function render(d) {
    const counts = d.counts || {};
    const running = Number(d.exam_running ?? 0);

    const tableCard = card({
      title: '数据量统计',
      iconName: 'database',
      body: el('div', {
        style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px,1fr))', gap: 'var(--sp-4)' },
      }, Object.entries(counts).map(([k, v]) => el('div', {
        style: {
          padding: 'var(--sp-4)', background: 'var(--bg-sunken)',
          border: '1px solid var(--border-subtle)', borderRadius: 'var(--radius-md)',
        },
      }, [
        el('div.fs-xs.c-tertiary.mb-1', { text: TABLE_LABELS[k] || k }),
        el('div.fw-700', { style: { fontSize: 'var(--fs-2xl)' }, text: fmtNumber(v) }),
      ]))),
    });

    const dangerCard = card({
      title: '危险操作',
      iconName: 'alert',
      body: el('div.stack', {}, [
        running > 0
          ? alertBox(`当前有 ${running} 场考试正在进行，清理类操作已被系统禁止。请先结束所有考试。`, { type: 'warning' })
          : alertBox('以下操作会永久删除数据，执行前请务必确认已备份。', { type: 'danger' }),

        opRow(
          '清理考试数据',
          '删除所有考试、试卷与成绩记录，但保留题库与考生账号。适合每轮考试结束后重置。',
          button('清理考试数据', {
            variant: 'outline-danger', iconName: 'trash',
            disabled: running > 0,
            onClick: () => doDanger('clearExams', '清理考试数据', 'CLEAR-EXAMS',
              '将删除 examinfo / stupaper / stuscore 全部数据，题库与考生账号保留。'),
          }),
          running > 0
        ),

        el('hr.divider'),

        opRow(
          '系统初始化',
          '清空全部业务数据，包括考试、试卷、成绩与考生账号，仅保留题库、科目与管理员。此操作不可恢复。',
          button('系统初始化', {
            variant: 'danger', iconName: 'alert',
            disabled: running > 0,
            onClick: () => doDanger('initialize', '系统初始化', 'INITIALIZE',
              '将删除考试、试卷、成绩以及全部考生账号！题库与管理员保留。此操作不可恢复。'),
          }),
          running > 0
        ),
      ]),
    });

    clear(infoSlot);
    infoSlot.append(tableCard, dangerCard);
  }

  function opRow(title, desc, btn, disabled) {
    return el('div.flex.items-start.justify-between.gap-4', { style: { flexWrap: 'wrap' } }, [
      el('div.flex-1', { style: { minWidth: '260px' } }, [
        el('div.fw-600', { text: title }),
        el('div.fs-sm.c-secondary.mt-1', { text: desc }),
      ]),
      el('div.shrink-0', {}, [btn]),
    ]);
  }

  async function doDanger(method, label, confirmWord, detail) {
    const ok = await confirmDialog(`即将执行「${label}」，请输入确认口令以继续。`, {
      title: label, confirmText: '继续', tone: 'danger', detail,
    });
    if (!ok) return;

    // 二次确认：要求输入口令
    const inputCtl = input({ placeholder: `请输入 ${confirmWord}`, autocomplete: 'off' });
    const errSlot = el('div');
    const confirmBtn = button('确认执行', { variant: 'danger', disabled: true });

    inputCtl.addEventListener('input', () => {
      confirmBtn.disabled = inputCtl.value.trim() !== confirmWord;
    });

    const dlg = openModal({
      title: `二次确认 · ${label}`,
      size: 'sm',
      body: el('div.stack', {}, [
        alertBox('此操作不可恢复，请确认你已做好数据备份。', { type: 'danger' }),
        field(`请输入确认口令「${confirmWord}」`, inputCtl, { required: true }),
        errSlot,
      ]),
      footer: [button('取消', { variant: 'secondary', onClick: () => dlg.close() }), confirmBtn],
    });

    confirmBtn.addEventListener('click', async () => {
      const { ok: done, error, result } = await withLoading(confirmBtn, () => adminApi[method]({ confirm: inputCtl.value.trim() }), { silent: true });
      clear(errSlot);
      if (done) {
        notify.success(`${label}完成`);
        dlg.close();
        load();
      } else {
        errSlot.append(alertBox(error?.message || '执行失败', { type: 'danger' }));
      }
    });
  }

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [
        el('h2.page-title', { text: '系统维护' }),
        el('div.page-desc', { text: '数据统计与危险操作。请谨慎使用清理功能' }),
      ]),
      el('div.page-actions', {}, [
        button('刷新', { variant: 'secondary', iconName: 'refresh', onClick: () => load() }),
      ]),
    ]),
    infoSlot,
  );

  load();
  return root;
}

const TABLE_LABELS = {
  examinfo: '考试',
  stupaper: '试卷',
  stuscore: '成绩记录',
  stuinfo: '考生账号',
  quizlib: '题目',
  subject: '科目',
  teainfo: '教师',
  classinfo: '班级',
  gradeinfo: '单位',
  admininfo: '管理员',
  examnews: '公告',
  stuscorebak: '成绩备份',
  siteconfig: '配置项',
  exam_category: '考试类别',
};

/* ============================ 个人设置 ============================ */
export function ProfileView({ shell, session }) {
  const root = el('div.stack-lg');
  const admin = session?.admin || {};
  const perms = session?.permissions || [];

  /* 基本信息 */
  const ROLE_LABELS = {
    systemAdmin: '超级管理员', testAdmin: '考务管理员',
    quizOperator: '题库维护', quizAdder: '题库录入',
  };

  const infoCard = card({
    title: '基本信息',
    iconName: 'user',
    body: el('div.stack', {}, [
      el('div.flex.items-center.gap-4', {}, [
        el('div.avatar.avatar-xl', { text: (admin.username || '?').slice(0, 1).toUpperCase() }),
        el('div', {}, [
          el('div.fw-700', { style: { fontSize: 'var(--fs-xl)' }, text: admin.username || '—' }),
          el('div.mt-1', {}, [badge(ROLE_LABELS[admin.admin_power] || admin.admin_power || '—', { tone: 'brand' })]),
        ]),
      ]),
      el('hr.divider'),
      descList([
        ['账号 ID', String(admin.id ?? '—')],
        ['登录账号', admin.username || '—'],
        ['角色', ROLE_LABELS[admin.admin_power] || admin.admin_power || '—'],
        ['权限点数量', perms.includes('*') ? '全部权限' : String(perms.length)],
      ]),
    ]),
  });

  /* 改密 */
  const oldPwd = input({ type: 'password', name: 'old_password', placeholder: '请输入当前密码', autocomplete: 'current-password' });
  const newPwd = input({ type: 'password', name: 'new_password', placeholder: '至少 6 位，不能为纯数字', autocomplete: 'new-password' });
  const confirmPwd = input({ type: 'password', name: 'confirm_password', placeholder: '请再次输入新密码', autocomplete: 'new-password' });
  const pwdErr = el('div');

  const savePwdBtn = button('修改密码', { variant: 'primary', iconName: 'lock' });
  savePwdBtn.addEventListener('click', async () => {
    clear(pwdErr);
    if (!oldPwd.value) { pwdErr.append(alertBox('请输入当前密码', { type: 'warning' })); return; }
    if (newPwd.value.length < 6) { pwdErr.append(alertBox('新密码至少 6 位', { type: 'warning' })); return; }
    if (newPwd.value !== confirmPwd.value) { pwdErr.append(alertBox('两次输入的新密码不一致', { type: 'warning' })); return; }

    const { ok, error } = await withLoading(savePwdBtn, () => adminApi.updatePassword({
      old_password: oldPwd.value,
      new_password: newPwd.value,
    }), { silent: true });

    if (ok) {
      notify.success('密码修改成功，请牢记新密码');
      oldPwd.value = newPwd.value = confirmPwd.value = '';
      clear(pwdErr);
    } else {
      pwdErr.append(alertBox(error?.message || '修改失败', { type: 'danger' }));
    }
  });

  const pwdCard = card({
    title: '修改密码',
    iconName: 'shield',
    body: el('div.stack', {}, [
      alertBox('修改密码后当前会话会立即刷新，原有令牌失效。', { type: 'info' }),
      field('当前密码', oldPwd, { required: true }),
      field('新密码', newPwd, { required: true, hint: '不能为纯数字、不能与账号相同' }),
      field('确认新密码', confirmPwd, { required: true }),
      pwdErr,
      el('div.flex.justify-end', {}, [savePwdBtn]),
    ]),
  });

  /* 权限点 */
  const permCard = perms.length ? card({
    title: '我的权限',
    iconName: 'sliders',
    body: el('div.flex.flex-wrap.gap-2', {}, perms.includes('*')
      ? [badge('全部权限（超级管理员）', { tone: 'danger', dot: true })]
      : perms.map((p) => badge(p, { tone: 'info' }))
    ),
  }) : null;

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [
        el('h2.page-title', { text: '个人设置' }),
        el('div.page-desc', { text: '查看账号信息与修改登录密码' }),
      ]),
    ]),
    el('div.grid-2', {}, [infoCard, pwdCard]),
    permCard,
  );

  return root;
}
