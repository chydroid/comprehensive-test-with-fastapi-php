/**
 * UI 组件库 —— 声明式地构建常用界面元素。
 * 所有组件返回 DOM 节点，调用方自行挂载。
 */

import { el, clear, mount, $, $$, trapFocus, uid } from '../core/dom.js';
import { icon } from '../core/icons.js';

/* ============================ 按钮 ============================ */
export function button(label, {
  variant = 'secondary', size = '', iconName = '', onClick, type = 'button',
  disabled = false, loading = false, block = false, title = '', class: cls = '',
} = {}) {
  const attrs = {
    type,
    class: `btn btn-${variant}${size ? ` btn-${size}` : ''}${block ? ' btn-block' : ''}${iconName && !label ? ' btn-icon' : ''}${cls ? ` ${cls}` : ''}`,
    disabled: disabled || loading,
    title,
  };
  if (onClick) attrs.on = { click: onClick };
  const node = el('button', attrs);
  if (loading) node.append(el('span.spinner'));
  if (iconName) node.append(icon(iconName, { size: size === 'sm' ? 15 : size === 'lg' ? 20 : 17 }));
  if (label) node.append(el('span', { text: label }));
  return node;
}

/* ============================ 徽标 ============================ */
export function badge(text, { tone = '', dot = false, pulse = false, title = '' } = {}) {
  return el('span', {
    class: `badge${tone ? ` badge-${tone}` : ''}${dot ? ' badge-dot' : ''}${pulse ? ' badge-pulse' : ''}`,
    title,
    text: String(text),
  });
}

/* ============================ 表单控件 ============================ */
export function field(label, control, { hint = '', required = false, error = '' } = {}) {
  const wrap = el('div.field');
  if (label) {
    wrap.append(el('label.field-label', {}, [
      el('span', { text: label }),
      required ? el('span.req', { text: '*' }) : null,
    ]));
  }
  wrap.append(control);
  if (error) wrap.append(el('div.field-error', {}, [icon('alert-circle', { size: 13 }), el('span', { text: error })]));
  else if (hint) wrap.append(el('div.field-hint', { text: hint }));
  return wrap;
}

export function input({
  name, value = '', placeholder = '', type = 'text', required = false,
  disabled = false, inputmode = '', maxlength = '', autocomplete = '', class: cls = '',
  min = '', max = '', step = '', suffix = '',
} = {}) {
  const node = el('input', {
    class: `input${cls ? ` ${cls}` : ''}`, name, value, placeholder, type, required, disabled,
    inputmode: inputmode || null, maxlength: maxlength || null,
    autocomplete: autocomplete || null,
    min: min === '' ? null : min, max: max === '' ? null : max, step: step === '' ? null : step,
  });
  // 单位后缀（如「分钟」「条」）只作展示，复用 input-group 的 addon 样式
  if (!suffix) return node;
  return el('div.input-group', {}, [node, el('span.input-addon', { text: suffix })]);
}

export function textarea({ name, value = '', placeholder = '', rows = 4, disabled = false } = {}) {
  const t = el('textarea', { class: 'textarea', name, placeholder, rows, disabled });
  t.value = value ?? '';
  return t;
}

/**
 * @param {{value:string,label:string}[]} options
 */
export function select(options, { name, value = '', placeholder = '', disabled = false, class: cls = '' } = {}) {
  const s = el('select', { class: `select${cls ? ` ${cls}` : ''}`, name, disabled });
  if (placeholder !== null) s.append(el('option', { value: '', text: placeholder || '请选择', selected: value === '' }));
  for (const opt of options) {
    s.append(el('option', {
      value: String(opt.value),
      text: opt.label,
      selected: String(opt.value) === String(value),
      disabled: opt.disabled || false,
    }));
  }
  return s;
}

export function checkbox(label, { name, checked = false, value = '1', disabled = false } = {}) {
  const id = uid('cb');
  const box = el('input', { type: 'checkbox', id, name, checked, value, disabled });
  return el('label.checkbox', { for: id }, [box, el('span', { text: label })]);
}

