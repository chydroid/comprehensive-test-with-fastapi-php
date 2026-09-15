/**
 * 应用外壳 —— 侧边栏 + 顶栏，供三个后台端（管理/教师）复用。
 * 考生端也复用同一外壳，仅导航项不同。
 */

import { el, $, mount, clear } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { initials, hashTone } from '../core/format.js';
import { dropdown, notify, confirmDialog } from '../ui/components.js';

const AVATAR_TONES = [
  'linear-gradient(135deg,#6366f1,#4338ca)',
  'linear-gradient(135deg,#06b6d4,#0e7490)',
  'linear-gradient(135deg,#10b981,#047857)',
  'linear-gradient(135deg,#f59e0b,#b45309)',
  'linear-gradient(135deg,#ef4444,#b91c1c)',
  'linear-gradient(135deg,#8b5cf6,#6d28d9)',
];

/**
 * 创建外壳
 * @param {object} cfg
 * @param {string} cfg.brandName
 * @param {string} cfg.brandSub
 * @param {string} [cfg.brandMark]
 * @param {{key:string,label:string,icon:string,badge?:()=>number,perm?:string}[]} cfg.nav
 * @param {{key:string,label:string,icon?:string}[]} [cfg.groups]  分组：nav 项可带 group 字段
 * @param {object} cfg.user     { name, role, avatar }
 * @param {(key:string)=>void} cfg.onNavigate
 * @param {()=>void} cfg.onLogout
 * @param {(perm:string)=>boolean} [cfg.can]  权限判定
 * @param {number} [cfg.activeCount]  顶栏实时提示
 */
