/**
 * 通用登录页 —— 三端共用，通过配置区分文案与字段。
 */

import { el, clear } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { button, field, input, alertBox } from './components.js';

/**
 * 渲染登录页
 * @param {object} cfg
 * @param {string} cfg.title
 * @param {string} cfg.subtitle
 * @param {string} [cfg.accent]        品牌图标名
 * @param {string} [cfg.brandMark]     品牌字（单字）
 * @param {{name:string,label:string,type?:string,placeholder?:string,value?:string,autocomplete?:string,inputmode?:string}[]} cfg.fields
 * @param {(values:object)=>Promise<any>} cfg.onSubmit
 * @param {Node[]} [cfg.extra]         底部附加内容
 * @param {(values:object)=>object} [cfg.beforeSubmit]  提交前转换
 * @param {{hint?:string, demo?:Node}} [cfg.aside]      侧栏补充
 */
export function renderLogin(cfg) {
  const { title, subtitle, accent = 'shield', brandMark = '考', fields, onSubmit, extra = [], beforeSubmit } = cfg;

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

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clear(errSlot);
    Object.values(controls).forEach((c) => c.classList.remove('is-invalid'));

    const values = {};
    for (const [k, ctl] of Object.entries(controls)) values[k] = ctl.value.trim();

    const missing = fields.filter((f) => !values[f.name]);
    if (missing.length) {
      missing.forEach((f) => controls[f.name].classList.add('is-invalid'));
      errSlot.append(alertBox('请填写完整的登录信息', { type: 'warning' }));
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
      errSlot.append(alertBox(error?.message || '登录失败，请重试', { type: 'danger' }));
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
      el('div.brand-mark', { style: { width: '42px', height: '42px', fontSize: 'var(--fs-lg)' } },
        [icon(accent, { size: 22 })]),
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
    el('div.login-foot', { text: `© ${new Date().getFullYear()} 在线考试系统 · fastapi-php` }),
  ]);
}