export function switchToggle(label, { name, checked = false, disabled = false, onChange } = {}) {
  const id = uid('sw');
  const cb = el('input', { type: 'checkbox', id, name, checked, disabled });
  if (onChange) cb.addEventListener('change', () => onChange(cb.checked));
  const wrap = el('label.switch', { for: id }, [
    cb,
    el('span.slider', { class: `slider${checked ? ' is-on' : ''}` }),
  ]);
  if (!label) return wrap;
  return el('div.flex.items-center.gap-2', {}, [wrap, el('span.fs-sm', { text: label })]);
}

/** 搜索框：带图标 + 防抖回调 */
export function searchBox({ placeholder = '搜索…', onInput, value = '', name = 'keyword' } = {}) {
  const wrap = el('div.search-box');
  wrap.append(el('span.search-icon', {}, [icon('search', { size: 16 })]));
  const i = el('input.input', { type: 'search', name, placeholder, value, autocomplete: 'off' });
  if (onInput) i.addEventListener('input', (e) => onInput(e.target.value));
  wrap.append(i);
  return wrap;
}

/* ============================ 卡片 ============================ */
export function card({ title = '', actions = [], body = null, footer = null, class: cls = '', hover = false, iconName = '' } = {}) {
  const c = el('div', { class: `card${hover ? ' card-hover' : ''}${cls ? ` ${cls}` : ''}` });
  if (title || actions.length) {
    const head = el('div.card-header');
    head.append(el('div.card-title', {}, [
      iconName ? icon(iconName, { size: 18 }) : null,
      el('span', { text: title }),
    ].filter(Boolean)));
    if (actions.length) head.append(el('div.flex.items-center.gap-2', {}, actions));
    c.append(head);
  }
  if (body) c.append(el('div.card-body', {}, Array.isArray(body) ? body : [body]));
  if (footer) c.append(el('div.card-footer', {}, Array.isArray(footer) ? footer : [footer]));
  return c;
}

export function statCard({ label, value, iconName = 'activity', tone = '' }) {
  return el('div.card.stat-card', {}, [
    el('div', { class: `stat-icon${tone ? ` tone-${tone}` : ''}` }, [icon(iconName, { size: 22 })]),
    el('div.flex-1', {}, [
      el('div.stat-value', { text: String(value) }),
      el('div.stat-label', { text: label }),
    ]),
  ]);
}

/* ============================ 表格 ============================ */
/**
 * 构建表格
 * @param {object} cfg
 * @param {{key:string,title:string,align?:string,width?:string,render?:(row:any,index:number)=>Node|string,sortable?:boolean}[]} cfg.columns
 * @param {any[]} cfg.rows
 * @param {(row:any)=>void} [cfg.onRowClick]
 * @param {string} [cfg.emptyText]
 * @param {string} [cfg.sort]     当前排序键
 * @param {string} [cfg.order]    asc|desc
 * @param {(key:string)=>void} [cfg.onSort]
 * @param {(row:any)=>boolean} [cfg.isSelected]
 */