export function createShell(cfg) {
  const { brandName, brandSub, brandMark = '考', nav, user, onNavigate, onLogout, can, groups, profileKey = 'profile' } = cfg;

  const persistKey = 'csip:sidebar:collapsed';

  /* ---------- 侧边栏 ---------- */
  const sidebarNav = el('div.sidebar-nav');
  const navButtons = new Map();
  const badges = new Map();

  function buildNav() {
    clear(sidebarNav);
    const items = nav.filter((n) => !n.perm || !can || can(n.perm) || can('*'));

    if (groups?.length) {
      for (const g of groups) {
        const groupItems = items.filter((n) => n.group === g.key);
        if (!groupItems.length) continue;
        const box = el('div.nav-group');
        box.append(el('div.nav-group-label', { text: g.label }));
        for (const item of groupItems) box.append(buildNavItem(item));
        sidebarNav.append(box);
      }
      // 未分组的项
      const loose = items.filter((n) => !n.group);
      if (loose.length) {
        const box = el('div.nav-group');
        for (const item of loose) box.append(buildNavItem(item));
        sidebarNav.append(box);
      }
    } else {
      const box = el('div.nav-group');
      for (const item of items) box.append(buildNavItem(item));
      sidebarNav.append(box);
    }
  }

  function buildNavItem(item) {
    const badgeEl = el('span.nav-item-badge', { style: { display: 'none' } });
    const btn = el('button', {
      class: 'nav-item', type: 'button',
      dataset: { key: item.key, tip: item.label },
    }, [
      icon(item.icon, { size: 18 }),
      el('span.nav-item-text', { text: item.label }),
      badgeEl,
    ]);
    btn.addEventListener('click', () => {
      onNavigate(item.key);
      shell.closeMobile();
    });
    navButtons.set(item.key, btn);
    badges.set(item.key, { el: badgeEl, fn: item.badge });
    return btn;
  }

  const brand = el('div.sidebar-brand', {}, [
    el('div.brand-mark', { text: brandMark }),
    el('div.brand-text', {}, [
      el('div.brand-name.truncate', { text: brandName }),
      el('div.brand-sub.truncate', { text: brandSub || '' }),
    ]),
  ]);

  const sidebarFooter = el('div.sidebar-footer');

  const sidebar = el('aside.sidebar', { id: 'sidebar' }, [brand, sidebarNav, sidebarFooter]);

  /* ---------- 顶栏 ---------- */
  const pageTitle = el('div.header-title');
  const headerActions = el('div.header-actions');

  const collapseBtn = el('button.icon-btn', {
    type: 'button', title: '折叠侧栏', 'aria-label': '折叠侧栏',
    on: { click: () => shell.toggleCollapse() },
  }, [icon('menu', { size: 18 })]);

  const themeBtn = el('button.icon-btn', {
    type: 'button', title: '切换主题', 'aria-label': '切换主题',
    on: { click: () => toggleTheme() },
  }, [icon('sun', { size: 18 })]);

  const userTrigger = el('button.user-trigger', { type: 'button' });
  function renderUser() {
    clear(userTrigger);
    const av = el('div.avatar');
    if (user.avatar) {
      const img = el('img', { src: user.avatar, alt: '' });
      // 头像加载失败（如文件缺失）时回退到首字母占位，避免破图与重复 404
      img.onerror = () => {
        img.remove();
        av.style.background = hashTone(user.name || '', AVATAR_TONES);
        av.textContent = initials(user.name);
      };
      av.append(img);
    } else {
      av.style.background = hashTone(user.name || '', AVATAR_TONES);
      av.textContent = initials(user.name);
    }
    userTrigger.append(
      av,
      el('div.user-meta', {}, [
        el('div.user-name.truncate', { text: user.name || '—' }),
        el('div.user-role', { text: user.role || '' }),
      ])
    );
  }
  renderUser();

  const userMenu = dropdown(userTrigger, [
    profileKey ? { label: '个人设置', iconName: 'settings', onClick: () => onNavigate(profileKey) } : null,
    user.onSwitch ? { label: user.switchLabel || '切换账号', iconName: 'login', onClick: user.onSwitch } : null,
    '-',
    { label: '退出登录', iconName: 'logout', danger: true, onClick: async () => {
      const ok = await confirmDialog('确定要退出登录吗？', { title: '退出登录', confirmText: '退出', tone: 'danger' });
      if (ok) onLogout();
    } },
  ].filter(Boolean));

  const header = el('header.app-header', {}, [
    collapseBtn,
    pageTitle,
    el('div.header-spacer'),
    headerActions,
    el('div.divider-v', { style: { height: '22px' } }),
    themeBtn,
    userMenu,
  ]);

  /* ---------- 内容区 ---------- */
  const content = el('main.app-content', { id: 'app-content' });
  const scrim = el('div.sidebar-scrim', { on: { click: () => shell.closeMobile() } });
  const main = el('div.app-main', {}, [header, content]);
  const root = el('div.app-shell', {}, [sidebar, main, scrim]);

  /* ---------- 主题 ---------- */
  function currentTheme() {
    return document.documentElement.getAttribute('data-theme') || 'light';
  }
  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('csip:theme', theme); } catch (_) {}
    mount(themeBtn, icon(theme === 'dark' ? 'sun' : 'moon', { size: 18 }));
    themeBtn.title = theme === 'dark' ? '切换到亮色主题' : '切换到暗色主题';
  }
  function toggleTheme() {
    applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
  }

  /* ---------- 折叠 ---------- */
  function applyCollapse(collapsed) {
    root.classList.toggle('is-collapsed', collapsed);
    collapseBtn.title = collapsed ? '展开侧栏' : '折叠侧栏';
    try { localStorage.setItem(persistKey, collapsed ? '1' : '0'); } catch (_) {}
  }

  let mobileQuery = window.matchMedia('(max-width: 900px)');

  /* ---------- 对外 API ---------- */
  const shell = {
    root,
    content,
    sidebar,
    header,

    mount(elRef) {
      // 首次挂载：优先挂到 #app（SPA 根节点），避免与启动占位并存
      if (!root.isConnected) {
        const host = document.getElementById('app') || document.body;
        clear(host);
        if (typeof window.__APP_READY__ === 'function') {
          try { window.__APP_READY__(); } catch (_) { /* 忽略 */ }
        }
        host.append(root);
      }
      this.setContent(elRef);
      return this;
    },

    setContent(node) {
      // 先释放上一个视图（清理定时器/事件监听，避免泄漏）
      if (typeof this._viewDispose === 'function') {
        try { this._viewDispose(); } catch (_) { /* 忽略 */ }
        this._viewDispose = null;
      }
      // 兼容两种视图返回值：裸节点，或 { node, dispose } 信封
      let real = node;
      if (node && typeof node === 'object' && 'node' in node) {
        real = node.node;
        this._viewDispose = typeof node.dispose === 'function' ? node.dispose : null;
      }
      if (real instanceof Node) mount(content, real);
      return this;
    },

    setTitle(text, desc = '') {
      clear(pageTitle);
      pageTitle.append(el('span', { text: text }));
      if (desc) pageTitle.append(el('span.badge', { text: desc }));
      return this;
    },

    setActions(nodes) {
      clear(headerActions);
      for (const n of [].concat(nodes || [])) if (n) headerActions.append(n);
      return this;
    },

    /** 顶栏右下角常驻信息（如"考试进行中 · 3 场"） */
    setHeaderExtra(node) {
      clear(headerActions);
      if (node) headerActions.append(node);
      return this;
    },

    setActive(key) {
      for (const [k, btn] of navButtons) btn.classList.toggle('is-active', k === key);
      return this;
    },

    /** 刷新导航徽标数字 */
    refreshBadges() {
      for (const [, { el: b, fn }] of badges) {
        if (!fn) continue;
        const v = fn();
        if (v === null || v === undefined || v === 0) { b.style.display = 'none'; continue; }
        b.style.display = '';
        b.textContent = String(v);
      }
      return this;
    },

    updateUser(patch) {
      Object.assign(user, patch);
      renderUser();
      return this;
    },

    toggleCollapse() {
      if (mobileQuery.matches) {
        root.classList.toggle('is-mobile-open');
        return;
      }
      applyCollapse(!root.classList.contains('is-collapsed'));
    },

    closeMobile() { root.classList.remove('is-mobile-open'); },

    applyTheme,
    toggleTheme,
    get theme() { return currentTheme(); },

    init() {
      // 主题（优先本地，其次跟随系统）
      let saved = null;
      try { saved = localStorage.getItem('csip:theme'); } catch (_) {}
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      applyTheme(saved || (prefersDark ? 'dark' : 'light'));

      // 折叠状态
      let collapsed = false;
      try { collapsed = localStorage.getItem(persistKey) === '1'; } catch (_) {}
      if (!mobileQuery.matches) applyCollapse(collapsed);

      buildNav();
      this.refreshBadges();

      mobileQuery.addEventListener?.('change', () => {
        root.classList.remove('is-mobile-open');
      });
      return this;
    },

    destroy() {
      if (typeof this._viewDispose === 'function') {
        try { this._viewDispose(); } catch (_) { /* 忽略 */ }
        this._viewDispose = null;
      }
      root.remove();
    },
  };

  // 预填充导航（避免 init 前空白）
  buildNav();

  return shell;
}
