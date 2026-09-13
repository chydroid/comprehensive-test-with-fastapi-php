/**
 * 应用引导 —— 各端共用的初始化流程。
 *  1. 继承未登录前的主题偏好（避免闪烁）
 *  2. 拉取 /me 恢复登录态，并按需刷新 CSRF
 *  3. 注册全局 401 / 403 处理
 *  4. 启动路由
 */

import { http, setCsrfToken, on, ApiError } from './http.js';
import { notify } from '../ui/components.js';

/** 立即应用主题，避免首屏白闪 */
export function applyInitialTheme() {
  let saved = null;
  try { saved = localStorage.getItem('csip:theme'); } catch (_) {}
  const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
  document.documentElement.setAttribute('data-theme', saved || (prefersDark ? 'dark' : 'light'));
}

/**
 * 恢复会话
 * @param {() => Promise<{logged_in:boolean, csrf_token?:string}>} fetchMe
 * @returns {Promise<object|null>} 会话对象（未登录为 null）
 */
export async function bootstrapSession(fetchMe) {
  try {
    const data = await fetchMe();
    if (data?.csrf_token) setCsrfToken(data.csrf_token);
    return data?.logged_in ? data : null;
  } catch (e) {
    // 会话接口本身失败（如 500）不应阻断首屏，仅提示
    if (e instanceof ApiError && e.status && e.status !== 401) {
      notify.error(e.message, { title: '会话初始化失败' });
    }
    return null;
  }
}

/**
 * 注册全局错误处理
 * @param {{onUnauthorized:()=>void, onForbidden?:(e:ApiError)=>void}} handlers
 */
export function installErrorHandlers({ onUnauthorized, onForbidden }) {
  on('unauthorized', () => onUnauthorized?.());
  on('forbidden', (payload) => {
    onForbidden?.(payload);
    if (!onForbidden) notify.warning(payload?.message || '没有权限执行该操作');
  });

  window.addEventListener('unhandledrejection', (e) => {
    const err = e.reason;
    if (err instanceof ApiError) {
      if (!err.isAuth && !err.isForbidden) {
        notify.error(err.message, { title: '操作失败' });
      }
      e.preventDefault();
    }
  });
}

/**
 * 统一的异步动作包装：管理 loading 态 + 错误提示。
 * @param {HTMLButtonElement|HTMLElement} trigger  触发按钮（可选）
 * @param {() => Promise<any>} fn
 * @param {{success?:string, onError?:(e)=>void, silent?:boolean, reload?:boolean}} [opts]
 */
export async function withLoading(trigger, fn, opts = {}) {
  const { success = '', onError, silent = false } = opts;
  let prevDisabled = null;
  let spinner = null;

  if (trigger instanceof HTMLElement) {
    prevDisabled = trigger.disabled;
    trigger.disabled = true;
    trigger.classList.add('is-loading');
    spinner = document.createElement('span');
    spinner.className = 'spinner';
    trigger.prepend(spinner);
  }

  try {
    const result = await fn();
    if (success) notify.success(success);
    return { ok: true, result };
  } catch (e) {
    if (onError) onError(e);
    else if (!silent && !(e instanceof ApiError && (e.isAuth || e.isForbidden))) {
      notify.error(e?.message || '操作失败');
    }
    return { ok: false, error: e };
  } finally {
    if (trigger instanceof HTMLElement) {
      trigger.disabled = prevDisabled ?? false;
      trigger.classList.remove('is-loading');
      spinner?.remove();
    }
  }
}

/** 简易并发去重（同一 key 的请求复用结果） */
export function createCache(ttl = 30_000) {
  const map = new Map();
  return {
    async get(key, loader) {
      const hit = map.get(key);
      if (hit && Date.now() - hit.at < ttl) return hit.value;
      const value = await loader();
      map.set(key, { at: Date.now(), value });
      return value;
    },
    invalidate(key) {
      if (key === undefined) map.clear();
      else map.delete(key);
    },
  };
}

/** 读取 URL 查询参数（支持 hash 内查询） */
export function currentQuery() {
  const hash = location.hash.replace(/^#/, '');
  const q = hash.includes('?') ? hash.split('?')[1] : location.search.replace(/^\?/, '');
  return Object.fromEntries(new URLSearchParams(q || ''));
}

export { http, setCsrfToken, ApiError };