export function table({ columns, rows, onRowClick, emptyText = '暂无数据', sort = '', order = 'asc', onSort, isSelected, size = '' }) {
  const wrap = el('div.table-wrap');
  const t = el('table', { class: `table${size === 'sm' ? ' table-sm' : ''}` });

  /* 表头 */
  const thead = el('thead');
  const trh = el('tr');
  for (const col of columns) {
    const isSorted = sort === col.key;
    const th = el('th', {
      class: `${col.align === 'right' ? 'col-num' : ''}${col.align === 'center' ? ' text-center' : ''}${col.sortable && onSort ? ' sortable' : ''}${isSorted ? (order === 'asc' ? ' sort-asc' : ' sort-desc') : ''}`,
      style: col.width ? { width: col.width } : null,
    }, [el('span', { text: col.title })]);
    if (col.sortable && onSort) {
      th.append(el('span.sort-ind', {}, [icon(isSorted && order === 'desc' ? 'chevronDown' : 'chevronUp', { size: 12 })]));
      th.addEventListener('click', () => onSort(col.key));
    }
    trh.append(th);
  }
  thead.append(trh);
  t.append(thead);

  /* 表体 */
  const tbody = el('tbody');
  if (!rows.length) {
    const td = el('td', { colspan: columns.length });
    td.append(emptyStated(emptyText));
    tbody.append(el('tr', {}, [td]));
  } else {
    rows.forEach((row, i) => {
      const tr = el('tr', { class: isSelected?.(row) ? 'is-selected' : '' });
      if (onRowClick) {
        tr.style.cursor = 'pointer';
        tr.addEventListener('click', (e) => {
          // 点击操作按钮/复选框时不触发行点击
          if (e.target.closest('button, a, input, label, select')) return;
          onRowClick(row, i);
        });
      }
      for (const col of columns) {
        const td = el('td', {
          class: `${col.align === 'right' ? 'col-num' : ''}${col.align === 'center' ? ' text-center' : ''}${col.class || ''}`,
        });
        const content = col.render ? col.render(row, i) : row[col.key];
        if (content instanceof Node) td.append(content);
        else td.textContent = content === null || content === undefined || content === '' ? '—' : String(content);
        tr.append(td);
      }
      // 选中态联动（简易）
      tr._row = row;
      tbody.append(tr);
    });
  }
  t.append(tbody);
  wrap.append(t);
  return wrap;
}

/** 行内操作按钮组 */
export function rowActions(actions) {
  return el('div.flex.items-center.gap-1.justify-end', {}, actions.filter(Boolean));
}

/* ============================ 空态 ============================ */
export function emptyStated(text = '暂无数据', { iconName = 'inbox', desc = '', action = null } = {}) {
  return el('div.empty', {}, [
    el('span.empty-icon', {}, [icon(iconName, { size: 46, stroke: 1.3 })]),
    el('div.empty-title', { text: text }),
    desc ? el('div.empty-desc', { text: desc }) : null,
    action,
  ].filter(Boolean));
}

/* ============================ 分页 ============================ */
/**
 * @param {{page:number,total:number,per_page:number,onPage:(p:number)=>void}} cfg
 */
export function pagination({ page, total, per_page, onPage }) {
  const totalPages = Math.max(1, Math.ceil(total / Math.max(1, per_page)));
  const wrap = el('div.pagination');

  const mk = (label, targetPage, { disabled = false, active = false } = {}) => {
    const b = el('button', {
      class: `page-btn${active ? ' is-active' : ''}`,
      type: 'button', disabled,
      text: String(label),
    });
    if (!disabled && !active) b.addEventListener('click', () => onPage(targetPage));
    return b;
  };

  wrap.append(mk('‹', page - 1, { disabled: page <= 1 }));

  // 页码窗口
  const win = [];
  const push = (p) => { if (p >= 1 && p <= totalPages && !win.includes(p)) win.push(p); };
  push(1);
  for (let p = page - 1; p <= page + 1; p++) push(p);
  push(totalPages);
  win.sort((a, b) => a - b);

  let prev = 0;
  for (const p of win) {
    if (prev && p - prev > 1) {
      wrap.append(el('span.c-tertiary.fs-sm', { text: '…' }));
    }
    wrap.append(mk(p, p, { active: p === page }));
    prev = p;
  }

  wrap.append(mk('›', page + 1, { disabled: page >= totalPages }));
  wrap.append(el('span.page-info', {
    text: `共 ${total} 条 · 第 ${page}/${totalPages} 页`,
  }));
  return wrap;
}

/* ============================ 弹窗 ============================ */
/**
 * 打开弹窗
 * @returns {{close:()=>void, root:HTMLElement}}
 */
