/**
 * HTTP 客户端 —— 封装统一响应信封 { code, message, data }
 *
 * 契约要点：
 *  - 所有非幂等请求自动附带 X-CSRF-Token（从会话引导接口取得并缓存）
 *  - code === 0 视为成功，其余抛 ApiError（携带 code/message/data/status）
 *  - 401 触发全局未登录回调（由各端 App 注入跳转逻辑）
 *  - 419 视为 CSRF 失效：自动刷新令牌并重试一次
 */

export const API_BASE = '/api';

/** 业务错误 */
export class ApiError extends Error {
  constructor(message, { code = -1, status = 0, data = null } = {}) {
    super(message || '请求失败');
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.data = data;
  }
  get isAuth()     { return this.status === 401; }
  get isForbidden(){ return this.status === 403; }
  get isCsrf()     { return this.status === 419; }
  get isValidation(){ return this.status === 400 || this.status === 422; }
}

let csrfToken = '';
const listeners = { unauthorized: [], forbidden: [] };

export function setCsrfToken(token) { csrfToken = token || ''; }
export function getCsrfToken() { return csrfToken; }

/** 注册全局事件回调 */
export function on(event, handler) {
  if (listeners[event]) listeners[event].push(handler);
}
function emit(event, payload) {
  (listeners[event] || []).forEach((fn) => { try { fn(payload); } catch (_) {} });
}

function buildQuery(params) {
  if (!params) return '';
  const usp = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v === null || v === undefined || v === '') continue;
    if (Array.isArray(v)) v.forEach((item) => usp.append(k, item));
    else usp.append(k, v);
  }
  const s = usp.toString();
  return s ? `?${s}` : '';
}

async function request(method, path, { query, body, headers = {}, raw = false, retry = true, signal } = {}) {
  const url = path.startsWith('http') ? path : `${API_BASE}${path}${buildQuery(query)}`;

  const init = { method, headers: { Accept: 'application/json', ...headers }, credentials: 'same-origin', signal };
  if (body !== undefined && body !== null) {
    if (body instanceof FormData) {
      init.body = body; // 浏览器自行设置 multipart 边界
    } else {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
  }
  // 非幂等请求强制带 CSRF
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())) {
    if (csrfToken) init.headers['X-CSRF-Token'] = csrfToken;
  }

  let res;
  try {
    res = await fetch(url, init);
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError('网络连接失败，请检查网络后重试', { code: -1, status: 0 });
  }

  if (raw) return res;

  // CSRF 失效 → 刷新后重试一次
  if (res.status === 419 && retry) {
    try {
      await refreshCsrf();
      return request(method, path, { query, body, headers, raw, retry: false, signal });
    } catch (_) { /* 落到下方统一错误处理 */ }
  }

  let payload = null;
  const ct = res.headers.get('content-type') || '';
  if (ct.includes('application/json')) {
    try { payload = await res.json(); } catch (_) { payload = null; }
  }

  if (res.status === 401) emit('unauthorized', { path });
  if (res.status === 403) emit('forbidden', { path, message: payload?.message });

  if (!res.ok) {
    throw new ApiError(
      payload?.message || `请求失败（HTTP ${res.status}）`,
      { code: payload?.code ?? res.status, status: res.status, data: payload?.data ?? null }
    );
  }

  // 200 但业务码非 0
  if (payload && typeof payload.code === 'number' && payload.code !== 0) {
    throw new ApiError(payload.message || '业务处理失败', {
      code: payload.code, status: res.status, data: payload.data ?? null,
    });
  }

  return payload ? payload.data : null;
}

/** 从会话引导接口刷新 CSRF 令牌 */
async function refreshCsrf() {
  const res = await fetch(`${API_BASE}/health`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  const token = res.headers.get('X-CSRF-Token');
  if (token) setCsrfToken(token);
  if (!res.ok) throw new ApiError('无法刷新会话令牌', { status: res.status });
  return token;
}

export const http = {
  get:   (path, opts)       => request('GET', path, opts),
  post:  (path, body, opts) => request('POST', path, { ...opts, body }),
  put:   (path, body, opts) => request('PUT', path, { ...opts, body }),
  patch: (path, body, opts) => request('PATCH', path, { ...opts, body }),
  del:   (path, opts)       => request('DELETE', path, opts),
  raw:   (method, path, opts) => request(method, path, { ...opts, raw: true }),
};

/** 下载文件（走 blob，避免暴露令牌） */
export async function download(path, { query, filename } = {}) {
  const res = await request('GET', path, { query, raw: true });
  if (!res.ok) {
    let msg = `下载失败（HTTP ${res.status}）`;
    try {
      const j = await res.json();
      if (j?.message) msg = j.message;
    } catch (_) {}
    throw new ApiError(msg, { status: res.status });
  }
  const blob = await res.blob();
  let name = filename;
  if (!name) {
    const cd = res.headers.get('content-disposition') || '';
    const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
    name = m ? decodeURIComponent(m[1]) : 'download';
  }
  const href = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = href;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(href), 2000);
  return name;
}

export { refreshCsrf };
