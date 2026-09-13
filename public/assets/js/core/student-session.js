/**
 * 考生会话单例 —— 供 portal / student / exercise 三个入口共享。
 *
 * 因为三个入口是各自独立的页面（不同 HTML shell），每个页面各自引导一次；
 * 这里集中处理「读会话 → 提供 can() / 登录跳转」的重复逻辑。
 */

import { bootstrapSession, installErrorHandlers } from './bootstrap.js';
import { setCsrfToken } from './http.js';
import { studentApi } from '../api/index.js';

export const studentSession = {
  data: null,
  router: null,

  get isLoggedIn() {
    return Boolean(this.data && this.data.logged_in);
  },

  get student() {
    return this.data?.student || null;
  },

  /** 引导：读取现有会话（cookie 有效则自动恢复） */
  async boot(router) {
    this.router = router;
    const restored = await bootstrapSession(() => studentApi.me());
    if (restored?.csrf_token) setCsrfToken(restored.csrf_token);
    if (restored?.logged_in) this.data = restored;
    return restored;
  },

  /** 登录成功后写入会话 */
  accept(payload) {
    if (payload?.csrf_token) setCsrfToken(payload.csrf_token);
    this.data = { logged_in: true, student: payload?.student ?? null };
  },

  /** 未登录则跳转登录页并返回 false */
  require(redirect) {
    if (this.isLoggedIn) return true;
    const target = redirect || (location.hash || '#/').replace(/^#/, '');
    if (this.router) this.router.navigate(`/login?redirect=${encodeURIComponent(target)}`);
    return false;
  },

  /** 退出登录 */
  async logout() {
    try { await studentApi.logout(); } catch (_) { /* 忽略 */ }
    this.data = null;
  },

  /** 安装全局错误处理：401 统一回登录页 */
  installHandlers() {
    installErrorHandlers({
      onUnauthorized: () => {
        this.data = null;
        if (this.router) this.router.navigate('/login');
      },
      onForbidden: () => {},
    });
  },
};