export function openModal({ title, body, footer = null, size = '', onClose, closable = true } = {}) {
  const backdrop = el('div.modal-backdrop');
  const modal = el('div', { class: `modal${size ? ` modal-${size}` : ''}`, role: 'dialog', 'aria-modal': 'true' });
  const titleId = uid('mt');
  modal.setAttribute('aria-labelledby', titleId);

  const head = el('div.modal-header', {}, [
    el('h3.modal-title', { id: titleId, text: title || '' }),
    closable ? el('button.modal-close', {
      type: 'button', 'aria-label': '关闭',
      on: { click: () => close() },
    }, [icon('x', { size: 18 })]) : null,
  ].filter(Boolean));

  const bodyEl = el('div.modal-body');
  mount(bodyEl, Array.isArray(body) ? body : [body]);
  modal.append(head, bodyEl);
  if (footer) modal.append(el('div.modal-footer', {}, Array.isArray(footer) ? footer : [footer]));
  backdrop.append(modal);

  let released = null;
  function close() {
    if (!backdrop.isConnected) return;
    released?.();
    backdrop.remove();
    document.removeEventListener('keydown', onKey);
    if (lastFocused && lastFocused.isConnected) lastFocused.focus();
    onClose?.();
  }
  function onKey(e) {
    if (e.key === 'Escape' && closable) { e.stopPropagation(); close(); }
  }

  const lastFocused = document.activeElement;
  if (closable) backdrop.addEventListener('mousedown', (e) => { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', onKey);

  document.body.append(backdrop);
  document.body.style.overflow = 'hidden';
  released = trapFocus(modal);

  // 恢复滚动
  const origClose = close;
  const wrappedClose = () => {
    origClose();
    if (!$('.modal-backdrop')) document.body.style.overflow = '';
  };

  // 自动聚焦首个可交互元素
  requestAnimationFrame(() => {
    const target = $('input:not([type=hidden]), select, textarea, button', modal);
    target?.focus();
  });

  return { close: wrappedClose, root: modal, body: bodyEl };
}

/** 确认对话框（Promise） */
export function confirmDialog(message, {
  title = '确认操作', confirmText = '确认', cancelText = '取消', tone = 'danger', detail = '',
} = {}) {
  return new Promise((resolve) => {
    let settled = false;
    const done = (v) => { if (!settled) { settled = true; resolve(v); } };
    const body = el('div.flex.gap-3.items-start', {}, [
      el('span', { class: `stat-icon tone-${tone}`, style: { width: '36px', height: '36px' } },
        [icon('alert', { size: 19 })]),
      el('div.flex-1', {}, [
        el('div.fw-500.mb-1', { text: message }),
        detail ? el('div.fs-sm.c-secondary.pre-wrap', { text: detail }) : null,
      ].filter(Boolean)),
    ]);

    const confirmBtn = button(confirmText, {
      variant: tone, onClick: () => { done(true); dlg.close(); },
    });
    const dlg = openModal({
      title, body, size: 'sm',
      footer: [button(cancelText, { variant: 'secondary', onClick: () => { done(false); dlg.close(); } }), confirmBtn],
      onClose: () => done(false),
    });
    requestAnimationFrame(() => confirmBtn.focus());
  });
}

/** 抽屉 */
export function openDrawer({ title, body, footer = null, onClose } = {}) {
  const backdrop = el('div.drawer-backdrop');
  const drawer = el('aside.drawer', { role: 'dialog', 'aria-modal': 'true' });
  const head = el('div.modal-header', {}, [
    el('h3.modal-title', { text: title || '' }),
    el('button.modal-close', { type: 'button', 'aria-label': '关闭', on: { click: () => close() } }, [icon('x', { size: 18 })]),
  ]);
  const bodyEl = el('div.modal-body');
  mount(bodyEl, Array.isArray(body) ? body : [body]);
  drawer.append(head, bodyEl);
  if (footer) drawer.append(el('div.modal-footer', {}, Array.isArray(footer) ? footer : [footer]));
  backdrop.append(drawer);

  function close() {
    if (!backdrop.isConnected) return;
    backdrop.remove();
    document.removeEventListener('keydown', onKey);
    if (!$('.modal-backdrop')) document.body.style.overflow = '';
    onClose?.();
  }
  function onKey(e) { if (e.key === 'Escape') close(); }

  backdrop.addEventListener('mousedown', (e) => { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', onKey);
  document.body.append(backdrop);
  document.body.style.overflow = 'hidden';
  const released = trapFocus(drawer);
  return { close: () => { released(); close(); }, root: drawer, body: bodyEl };
}

/* ============================ Toast ============================ */
let toastHost = null;
function ensureToastHost() {
  if (!toastHost || !toastHost.isConnected) {
    toastHost = el('div.toast-container', { role: 'status', 'aria-live': 'polite' });
    document.body.append(toastHost);
  }
  return toastHost;
}

const TONE_ICON = { success: 'check-circle', error: 'x-circle', warning: 'alert-circle', info: 'info' };

export function toast(message, { type = 'info', title = '', duration = 3600 } = {}) {
  const host = ensureToastHost();
  const node = el('div', { class: `toast toast-${type}` }, [
    el('span.toast-icon', {}, [icon(TONE_ICON[type] || 'info', { size: 17 })]),
    el('div.toast-body', {}, [
      title ? el('div.toast-title', { text: title }) : null,
      el('div.toast-msg', { text: message }),
    ].filter(Boolean)),
    el('button.toast-close', { type: 'button', 'aria-label': '关闭', on: { click: () => dismiss() } }, [icon('x', { size: 14 })]),
  ]);

  let timer = null;
  function dismiss() {
    clearTimeout(timer);
    if (!node.isConnected) return;
    node.classList.add('is-leaving');
    setTimeout(() => node.remove(), 180);
  }
  if (duration > 0) timer = setTimeout(dismiss, duration);

  host.append(node);
  return dismiss;
}

export const notify = {
  success: (msg, opts) => toast(msg, { ...opts, type: 'success' }),
  error: (msg, opts) => toast(msg, { ...opts, type: 'error', duration: 5200 }),
  warning: (msg, opts) => toast(msg, { ...opts, type: 'warning', duration: 4800 }),
  info: (msg, opts) => toast(msg, { ...opts, type: 'info' }),
};

/* ============================ 提示条 ============================ */
export function alertBox(message, { type = 'info', title = '', action = null } = {}) {
  return el('div', { class: `alert alert-${type}` }, [
    el('span.alert-icon', {}, [icon(TONE_ICON[type] || 'info', { size: 17 })]),
    el('div.flex-1', {}, [
      title ? el('div.fw-600.mb-1', { text: title }) : null,
      el('div', { text: message }),
    ].filter(Boolean)),
    action,
  ].filter(Boolean));
}

/* ============================ 骨架屏 ============================ */
export function skeletonRows(count = 5, cols = 4) {
  const wrap = el('div', { style: { padding: 'var(--sp-4)' } });
  for (let r = 0; r < count; r++) {
    const row = el('div.flex.gap-4', { style: { marginBottom: 'var(--sp-3)' } });
    for (let c = 0; c < cols; c++) {
      row.append(el('div.skeleton.skeleton-text', { style: { flex: c === 0 ? '0 0 90px' : '1' } }));
    }
    wrap.append(row);
  }
  return wrap;
}

/* ============================ 加载遮罩 ============================ */
export function loadingOverlay(text = '加载中…') {
  return el('div.loading-overlay', {}, [
    el('div.flex.flex-col.items-center.gap-3', {}, [
      el('div.spinner-lg'),
      el('div.fs-sm.c-secondary', { text }),
    ]),
  ]);
}

/* ============================ 选项卡 ============================ */
/**
 * @param {{key:string,label:string,count?:number}[]} items
 */
export function tabs(items, activeKey, onChange) {
  const wrap = el('div.tabs', { role: 'tablist' });
  for (const item of items) {
    const b = el('button', {
      class: `tab${item.key === activeKey ? ' is-active' : ''}`,
      type: 'button', role: 'tab',
      'aria-selected': item.key === activeKey,
    }, [
      el('span', { text: item.label }),
      item.count !== undefined ? el('span.tab-count', { text: String(item.count) }) : null,
    ].filter(Boolean));
    b.addEventListener('click', () => onChange(item.key));
    wrap.append(b);
  }
  return wrap;
}

/* ============================ 分段控件 ============================ */
export function segmented(items, activeKey, onChange) {
  const wrap = el('div.segmented');
  for (const item of items) {
    const b = el('button', {
      class: item.key === activeKey ? 'is-active' : '',
      type: 'button', text: item.label,
    });
    b.addEventListener('click', () => onChange(item.key));
    wrap.append(b);
  }
  return wrap;
}

/* ============================ 工具条 ============================ */
export function toolbar(items) {
  const bar = el('div.toolbar');
  for (const it of items) {
    if (it === 'spacer') { bar.append(el('div.toolbar-spacer')); continue; }
    bar.append(it);
  }
  return bar;
}

/* ============================ 下拉菜单 ============================ */
export function dropdown(trigger, items) {
  const wrap = el('div.dropdown');
  const menu = el('div.dropdown-menu', { style: { display: 'none' } });
  for (const it of items) {
    if (it === '-') { menu.append(el('div.dropdown-divider')); continue; }
    if (typeof it === 'string') { menu.append(el('div.dropdown-label', { text: it })); continue; }
    const b = el('button', { class: `dropdown-item${it.danger ? ' is-danger' : ''}`, type: 'button', disabled: it.disabled }, [
      it.iconName ? icon(it.iconName, { size: 16 }) : null,
      el('span', { text: it.label }),
    ].filter(Boolean));
    if (!it.disabled) b.addEventListener('click', () => { close(); it.onClick?.(); });
    menu.append(b);
  }
  let open = false;
  function close() { open = false; menu.style.display = 'none'; document.removeEventListener('mousedown', onDoc); }
  function onDoc(e) { if (!wrap.contains(e.target)) close(); }
  wrap.append(trigger, menu);
  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    open = !open;
    menu.style.display = open ? 'block' : 'none';
    if (open) setTimeout(() => document.addEventListener('mousedown', onDoc), 0);
  });
  return wrap;
}

/* ============================ 进度条 ============================ */
export function progressBar(percent, { tone = '' } = {}) {
  const p = Math.max(0, Math.min(100, Number(percent) || 0));
  return el('div.progress', {}, [
    el('div', { class: `progress-bar${tone ? ` tone-${tone}` : ''}`, style: { width: `${p}%` } }),
  ]);
}

/* ============================ 键值列表 ============================ */
export function descList(rows) {
  const dl = el('div.dl');
  for (const [k, v] of rows) {
    if (v === null || v === undefined) continue;
    dl.append(el('div.dl-row', {}, [
      el('div.dl-key', { text: k }),
      v instanceof Node ? el('div.dl-val', {}, [v]) : el('div.dl-val', { text: String(v) }),
    ]));
  }
  return dl;
}

/**
 * 复制文本到剪贴板（优先 Clipboard API，失败降级 execCommand）。
 * @returns {Promise<boolean>} 是否复制成功
 */
export async function copyText(text) {
  const value = String(text ?? '');
  if (!value) return false;
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
      return true;
    }
  } catch (_) { /* 降级 */ }
  try {
    const ta = document.createElement('textarea');
    ta.value = value;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    ta.style.opacity = '0';
    document.body.append(ta);
    ta.select();
    const ok = document.execCommand('copy');
    ta.remove();
    return ok;
  } catch (_) {
    return false;
  }
}

/** 复制文本并给出提示 */
export async function copyWithToast(text, label = '内容') {
  const ok = await copyText(text);
  if (ok) notify.success(`${label}已复制到剪贴板`);
  else notify.warning('复制失败，请手动记录');
  return ok;
}

/** 只读展示块 */
export function codeBlock(text) {
  return el('pre', {
    class: 'mono fs-sm',
    style: {
      background: 'var(--bg-sunken)', border: '1px solid var(--border-subtle)',
      borderRadius: 'var(--radius-sm)', padding: 'var(--sp-3)',
      overflowX: 'auto', margin: '0', whiteSpace: 'pre-wrap', wordBreak: 'break-all',
    },
    text: String(text ?? ''),
  });
}
