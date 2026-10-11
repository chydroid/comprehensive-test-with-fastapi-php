/**
 * 管理统一入口 —— 管理员 / 教师共用登录页（隐藏入口，门户不展示）。
 *
 * 职责极简：一个「账号 + 密码」表单提交 /api/manage/login，
 * 服务端自动识别身份并建立对应会话，本页按返回的 redirect 跳转：
 *   - 管理员 → /admin（考试管理后台）
 *   - 教师   → /teacher（教师工作台）
 * 工作台初始化（账号详情/权限点/CSRF）由各端 /me 接口自行完成。
 */

import { applyInitialTheme, installErrorHandlers } from '../core/bootstrap.js';
import { renderLogin } from '../ui/login.js';
import { clear, appRoot } from '../core/dom.js';
import { manageApi } from '../api/index.js';

const APP_NAME = '后台管理';

applyInitialTheme();
document.title = APP_NAME;
// 全局错误兜底：登录页不注入 401 跳转（登录失败由表单内提示），保留 ApiError 通知
installErrorHandlers({ onUnauthorized: () => {} });

clear(appRoot());
appRoot().append(
  renderLogin({
    title: APP_NAME,
    subtitle: '管理员 / 教师统一入口 · 自动识别身份',
    fields: [
      { name: 'username', label: '账号', placeholder: '管理员或教师账号', autocomplete: 'username' },
      { name: 'password', label: '密码', type: 'password' },
    ],
    onSubmit: async (values) => {
      // http.post 成功直接返回信封 data：{ role, redirect }
      const res = await manageApi.login(values);
      location.assign(res.redirect || (res.role === 'admin' ? '/admin' : '/teacher'));
      return res;
    },
    extra: [
      // 隐藏入口提示：本页不对门户展示，仅限管理/教师人员通过直链访问
      document.createTextNode('本入口不在站点首页展示，请通过收藏夹或直接输入地址访问。'),
    ],
  })
);
