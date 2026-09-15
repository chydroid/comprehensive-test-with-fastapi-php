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

  // 导航序号：快速连续导航（或前进/后退连击）时，两个 handle 会并发 await，
  // 先发起的那个可能后返回，把旧视图盖到新视图上，并覆盖掉新视图的 disposer。
  let seq = 0;

  async function handle() {
    const token = ++seq;
    const target = resolve();

    // 守卫：可返回 false（阻断）或重定向路径
    if (beforeEach) {
      const verdict = await beforeEach(target, current);
      if (token !== seq) return; // 等待期间又发生了导航，放弃本次渲染
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

      // 已有更新的导航接管，丢弃本次结果：否则旧视图会后挂载，
      // 并把新视图的 disposer 覆盖掉，造成定时器泄漏。
      if (token !== seq) return;

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

    // 图标是静态常量，可以安全地用 innerHTML；路径不可信，必须走 textContent
    const iconBox = document.createElement('div');
    iconBox.className = 'empty-icon';
    iconBox.innerHTML = `
      <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
        <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5" stroke-linecap="round"/>
      </svg>`;

    const title = document.createElement('div');
    title.className = 'empty-title';
    title.textContent = '页面不存在';

    const desc = document.createElement('div');
    desc.className = 'empty-desc';
    const code = document.createElement('code');
    // 路径取自 location.hash，可被构造成 #/<img src=x onerror=...>。
    // 此前直接拼进 innerHTML，属于潜伏的 DOM XSS（当前各入口都传了 notFound 才未暴露）。
    code.textContent = String(target?.path ?? '');
    desc.append('路径 ', code, ' 未匹配到任何视图。');

    s.append(iconBox, title, desc);
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
    // 只有事件监听需要幂等保护；渲染必须每次执行。
    // 早退会导致：登录后内容区空白、退出登录后整页白屏（登录页不再渲染）。
    if (!started) {
      started = true;
      window.addEventListener('hashchange', handle);
    }
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