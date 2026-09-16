/**
 * 产品名 —— 前端唯一读取口。
 *
 * 服务端在 <html data-app-name="…"> 上注入 config('app.name')，
 * 各端标题、门户导航/页脚、登录页页脚都从这里取，不再各写一份字符串。
 *
 * 两个约束决定了这个模块的形态：
 *   1. 不能靠内联 <script> 传变量 —— 站点 CSP 是 `script-src 'self'`，
 *      内联脚本会被浏览器拦掉（曾经踩过），所以走 data-* 属性；
 *   2. 站点标题（siteconfig.site_title）是后台可改的「展示标题」，
 *      与本模块的「产品名」不是一回事，页面如需要站点标题应另行读取接口数据。
 */

/** 兜底产品名：读不到 data-app-name 时使用（静态预览、单测等） */
export const APP_NAME = '深蓝网上考试系统';

/**
 * 当前产品名。
 * 优先级：服务端注入的 data-app-name → 手工覆盖的 window.__APP_NAME__ → 常量。
 */
export function appName() {
  return document.documentElement?.dataset?.appName
      || window.__APP_NAME__
      || APP_NAME;
}
