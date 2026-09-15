/**
 * 考生登录 / 注册页 —— 门户与个人中心共用。
 */

import { el, clear, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { renderLogin } from '../../ui/login.js';
import { button, field, input, select, notify } from '../../ui/components.js';
import { studentApi } from '../../api/index.js';
import { studentSession } from '../../core/student-session.js';
import { withLoading } from '../../core/bootstrap.js';
import { passwordHintText, appSettingInt, loadAppSettings } from '../../core/app-settings.js';

/**
 * @param {{router, query?, onDone?:Function}} cfg
 */
export function StudentLoginView({ router, query, onDone }) {
  const redirect = query?.redirect || '/student';

  const page = renderLogin({
    title: '考生登录',
    subtitle: '使用准考证号或姓名登录',
    accent: 'graduation-cap',
    brandMark: '考',
    fields: [
      { name: 'username', label: '准考证号 / 姓名', placeholder: '请输入准考证号或姓名', autocomplete: 'username' },
      { name: 'password', label: '密码', type: 'password', placeholder: '请输入密码', autocomplete: 'current-password' },
    ],
    onSubmit: async (values) => {
      try {
        const data = await studentApi.login({ username: values.username, password: values.password });
        studentSession.accept(data);
        notify.success(`欢迎回来，${data?.student?.stu_name || ''}`);
        // onDone 由各个入口自行决定落地页（考生中心页内走 startShell，
        // 门户/其它页走整页跳转）。兜底分支的 redirect 指向真实页面路径，
        // 必须用整页跳转而非 router.navigate（后者只会改写 hash）。
        if (onDone) onDone(); else location.assign(redirect);
      } catch (e) {
        notify.error(e?.message || '登录失败，请检查账号或密码后重试');
      }
    },
    extra: [
      el('div.login-extra-row', {}, [
        el('span.muted', { text: '还没有账号？' }),
        button('立即注册', { variant: 'link', size: 'sm', onClick: () => router.navigate('/register') }),
      ]),
      el('div.login-extra-row', {}, [
        button('返回首页', { variant: 'link', size: 'sm', iconName: 'arrow-left', onClick: () => router.navigate('/') }),
      ]),
    ],
  });

  return page;
}

/**
 * 考生注册页。
 */
export function StudentRegisterView({ router }) {
  const root = el('div.login-page');
  const errSlot = el('div');

  const idIn = input({ name: 'stu_id', required: true, placeholder: '准考证号（纯数字）', inputmode: 'numeric' });
  const nameIn = input({ name: 'stu_name', required: true, maxlength: 50, placeholder: '真实姓名' });
  const sexSel = select([{ value: '男', label: '男' }, { value: '女', label: '女' }], { name: 'stu_sex', value: '男' });
  const gradeSel = select([], { name: 'grade_id', placeholder: '加载中…' });
  const classSel = select([], { name: 'class_id', placeholder: '加载中…' });
  const pwdIn = input({ name: 'password', type: 'password', required: true, autocomplete: 'new-password', placeholder: passwordHintText() });
  loadAppSettings().then(() => { pwdIn.placeholder = passwordHintText(); });
  const pwd2In = input({ name: 'confirm', type: 'password', required: true, autocomplete: 'new-password', placeholder: '再次输入密码' });

  const submitBtn = button('注 册', { variant: 'primary', type: 'submit', block: true, size: 'lg' });

  const form = el('form.login-form', { on: { submit: (e) => { e.preventDefault(); submit(); } } }, [
    field('准考证号', idIn, { required: true, hint: '用于登录，注册后不可修改' }),
    field('姓名', nameIn, { required: true }),
    field('性别', sexSel),
    field('单位', gradeSel),
    field('班级', classSel),
    field('密码', pwdIn, { required: true }),
    field('确认密码', pwd2In, { required: true }),
    errSlot,
    submitBtn,
  ]);

    // 加载下拉数据（value 用 ID：后端排卷/待考列表一律按 class_id 的 ID 匹配，
    // 提交名称会让该考生此后匹配不到任何考试）
    (async () => {
      const res = await studentApi.registerOptions().catch(() => null);
      if (!res) return;
      fill(gradeSel, (res.grades || []).map((g) => ({ value: String(g.id), label: g.grade_name || String(g.id) })), '请选择单位');
      fill(classSel, (res.classes || []).map((c) => ({ value: String(c.id), label: c.class_name || String(c.id) })), '请选择班级');
    })();

  function fill(sel, opts, placeholder) {
    clear(sel);
    sel.append(el('option', { value: '', text: placeholder }));
    for (const o of opts) sel.append(el('option', { value: o.value, text: o.label }));
  }

  async function submit() {
    clear(errSlot);
    const id = idIn.value.trim();
    if (!/^\d{1,20}$/.test(id)) {
      errSlot.append(el('div.alert.alert-danger', {}, [el('span', { text: '准考证号必须为数字' })]));
      return;
    }
    if (pwdIn.value.length < appSettingInt('password_min_length', 6)) {
      errSlot.append(el('div.alert.alert-danger', {}, [el('span', { text: `密码${passwordHintText()}` })]));
      return;
    }
    if (pwdIn.value !== pwd2In.value) {
      errSlot.append(el('div.alert.alert-danger', {}, [el('span', { text: '两次输入的密码不一致' })]));
      return;
    }
    const res = await withLoading(form, () => studentApi.register({
      stu_id: id,
      stu_name: nameIn.value.trim(),
      password: pwdIn.value,
      stu_sex: sexSel.value,
      grade_id: gradeSel.value,
      class_id: classSel.value,
    }));
    if (!res.ok) {
      errSlot.append(el('div.alert.alert-danger', {}, [el('span', { text: res.error?.message || '注册失败' })]));
      return;
    }
    notify.success('注册成功，请登录');
    router.navigate('/login');
  }

  root.append(el('div.login-aurora'));
  root.append(el('div.login-panel', {}, [
    el('div.login-brand', {}, [
      el('div.brand-mark', { style: { width: '42px', height: '42px' } }, [icon('user-plus', { size: 21 })]),
      el('div', {}, [
        el('h1', { text: '考生注册' }),
        el('p.muted', { text: '填写真实信息完成注册' }),
      ]),
    ]),
    form,
    el('div.login-extra', {}, [
      el('div.login-extra-row', {}, [
        el('span.muted', { text: '已有账号？' }),
        button('返回登录', { variant: 'link', size: 'sm', onClick: () => router.navigate('/login') }),
      ]),
    ]),
  ]));
  return root;
}
