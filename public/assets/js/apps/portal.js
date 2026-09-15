/**
 * 门户入口 —— 公开首页（无需登录）。
 * 提供：首页 / 在线练习（需考生登录）/ 模拟考试（需考生登录）。
 */

import { applyInitialTheme } from '../core/bootstrap.js';
import { createRouter } from '../core/router.js';
import { el, appRoot } from '../core/dom.js';
import { emptyStated, button } from '../ui/components.js';
import { studentSession } from '../core/student-session.js';

import { PortalView, HeroPageView } from '../views/portal.js';
import { ExerciseView } from '../views/student/exercise.js';
import { MockSetupView, MockTakeView, MockReviewView } from '../views/student/mock.js';
import { StudentLoginView, StudentRegisterView } from '../views/student/login.js';

applyInitialTheme();
document.title = '在线考试系统';

const appEl = appRoot();
const outlet = document.createElement('div');
appEl.append(outlet);

const router = createRouter({
  routes: [
    { path: '/', view: () => PortalView({ router }) },
    { path: '/portal', view: () => PortalView({ router }) },
    { path: '/hero', view: () => HeroPageView({ router }) },
    // 登录成功后应跳转到「考生中心」独立页面（/student 是真实页面路由，
    // 不是本 SPA 的 hash 路由）。若用 router.navigate('/student') 只会改写
    // location.hash 成 #/student，门户 SPA 没有该路由 → 报「页面不存在」。
    // 因此必须做整页跳转，而不是 hash 跳转。
    { path: '/login', view: (ctx) => StudentLoginView({ router, query: ctx.query, onDone: () => { location.assign('/student'); } }) },
    { path: '/register', view: () => StudentRegisterView({ router }) },
    { path: '/exercise', view: () => guard(() => ExerciseView({ router })) },
    { path: '/exercise/mock', view: () => guard(() => MockSetupView({ router })) },
    { path: '/exercise/mock/take', view: (ctx) => guard(() => MockTakeView({ router, query: ctx.query })) },
    { path: '/exercise/mock/review', view: (ctx) => guard(() => MockReviewView({ router, query: ctx.query })) },
  ],
  outlet,
  notFound: (ctx) => emptyStated('页面不存在', {
    iconName: 'alert-circle',
    desc: `路径 ${ctx.path} 无效`,
    action: button('回到首页', { variant: 'secondary', onClick: () => router.navigate('/') }),
  }),
});

/** 需要考生登录 */
function guard(factory) {
  if (!studentSession.require()) return el('div');
  return factory();
}

studentSession.installHandlers();
(async () => {
  await studentSession.boot(router);
  router.start();
})();
