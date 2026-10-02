/**
 * 通用登录页 —— 三端共用，通过配置区分文案与字段。
 */

import { el, clear } from '../core/dom.js';
import { logoMark } from '../core/logo.js';
import { appName } from '../core/brand.js';
import { button, field, input, alertBox, bindFormErrors } from './components.js';

/**
 * 渲染登录页
 * @param {object} cfg
 * @param {string} cfg.title
 * @param {string} cfg.subtitle
 * @param {{name:string,label:string,type?:string,placeholder?:string,value?:string,autocomplete?:string,inputmode?:string}[]} cfg.fields
 * @param {(values:object)=>Promise<any>} cfg.onSubmit
 * @param {Node[]} [cfg.extra]         底部附加内容
 * @param {(values:object)=>object} [cfg.beforeSubmit]  提交前转换
 * @param {{hint?:string, demo?:Node}} [cfg.aside]      侧栏补充
 */
export function renderLogin(cfg) {
  const { title, subtitle, fields, onSubmit, extra = [], beforeSubmit } = cfg;

  const form = el('form', { class: 'login-form' });
  const controls = {};

  for (const f of fields) {
    const ctl = input({
      name: f.name,
      type: f.type || 'text',
      placeholder: f.placeholder || '',
      value: f.value || '',
      required: true,
      autocomplete: f.autocomplete || (f.type === 'password' ? 'current-password' : 'username'),
      inputmode: f.inputmode || '',
    });
    controls[f.name] = ctl;
    form.append(field(f.label, ctl, { required: true }));
  }

  const errSlot = el('div');
  const submitBtn = button('登 录', { variant: 'primary', type: 'submit', block: true, size: 'lg' });
  form.append(submitBtn, errSlot);

  // 提示区：写前先清（不叠加）+ 输入即复核（改正后提示及时消失）。
  // 此前只有提交时 clear，用户补全字段后红框与提示仍残留，看起来像改动没生效。
  const { showErr, clearErr } = bindFormErrors(form, errSlot, () => {
    const vals = {};
    for (const [k, ctl] of Object.entries(controls)) vals[k] = ctl.value.trim();
    // 先整体摘掉旧红框，再按当前值重标，避免「改好了仍是红框」
    Object.values(controls).forEach((c) => c.classList.remove('is-invalid'));
    const missing = fields.filter((f) => !vals[f.name]);
    missing.forEach((f) => controls[f.name].classList.add('is-invalid'));
    return missing.length ? ['请填写完整的登录信息'] : [];
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErr();
    Object.values(controls).forEach((c) => c.classList.remove('is-invalid'));

    const values = {};
    for (const [k, ctl] of Object.entries(controls)) values[k] = ctl.value.trim();

    const missing = fields.filter((f) => !values[f.name]);
    if (missing.length) {
      missing.forEach((f) => controls[f.name].classList.add('is-invalid'));
      showErr('请填写完整的登录信息', 'warning');
      controls[missing[0].name].focus();
      return;
    }

    const payload = beforeSubmit ? beforeSubmit(values) : values;
    submitBtn.disabled = true;
    submitBtn.classList.add('is-loading');
    const sp = el('span.spinner');
    submitBtn.prepend(sp);

    try {
      await onSubmit(payload);
    } catch (error) {
      showErr(error?.message || '登录失败，请重试', 'danger');
      const last = fields[fields.length - 1]?.name;
      if (last && controls[last]) { controls[last].focus(); controls[last].select?.(); }
    } finally {
      submitBtn.disabled = false;
      submitBtn.classList.remove('is-loading');
      sp.remove();
    }
  });

  const panel = el('div.login-panel', {}, [
    el('div.login-brand', {}, [
      // 品牌标识：墨色取 --logo-ink（亮底深蓝 / 暗底浅蓝，见 tokens.css）
      logoMark({ height: 42 }),
      el('div', {}, [
        el('h1', { style: { fontSize: 'var(--fs-xl)', marginBottom: '2px' }, text: title }),
        el('p.c-secondary.fs-sm', { style: { margin: '0' }, text: subtitle }),
      ]),
    ]),
    form,
    extra.length ? el('div.login-extra', {}, extra) : null,
  ].filter(Boolean));

  return el('div.login-page', {}, [
    el('div.login-aurora'),
    panel,
    el('div.login-foot', { text: `© ${new Date().getFullYear()} ${appName()} · fastapi-php` }),
  ]);
}
