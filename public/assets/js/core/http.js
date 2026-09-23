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

/**
 * 这些路径上的 401 不触发全局未登录跳转（见 request() 中的说明）。
 * 共同特征：它们的 401 表示「调用方尚未建立该类会话」这一**正常事实**，
 * 各视图已用局部 catch 自行处理，不需要全局跳转介入。
 */
const NO_AUTH_REDIRECT = [
  /\/login$/i,          // 登录接口本身：401 = 凭据错误，应由调用方给出真实提示
  /\/me$/i,             // 会话探测：未登录时如实回答（后端已改为 200，此处兜底）
  /^\/exam\/status$/i,  // 考场会话探测：未入场时如实回答（见下方注释）
];

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

async function request(method, path, { query, body, headers = {}, raw = false, retry = true, signal, suppressForbidden = false } = {}) {
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

  // 以下两类 401 属于「预期内」的结果，不触发全局未登录跳转：
  //  1. 登录接口自身：401 = 账号/密码错误，应交给调用方显示真实提示；
  //  2. 会话探测接口（*/me、/exam/status）：作为兜底白名单保留（见下）。
  //     后端已把三端 /me 从鉴权中间件的受保护前缀中摘出
  //     （SessionAuthMiddleware::EXACT_RULES），未登录时会返回 200 +
  //     { logged_in:false }；/exam/status 仍是 401，但它的语义同样是
  //     「尚未入场」这一正常回答，考场两处视图都已 .catch(() => null) 自行处理。
  //     若放进全局跳转，后果有两层：
  //       a) 公开页面（门户首页 / 注册页 / 英雄页）一打开就被弹到 #/login，
  //          考生无法自助注册，后台端还会在登录页上多弹一次「登录状态已失效」；
  //       b) 考场入口页更严重——它在无考场会话时必然探测一次 /exam/status，
  //          而该页的 onUnauthorized 是 router.navigate('/')；当 hash 已是 #/ 时
  //          navigate 会直接重渲染入口视图，于是「重渲染 → 再探测 → 又 401 → 再跳转」
  //          形成请求风暴（实测 6 秒内 997 次请求，页面卡死）。
  //          白名单切断该环路的起点，apps/exam.js 另加了幂等守卫作为第二道防线。
  //     各端 boot 已有显式的未登录分支（渲染自己的登录页），门户侧则由
  //     studentSession.require() 守卫受保护视图。
  if (res.status === 401 && !NO_AUTH_REDIRECT.some((re) => re.test(path))) {
    emit('unauthorized', { path });
  }
  // 调用方显式声明本地处理 403（如交卷后拉解析）时，不触发全局跳转，
  // 否则结果页会被 403 事件送回入口，用户看到「交卷成功后又跳回作答页」。
  if (res.status === 403 && !suppressForbidden) emit('forbidden', { path, message: payload?.message });

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
