/**
 * 考场入口 —— 正式考试（准考证号 + 密码 + 考场口令）。
 * 全屏答题，无侧边栏。
 */

import { applyInitialTheme, installErrorHandlers } from '../core/bootstrap.js';
import { createRouter } from '../core/router.js';
import { el, appRoot } from '../core/dom.js';
import { emptyStated, button } from '../ui/components.js';
import { examApi } from '../api/index.js';
import { setCsrfToken } from '../core/http.js';
import { ExamLoginView, ExamTakeView } from '../views/student/exam.js';

applyInitialTheme();
document.title = '在线考场';

const appEl = appRoot();
const outlet = document.createElement('div');
appEl.append(outlet);

const router = createRouter({
  routes: [
    { path: '/', view: (ctx) => ExamLoginView({ router, query: ctx.query }) },
    { path: '/take', view: (ctx) => ExamTakeView({ router, query: ctx.query }) },
  ],
  outlet,
  notFound: (ctx) => emptyStated('页面不存在', { iconName: 'alert-circle', desc: `路径 ${ctx.path} 无效`,
    action: button('返回考场入口', { variant: 'secondary', onClick: () => router.navigate('/') }) }),
});

installErrorHandlers({
  onUnauthorized: () => {
    // 考场会话失效：回到考场入口
    router.navigate('/');
  },
  onForbidden: (payload) => {
    if (payload?.status === 403) router.navigate('/');
  },
});

/* 首次进入先取一次 CSRF 令牌（考场登录是公开接口，但后续保存需要 token） */
(async function boot() {
  try {
    const res = await examApi.over();
    void res;
  } catch (_) { /* 未入场属正常，忽略 */ }
  // 兜底：通过公开接口拿一次 csrf
  try {
    const me = await fetch('/api/student/me', { credentials: 'same-origin' }).then((r) => r.json());
    if (me?.data?.csrf_token) setCsrfToken(me.data.csrf_token);
  } catch (_) { /* 忽略 */ }
  router.start();
})();
