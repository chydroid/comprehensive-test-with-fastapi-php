/**
 * 练习入口 —— 在线练习 + 模拟考试（需考生登录）。
 * 独立页面，带轻量侧边导航。
 */

import { applyInitialTheme } from '../core/bootstrap.js';
import { createRouter } from '../core/router.js';
import { createShell } from '../ui/shell.js';
import { el, clear, appRoot } from '../core/dom.js';
import { emptyStated, notify } from '../ui/components.js';
import { studentSession } from '../core/student-session.js';

import { StudentLoginView } from '../views/student/login.js';
import { ExerciseView } from '../views/student/exercise.js';
import { MockSetupView, MockTakeView, MockReviewView } from '../views/student/mock.js';

const NAV = [
  { key: 'exercise', label: '在线练习', icon: 'edit-3', group: 'practice' },
  { key: 'mock',     label: '模拟考试', icon: 'target', group: 'practice' },
];

const GROUPS = [{ key: 'practice', label: '练习' }];

applyInitialTheme();
document.title = '在线练习';

const outlet = document.createElement('div');
const router = createRouter({
  routes: [],
  outlet,
  notFound: (ctx) => emptyStated('页面不存在', { iconName: 'alert-circle', desc: `路径 ${ctx.path} 无效` }),
});

let shell = null;
studentSession.installHandlers();

function renderAuth() {
  if (shell) { shell.destroy(); shell = null; }
  const page = StudentLoginView({ router, onDone: () => startShell() });
  const el = appRoot();
  el.append(page);
  router.setRoutes([
    { path: '/login', view: () => page },
    { path: '/', view: () => page },
  ]);
  router.start();
}

function startShell() {
  if (!studentSession.isLoggedIn) return renderAuth();

  const s = studentSession.student || {};
  shell = createShell({
    brandName: '在线练习',
    brandSub: '考生',
    brandMark: '练',
    nav: NAV,
    groups: GROUPS,
    can: () => true,
    user: { name: s.stu_name || '考生', role: s.grade_id || '考生', avatar: '' },
    profileKey: null,
    onNavigate: (key) => router.navigate(key === 'exercise' ? '/' : `/${key}`),
    onLogout: async () => {
      await studentSession.logout();
      notify.info('已退出登录');
      renderAuth();
    },
  }).init();
  shell.mount();

  router.setRoutes([
    { path: '/', view: (ctx) => { shell.setActive('exercise'); shell.setTitle('在线练习'); return ExerciseView({ router, query: ctx.query }); } },
    { path: '/mock', view: (ctx) => { shell.setActive('mock'); shell.setTitle('模拟考试'); return MockSetupView({ router, query: ctx.query }); } },
    { path: '/mock/take', view: (ctx) => { shell.setActive('mock'); shell.setTitle('模拟考试'); return MockTakeView({ router, query: ctx.query }); } },
    { path: '/mock/review', view: (ctx) => { shell.setActive('mock'); shell.setTitle('错题回顾'); return MockReviewView({ router, query: ctx.query }); } },
  ]);
  router.start();
}

(async function boot() {
  await studentSession.boot(router);
  if (studentSession.isLoggedIn) startShell();
  else renderAuth();
})();
