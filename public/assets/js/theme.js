// 尽早应用主题，避免首屏白闪（独立为外部脚本以符合 CSP script-src 'self'）
(function () {
  try {
    var saved = localStorage.getItem('csip:theme');
    var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.setAttribute('data-theme', saved || (dark ? 'dark' : 'light'));
  } catch (e) {}
})();
