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

/**
 * 当前是否已在考场入口路由（`#/` 或空 hash）。
 *
 * 存在的意义：router.navigate(同一路径) 不会早退，而是直接重新渲染
 * （见 core/router.js —— 同一个 hash 时走 handle()，用于「原地刷新」）。
 * 而入口视图 ExamLoginView 挂载时就会探测一次 /api/exam/status，
 * 在尚未入场时该请求必然失败并发出 unauthorized 事件。若处理器不加判断地
 * 跳回 '/'，就会「重渲染 → 再探测 → 又失败 → 再跳转」无限循环：
 * 实测 6 秒内发出 997 次 /api/exam/status，页面卡死。
 * 因此两个处理器都必须幂等——已经站在入口页时不再触发跳转。
 */
function atExamRoot() {
    const path = (location.hash.replace(/^#/, '') || '/').split('?')[0];
    return path.replace(/\/+$/, '') === '';
}

installErrorHandlers({
    onUnauthorized: () => {
        // 考场会话失效：回到考场入口（已在入口则无需动作，否则会自激）
        if (!atExamRoot()) router.navigate('/');
    },
    // http.js 发出的 forbidden 事件荷载是 { path, message }，没有 status 字段，
    // 原先判断 payload?.status === 403 恒为假，导致被锁定的考生卡在答题页无任何反馈。
    onForbidden: () => {
        if (!atExamRoot()) router.navigate('/');
    },
});

// 令牌由 ExamLoginView 在入场成功后注入（后端随登录响应下发），此处无需预取。
router.start();
