// 入口脚本挂载后清掉启动占位（各端首次渲染即移除；独立为外部脚本以符合 CSP）
window.__APP_READY__ = function () {
  var s = document.querySelector('.boot-splash');
  if (s) { s.remove(); }
};
