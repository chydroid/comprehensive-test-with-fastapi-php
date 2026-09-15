/**
 * 考场入口 —— 正式考试（准考证号 + 密码 + 考场口令）。
 * 全屏答题，无侧边栏。
 */

import { applyInitialTheme, installErrorHandlers } from '../core/bootstrap.js';
import { createRouter } from '../core/router.js';
import { el, appRoot } from '../core/dom.js';
import { emptyStated, button } from '../ui/components.js';
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
  // http.js 发出的 forbidden 事件荷载是 { path, message }，没有 status 字段，
  // 原先判断 payload?.status === 403 恒为假，导致被锁定的考生卡在答题页无任何反馈。
  onForbidden: () => {
    router.navigate('/');
  },
});

// 令牌由 ExamLoginView 在入场成功后注入（后端随登录响应下发），此处无需预取。
router.start();
