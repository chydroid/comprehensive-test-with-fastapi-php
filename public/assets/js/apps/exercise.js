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
appRoot().append(outlet);
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
  router.setRoutes([
    { path: '/', view: () => page },
    { path: '/login', view: () => page },
  ]);
  router.start();
}

function renderErrorNode(err, label) {
  const s = document.createElement('section');
  s.className = 'alert alert-danger';
  s.style.margin = 'var(--sp-6)';
  s.textContent = `「${label || '页面'}」加载失败：${err?.message || err}`;
  return s;
}

function startShell() {
  if (!studentSession.isLoggedIn) return renderAuth();

  const s = studentSession.student || {};
  shell = createShell({
    brandName: '在线练习',
    brandSub: '考生',
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
    { path: '/', view: async (ctx) => { shell.setActive('exercise'); shell.setTitle('在线练习'); try { shell.setContent(await ExerciseView({ router, query: ctx.query })); } catch (e) { shell.setContent(renderErrorNode(e, '在线练习')); } return undefined; } },
    { path: '/mock', view: async (ctx) => { shell.setActive('mock'); shell.setTitle('模拟考试'); try { shell.setContent(await MockSetupView({ router, query: ctx.query })); } catch (e) { shell.setContent(renderErrorNode(e, '模拟考试')); } return undefined; } },
    { path: '/mock/take', view: async (ctx) => { shell.setActive('mock'); shell.setTitle('模拟考试'); try { shell.setContent(await MockTakeView({ router, query: ctx.query })); } catch (e) { shell.setContent(renderErrorNode(e, '模拟考试')); } return undefined; } },
    { path: '/mock/review', view: async (ctx) => { shell.setActive('mock'); shell.setTitle('错题回顾'); try { shell.setContent(await MockReviewView({ router, query: ctx.query })); } catch (e) { shell.setContent(renderErrorNode(e, '错题回顾')); } return undefined; } },
  ]);
  router.start();
}

(async function boot() {
  await studentSession.boot(router);
  if (studentSession.isLoggedIn) startShell();
  else renderAuth();
})();
