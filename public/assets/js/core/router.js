/**
 * 哈希路由器 —— 支持动态参数（:id）与守卫。
 *
 * 路由定义：
 *   { path: '/students/:id', view: StudentsView, meta: { title: '考生详情', perm: 'student.view' } }
 *
 * 用法：
 *   const router = createRouter({ routes, outlet, notFound, beforeEach });
 *   router.start();
 */

export function createRouter({ routes, outlet, notFound, beforeEach, afterEach }) {
  let current = null;
  let disposer = null;  // 上一个视图的清理函数
  let started = false;
  let compiled = [];
  let api = null;       // 对外暴露的 router 实例（供 ctx.router 使用）

  /** 把 '/students/:id' 编译为正则 */
  function compile(path) {
    const keys = [];
    const pattern = path
      .replace(/\/$/, '')
      .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
      .replace(/\\\/:(\w+)/g, (_, k) => { keys.push(k); return '/([^/]+)'; });
    return { re: new RegExp(`^${pattern || '/'}/?$`), keys };
  }

  /** 装载（或替换）路由表 */
  function setRoutes(list) {
    compiled = (list || []).map((r) => ({ ...r, ...compile(r.path) }));
  }

  /** 追加单条路由 */
  function addRoute(route) {
    compiled.push({ ...route, ...compile(route.path) });
  }

  api = {
    start, stop, navigate, resolve, setRoutes, addRoute,
    get current() { return current; },
    /** 重新渲染当前路由（数据刷新后调用） */
    refresh: handle,
  };

  setRoutes(routes);

  /** 解析当前 hash → { route, params, query, path } */
  function resolve() {
    const raw = location.hash.replace(/^#/, '') || '/';
    const [pathPart, queryPart] = raw.split('?');
    const path = pathPart.replace(/\/+$/, '') || '/';
    const query = Object.fromEntries(new URLSearchParams(queryPart || ''));

    for (const route of compiled) {
      const m = route.re.exec(path);
      if (!m) continue;
      const params = {};
      route.keys.forEach((k, i) => { params[k] = decodeURIComponent(m[i + 1]); });
      return { route, params, query, path };
    }
    return { route: null, params: {}, query, path };
  }

  /** 编程式跳转 */
  function navigate(path, { replace = false } = {}) {
    const target = path.startsWith('#') ? path.slice(1) : path;
    if (replace) {
      const url = `${location.pathname}${location.search}#${target}`;
      history.replaceState(null, '', url);
      handle();
    } else if (location.hash.replace(/^#/, '') === target) {
      handle();
    } else {
      location.hash = target;
    }
  }

  async function handle() {
    const target = resolve();

    // 守卫：可返回 false（阻断）或重定向路径
    if (beforeEach) {
      const verdict = await beforeEach(target, current);
      if (verdict === false) return;
      if (typeof verdict === 'string') { navigate(verdict, { replace: true }); return; }
    }

    // 清理上一个视图
    if (typeof disposer === 'function') {
      try { disposer(); } catch (e) { console.error('[router] dispose failed', e); }
      disposer = null;
    }

    const prev = current;
    current = target;

    if (!target.route) {
      outlet.replaceChildren(notFound ? notFound(target) : defaultNotFound(target));
      afterEach?.(target, prev);
      return;
    }

    document.title = target.route.meta?.title
      ? `${target.route.meta.title} · ${window.__APP_NAME__ || '在线考试系统'}`
      : (window.__APP_NAME__ || '在线考试系统');

    try {
      const result = await target.route.view({ params: target.params, query: target.query, router: api, route: target.route });

      // 支持四种返回值：
      //   1) Node              → 直接挂载
      //   2) { node, dispose } → 挂载 node，卸载时调用 dispose
      //   3) Function          → 视为 dispose（视图已通过副作用自行挂载）
      //   4) undefined         → 视图自行挂载
      if (result instanceof Node) {
        outlet.replaceChildren(result);
      } else if (result && typeof result === 'object' && 'node' in result) {
        if (result.node instanceof Node) outlet.replaceChildren(result.node);
        if (typeof result.dispose === 'function') disposer = result.dispose;
      } else if (typeof result === 'function') {
        disposer = result;
      }
    } catch (e) {
      console.error('[router] view failed', e);
      outlet.replaceChildren(renderError(e, target));
    }
    afterEach?.(target, prev);
  }

  function defaultNotFound(target) {
    const s = document.createElement('section');
    s.className = 'empty';
    s.innerHTML = `
      <div class="empty-icon">
        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
          <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="empty-title">页面不存在</div>
      <div class="empty-desc">路径 <code>${target.path}</code> 未匹配到任何视图。</div>
    `;
    return s;
  }

  function renderError(err, target) {
    const s = document.createElement('section');
    s.className = 'alert alert-danger';
    s.style.margin = 'var(--sp-6)';
    s.textContent = `视图渲染失败：${err?.message || err}`;
    return s;
  }

  function start() {
    if (started) return;
    started = true;
    window.addEventListener('hashchange', handle);
    if (!location.hash) history.replaceState(null, '', `${location.pathname}${location.search}#/`);
    handle();
  }

  function stop() {
    window.removeEventListener('hashchange', handle);
    started = false;
    if (typeof disposer === 'function') disposer();
    disposer = null;
  }

  return api;
}