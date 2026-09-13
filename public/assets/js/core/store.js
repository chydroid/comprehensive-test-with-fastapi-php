/**
 * 极简全局状态容器 —— 观察者模式。
 * 用法：
 *   const store = createStore({ user: null });
 *   const off = store.subscribe(s => render(s));
 *   store.setState({ user: { name: 'x' } });   // 浅合并 + 通知
 *   store.select(s => s.user);
 */

export function createStore(initial = {}) {
  let state = { ...initial };
  const subscribers = new Set();

  const notify = (changed) => {
    for (const fn of subscribers) {
      try { fn(state, changed); } catch (e) { console.error('[store] subscriber failed', e); }
    }
  };

  return {
    getState: () => state,

    /** 浅合并更新；返回实际发生变化的键 */
    setState(patch) {
      const changed = {};
      let dirty = false;
      for (const [k, v] of Object.entries(patch)) {
        if (state[k] !== v) { changed[k] = v; dirty = true; }
      }
      if (!dirty) return [];
      state = { ...state, ...patch };
      notify(changed);
      return Object.keys(changed);
    },

    /** 订阅，返回取消订阅函数 */
    subscribe(fn) {
      subscribers.add(fn);
      return () => subscribers.delete(fn);
    },

    select(fn) { return fn(state); },

    reset() {
      state = { ...initial };
      notify(state);
    },
  };
}

/**
 * 本地持久化（带命名空间与容错）
 */
export function createPersist(namespace) {
  const key = (k) => `${namespace}:${k}`;
  return {
    get(k, fallback = null) {
      try {
        const raw = localStorage.getItem(key(k));
        return raw === null ? fallback : JSON.parse(raw);
      } catch (_) { return fallback; }
    },
    set(k, v) {
      try { localStorage.setItem(key(k), JSON.stringify(v)); } catch (_) {}
    },
    remove(k) { try { localStorage.removeItem(key(k)); } catch (_) {} },
  };
}
