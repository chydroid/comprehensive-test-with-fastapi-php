/**
 * 管理后台 —— 站点配置 / 系统维护 / 个人设置
 */

import { el, mount, clear } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, badge, card, openModal, confirmDialog, notify, alertBox,
  table, descList, emptyStated, field, input, textarea, select, codeBlock,
  tabs, switchToggle,
} from '../../ui/components.js';
import { adminApi, exerciseApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';
import { invalidateAppSettings, loadAppSettings, appSettingInt, passwordHintText } from '../../core/app-settings.js';
import { fmtNumber, fmtDateTime } from '../../core/format.js';

/* ============================ 系统设置 ============================ */

/**
 * 站点信息 + 运行参数。
 *
 * 运行参数（考试规则 / 安全策略 / 界面与体验）完全由后端 App\Services\Setting
 * 的 schema 驱动：分组、类型、取值范围、单位与帮助文案都随 /api/admin/settings
 * 下发，前端只负责渲染——后端新增一个设置项时这里无需同步改代码。
 */
export function ConfigView() {
  const root = el('div.stack');
  const tabSlot = el('div');
  const bodySlot = el('div');

  /** 站点展示信息（与运行参数分开维护，键名不重叠） */
  const SITE_FIELDS = [
    { name: 'site_title', label: '站点标题', placeholder: '如：网上理论考核系统', maxlength: 100, colSpan: 2 },
    { name: 'copyright', label: '版权信息', placeholder: '如：XX 海事局 版权所有', maxlength: 255, colSpan: 2 },
    { name: 'icp', label: '备案号', placeholder: '如：京 ICP 备 12345678 号', maxlength: 100 },
    { name: 'phone', label: '联系电话', placeholder: '如：010-12345678', maxlength: 50 },
    { name: 'address', label: '联系地址', placeholder: '如：XX 市 XX 区 XX 路 1 号', maxlength: 255, colSpan: 2 },
    { name: 'site_desc', label: '站点描述', type: 'textarea', rows: 4, maxlength: 500, colSpan: 2,
      placeholder: '用于首页与搜索引擎展示的站点简介' },
  ];

  const state = {
    tab: 'site',
    site: null,       // 站点信息当前值（用于「是否有改动」比较）
    siteCtl: {},      // 站点信息控件
    meta: null,       // /api/admin/settings 的 {groups, fields}
    metaError: '',
  };

  function skeletonBlock() {
    return el('div.card', {}, el('div.card-body.stack-sm', {}, [
      el('div.skeleton.skeleton-title'),
      el('div.skeleton.skeleton-text'),
      el('div.skeleton.skeleton-text'),
      el('div.skeleton.skeleton-text'),
    ]));
  }

  function renderTabs() {
    const items = [{ key: 'site', label: '站点信息' }];
    for (const [key, g] of Object.entries(state.meta?.groups || {})) {
      items.push({ key, label: g.label });
    }
    mount(tabSlot, tabs(items, state.tab, (k) => { state.tab = k; renderTabs(); render(); }));
  }

  function render() {
    if (state.tab === 'site') { renderSite(); return; }
    renderGroup(state.tab);
  }

  /* -------------------- 站点信息 -------------------- */

  async function renderSite() {
    mount(bodySlot, skeletonBlock());
    if (!state.site) {
      try {
        state.site = (await adminApi.config()) || {};
      } catch (e) {
        mount(bodySlot, alertBox(e?.message || '配置加载失败', { type: 'danger' }));
        return;
      }
    }

    const values = state.site;
    const form = el('div.form-grid');
    state.siteCtl = {};
    for (const f of SITE_FIELDS) {
      const ctl = f.type === 'textarea'
        ? textarea({ name: f.name, value: values[f.name] ?? '', placeholder: f.placeholder || '', rows: f.rows || 4 })
        : input({ name: f.name, value: values[f.name] ?? '', placeholder: f.placeholder || '', maxlength: f.maxlength || '' });
      state.siteCtl[f.name] = ctl;
      const wrap = field(f.label, ctl);
      if (f.colSpan === 2) wrap.classList.add('span-2');
      form.append(wrap);
    }

    const saveBtn = button('保存站点信息', { variant: 'primary', iconName: 'save' });
    saveBtn.addEventListener('click', async () => {
      const payload = {};
      for (const f of SITE_FIELDS) payload[f.name] = state.siteCtl[f.name].value.trim();
      const changed = Object.entries(payload).filter(([k, v]) => v !== (values[k] ?? ''));
      if (!changed.length) { notify.info('配置没有变化'); return; }

      const { ok, error } = await withLoading(saveBtn, () => adminApi.saveConfig(payload), { silent: true });
      if (ok) {
        notify.success('站点信息已保存');
        state.site = null;
        renderSite();
      } else {
        notify.error(error?.message || '保存失败');
      }
    });

    mount(bodySlot, card({
      title: '站点信息',
      iconName: 'settings',
      body: el('div.stack', {}, [
        el('div.page-desc', { text: '以下信息会展示在前台门户、考生端页脚等位置' }),
        form,
      ]),
      footer: el('div.flex.justify-end.gap-2', {}, [
        button('重新加载', { variant: 'secondary', iconName: 'refresh', onClick: () => { state.site = null; renderSite(); } }),
        saveBtn,
      ]),
    }));
  }

  /* -------------------- 运行参数（schema 驱动） -------------------- */

  async function ensureMeta() {
    if (state.meta) return true;
    try {
      state.meta = await adminApi.settings();
      state.metaError = '';
    } catch (e) {
      state.meta = { groups: {}, fields: [] };
      state.metaError = e?.message || '设置加载失败';
    }
    renderTabs();
    return state.metaError === '';
  }

  async function renderGroup(key) {
    mount(bodySlot, skeletonBlock());
    if (!(await ensureMeta())) {
      mount(bodySlot, alertBox(state.metaError, { type: 'danger' }));
      return;
    }

    const group = state.meta.groups?.[key] || { label: key, desc: '' };
    const fields = (state.meta.fields || []).filter((f) => f.group === key);
    if (!fields.length) {
      mount(bodySlot, emptyStated('该分组暂无设置项', { iconName: 'sliders' }));
      return;
    }

    const ctl = {};
    const form = el('div.form-grid');
    for (const f of fields) {
      let node;
      if (f.type === 'bool') {
        // 传入 name 以便与其它表单控件一致地按字段名寻址
        const sw = switchToggle('', { name: f.key, checked: Number(f.value) === 1 });
        node = el('div.flex.items-center', { style: { height: '38px' } }, [sw]);
        ctl[f.key] = { type: 'bool', input: sw.querySelector('input') };
      } else {
        node = input({
          name: f.key, type: 'number', value: String(f.value),
          min: f.min ?? '', max: f.max ?? '', step: 1, suffix: f.unit || '',
        });
        ctl[f.key] = { type: 'int', input: node.querySelector('input'), field: f };
      }
      form.append(field(f.label, node, { hint: f.hint }));
    }

    const saveBtn = button('保存设置', { variant: 'primary', iconName: 'save' });
    saveBtn.addEventListener('click', async () => {
      const payload = {};
      for (const f of fields) {
        const c = ctl[f.key];
        if (c.type === 'bool') { payload[f.key] = c.input.checked ? 1 : 0; continue; }

        const raw = String(c.input.value).trim();
        const n = Number(raw);
        if (raw === '' || !Number.isFinite(n)) { notify.error(`「${f.label}」请填写数字`); c.input.focus(); return; }
        if (f.min !== null && f.max !== null && (n < f.min || n > f.max)) {
          notify.error(`「${f.label}」需在 ${f.min}–${f.max} 之间`);
          c.input.focus();
          return;
        }
        payload[f.key] = Math.trunc(n);
      }

      const { ok, error } = await withLoading(saveBtn, () => adminApi.saveSettings(payload), { silent: true });
      if (ok) {
        notify.success('设置已保存，即时生效');
        state.meta = null;            // 重新拉取（可能已被规范化）
        invalidateAppSettings();      // 让本客户端的公开参数缓存失效
        renderTabs();
        renderGroup(key);
      } else {
        notify.error(error?.message || '保存失败');
      }
    });

    const resetBtn = button('恢复默认', { variant: 'secondary', iconName: 'refresh', onClick: () => {
      for (const f of fields) {
        const c = ctl[f.key];
        if (c.type === 'bool') c.input.checked = Number(f.default) === 1;
        else c.input.value = String(f.default);
      }
      notify.info('已填入默认值，确认后请点击保存');
    } });

    mount(bodySlot, card({
      title: group.label,
      iconName: 'sliders',
      body: el('div.stack', {}, [
        group.desc ? el('div.page-desc', { text: group.desc }) : null,
        form,
        alertBox('设置保存后立即生效，无需重启服务。', { type: 'info' }),
      ].filter(Boolean)),
      footer: el('div.flex.justify-end.gap-2', {}, [resetBtn, saveBtn]),
    }));
  }

  /* -------------------- 启动 -------------------- */

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [
        el('h2.page-title', { text: '系统设置' }),
        el('div.page-desc', { text: '站点展示信息与运行参数（入场窗口、限流、分页等）' }),
      ]),
    ]),
    tabSlot,
    bodySlot,
  );

  renderTabs();
  render();
  // 后台补拉设置分组，用于渲染其余标签页
  ensureMeta().then(() => { if (state.tab !== 'site') render(); });

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

  /* -------------------- 头像上传 -------------------- */
  let avatarUrl = admin.avatar || '';
  const avatarBox = el('div.avatar.avatar-xl.avatar-upload', {
    title: '点击上传头像',
    on: { click: () => avatarFile.click() },
  });
  const avatarFile = el('input', { type: 'file', accept: 'image/*', style: { display: 'none' } });

  function renderAvatar() {
    clear(avatarBox);
    if (avatarUrl) {
      const img = el('img', { src: avatarUrl, alt: '头像' });
      img.onerror = () => {
        img.remove();
        avatarBox.textContent = (admin.username || '?').slice(0, 1).toUpperCase();
        avatarBox.style.background = '';
      };
      avatarBox.append(img);
    } else {
      avatarBox.textContent = (admin.username || '?').slice(0, 1).toUpperCase();
      avatarBox.style.background = '';
    }
  }
  renderAvatar();

  avatarFile.addEventListener('change', async () => {
    const file = avatarFile.files?.[0];
    if (!file) return;
    // 两步：先上传到 /uploads 拿到 url，再回填到 admininfo.avatar
    const fd = new FormData();
    fd.append('file', file);
    const up = await withLoading(null, () => adminApi.uploadPic(fd), { silent: true });
    if (!up.ok) { notify.error(up.error?.message || '头像上传失败'); return; }
    const url = up.result?.url || '';
    const saved = await withLoading(null, () => adminApi.uploadAvatar({ avatar: url }), { silent: true });
    if (!saved.ok) { notify.error(saved.error?.message || '头像保存失败'); return; }
    avatarUrl = saved.result?.avatar || url;
    if (session?.admin) session.admin.avatar = avatarUrl;
    renderAvatar();
    shell?.updateUser?.({ avatar: avatarUrl });
    notify.success('头像已更新');
  });

  const infoCard = card({
    title: '基本信息',
    iconName: 'user',
    body: el('div.stack', {}, [
      el('div.flex.items-center.gap-4', {}, [
        avatarBox,
        avatarFile,
        el('div', {}, [
          el('div.fw-700', { style: { fontSize: 'var(--fs-xl)' }, text: admin.username || '—' }),
          el('div.mt-1', {}, [badge(ROLE_LABELS[admin.admin_power] || admin.admin_power || '—', { tone: 'brand' })]),
          el('div.fs-xs.c-tertiary.mt-2', { text: '点击左侧头像可上传更换（JPG/PNG，≤2MB）' }),
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
  const newPwd = input({ type: 'password', name: 'new_password', placeholder: `${passwordHintText()}，不能为纯数字`, autocomplete: 'new-password' });
  // 设置可能稍后到达：就绪后校正提示文案
  loadAppSettings().then(() => { newPwd.placeholder = `${passwordHintText()}，不能为纯数字`; });
  const confirmPwd = input({ type: 'password', name: 'confirm_password', placeholder: '请再次输入新密码', autocomplete: 'new-password' });
  const pwdErr = el('div');

  const savePwdBtn = button('修改密码', { variant: 'primary', iconName: 'lock' });
  savePwdBtn.addEventListener('click', async () => {
    clear(pwdErr);
    if (!oldPwd.value) { pwdErr.append(alertBox('请输入当前密码', { type: 'warning' })); return; }
    if (newPwd.value.length < appSettingInt('password_min_length', 6)) { pwdErr.append(alertBox(`新密码${passwordHintText()}`, { type: 'warning' })); return; }
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
