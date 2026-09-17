/**
 * 极小 DOM 工具集 —— 不依赖任何框架。
 * 设计取向：显式、可预测、零魔法。所有文本一律走 textContent，杜绝 XSS。
 */

/** 选择单个元素 */
export const $ = (sel, root = document) => root.querySelector(sel);

/** 选择多个元素（返回真数组） */
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/**
 * 创建元素
 * @param {string} tag        标签名，可带类名简写 "div.card.body"
 * @param {object} [attrs]    属性；特殊键：class/text/html/on/dataset/style
 * @param {(Node|string)[]} [children]
 */
export function el(tag, attrs = {}, children = []) {
  const [name, ...classes] = tag.split('.');
  const node = document.createElement(name || 'div');
  if (classes.length) node.className = classes.join(' ');

  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    switch (k) {
      case 'class':
        node.className = node.className ? `${node.className} ${v}` : String(v);
        break;
      case 'text':
        node.textContent = String(v);
        break;
      case 'on':
        for (const [evt, fn] of Object.entries(v)) node.addEventListener(evt, fn);
        break;
      case 'dataset':
        for (const [dk, dv] of Object.entries(v)) node.dataset[dk] = String(dv);
        break;
      case 'style':
        if (typeof v === 'string') node.setAttribute('style', v);
        else Object.assign(node.style, v);
        break;
      case 'value':
      case 'checked':
      case 'disabled':
      case 'selected':
        node[k] = v;
        break;
      default:
        node.setAttribute(k, v === true ? '' : String(v));
    }
  }

  for (const c of flattenChildren([children])) {
    if (c === null || c === undefined || c === false) continue;
    node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return node;
}

/** 用 SVG 字符串创建元素（图标专用） */
export function svg(markup) {
  const tpl = document.createElement('template');
  tpl.innerHTML = markup.trim();
  return tpl.content.firstElementChild;
}

/**
 * 展平子节点入参：支持任意层级的嵌套数组，并过滤 null/undefined/布尔占位。
 * 说明：mount(...) / el(...) 允许调用方直接传数组（如 mount(slot, cards.map(...))），
 * 若只做一层 concat，嵌套数组本身会被 String() 成 "[object HTMLDivElement],..." 渲染出来。
 */
function flattenChildren(children) {
  const out = [];
  for (const c of children) {
    if (c === null || c === undefined || c === false || c === true) continue;
    if (Array.isArray(c)) out.push(...flattenChildren(c));
    else out.push(c);
  }
  return out;
}

/** 清空子节点 */
export function clear(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
  return node;
}

/** 替换内容 */
export function mount(node, ...children) {
  clear(node);
  for (const c of flattenChildren(children)) {
    node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return node;
}

/**
 * 取页面挂载根节点（#app），并清掉后端输出的启动占位（boot-splash）。
 * 各端入口统一调用，保证 SPA 首屏渲染后不残留 loading。
 */
export function appRoot() {
  const root = document.getElementById('app') || document.body;
  clear(root);
  if (typeof window.__APP_READY__ === 'function') {
    try { window.__APP_READY__(); } catch (_) { /* 忽略 */ }
  }
  const splash = document.querySelector('.boot-splash');
  if (splash) splash.remove();
  return root;
}

/** 事件委托 */
export function delegate(root, eventName, selector, handler) {
  root.addEventListener(eventName, (e) => {
    const target = e.target instanceof Element ? e.target.closest(selector) : null;    if (target && root.contains(target)) handler(e, target);
  });
}

/** 防抖 */
export function debounce(fn, wait = 300) {
  let timer = null;
  return function debounced(...args) {
    clearTimeout(timer);
    timer = setTimeout(() => fn.apply(this, args), wait);
  };
}

/** 节流 */
export function throttle(fn, wait = 200) {
  let last = 0, timer = null;
  return function throttled(...args) {
    const now = Date.now();
    const remain = wait - (now - last);
    if (remain <= 0) {
      last = now;
      fn.apply(this, args);
    } else if (!timer) {
      timer = setTimeout(() => {
        last = Date.now();
        timer = null;
        fn.apply(this, args);
      }, remain);
    }
  };
}

/** 转义为 HTML 文本（仅在必须拼字符串时使用） */
export function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, (m) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[m]));
}

/** 生成短随机 id */
export function uid(prefix = 'u') {
  return `${prefix}_${Math.random().toString(36).slice(2, 9)}`;
}

/** 浅层相等 */
export function shallowEqual(a, b) {
  if (a === b) return true;
  if (typeof a !== 'object' || typeof b !== 'object' || !a || !b) return false;
  const ka = Object.keys(a), kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  return ka.every((k) => a[k] === b[k]);
}

/**
 * 绑定表单：将 FormData 收集为普通对象
 * @param {HTMLFormElement} form
 * @param {{numbers?: string[], booleans?: string[], arrays?: string[]}} [opts]
 */
export function formData(form, opts = {}) {
  const { numbers = [], booleans = [], arrays = [] } = opts;
  const fd = new FormData(form);
  const out = {};
  for (const [key, raw] of fd.entries()) {
    const value = typeof raw === 'string' ? raw : raw;
    if (numbers.includes(key)) {
      out[key] = value === '' ? null : Number(value);
    } else if (booleans.includes(key)) {
      out[key] = value === '1' || value === 'true' || value === 'on';
    } else if (arrays.includes(key)) {
      if (!Array.isArray(out[key])) out[key] = [];
      out[key].push(value);
    } else {
      out[key] = value;
    }
  }
  // 未勾选的复选框需显式置 false
  for (const key of booleans) {
    if (!(key in out)) out[key] = false;
  }
  return out;
}

/** 焦点陷阱（弹窗可访问性） */
export function trapFocus(container) {
  const SEL = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  function onKeydown(e) {
    if (e.key !== 'Tab') return;
    const items = $$(SEL, container).filter((n) => n.offsetParent !== null);
    if (!items.length) return;
    const first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault(); last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault(); first.focus();
    }
  }
  container.addEventListener('keydown', onKeydown);
  return () => container.removeEventListener('keydown', onKeydown);
}
