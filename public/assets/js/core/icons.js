/**
 * 图标库 —— 24×24 线性图标，stroke 继承 currentColor。
 * 用法： icon('check', { size: 18 })
 */

const PATHS = {
  // 导航 / 模块
  dashboard: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
  user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  teacher: '<path d="M12 3 2 8l10 5 10-5-10-5Z"/><path d="M6 10.5V16c0 1.5 2.7 3 6 3s6-1.5 6-3v-5.5"/>',
  book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
  file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/>',
  clipboard: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4" stroke-linecap="round"/>',
  chart: '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 15v-4M12 15V7M17 15v-7" stroke-linecap="round"/>',
  layers: '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
  school: '<path d="M14 22v-4a2 2 0 0 0-4 0v4"/><path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9Z"/><path d="M7 22V12M17 22V12"/>',
  bell: '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.36.44.66.8.81.2.08.42.12.64.12H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
  shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>',
  sliders: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3" stroke-linecap="round"/><path d="M1 14h6M9 8h6M17 16h6" stroke-linecap="round"/>',
  monitor: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4" stroke-linecap="round"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
  'eye-off': '<path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a17.9 17.9 0 0 1-3.2 4.2M6.6 6.6A17.9 17.9 0 0 0 2 11s3.5 7 10 7a9 9 0 0 0 4.4-1.1"/><path d="M2 2l20 20M9.9 9.9a3 3 0 0 0 4.2 4.2" stroke-linecap="round"/>',

  // 操作
  plus: '<path d="M12 5v14M5 12h14" stroke-linecap="round"/>',
  minus: '<path d="M5 12h14" stroke-linecap="round"/>',
  check: '<path d="m20 6-11 11-5-5" stroke-linecap="round" stroke-linejoin="round"/>',
  x: '<path d="M18 6 6 18M6 6l12 12" stroke-linecap="round"/>',
  edit: '<path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3Z"/>',
  trash: '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M10 11v6M14 11v6" stroke-linecap="round"/>',
  copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5" stroke-linecap="round"/>',
  filter: '<path d="M22 3H2l8 9.5V19l4 2v-8.5L22 3Z"/>',
  refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4M21 3v6h-6" stroke-linecap="round" stroke-linejoin="round"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3" stroke-linecap="round" stroke-linejoin="round"/>',
  upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12" stroke-linecap="round" stroke-linejoin="round"/>',
  save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
  lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  unlock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
  play: '<path d="M5 3l14 9-14 9V3Z"/>',
  pause: '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
  stop: '<rect x="5" y="5" width="14" height="14" rx="2"/>',
  send: '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z"/>',
  arrowLeft: '<path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round"/>',
  arrowRight: '<path d="M5 12h14M12 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round"/>',
  chevronLeft: '<path d="m15 18-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/>',
  chevronRight: '<path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/>',
  chevronDown: '<path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/>',
  chevronUp: '<path d="m18 15-6-6-6 6" stroke-linecap="round" stroke-linejoin="round"/>',
  more: '<circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/>',
  external: '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3" stroke-linecap="round" stroke-linejoin="round"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9" stroke-linecap="round" stroke-linejoin="round"/>',
  login: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5M15 12H3" stroke-linecap="round" stroke-linejoin="round"/>',
  menu: '<path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round"/>',

  // 状态
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-5M12 8h.01" stroke-linecap="round"/>',
  alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01" stroke-linecap="round"/>',
  'alert-circle': '<circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16h.01" stroke-linecap="round"/>',
  'check-circle': '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round"/>',
  'x-circle': '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6" stroke-linecap="round"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2" stroke-linecap="round" stroke-linejoin="round"/>',
  calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18" stroke-linecap="round"/>',
  flag: '<path d="M4 22V4a1 1 0 0 1 1-1h12l-2 4 2 4H5" stroke-linecap="round" stroke-linejoin="round"/>',
  award: '<circle cx="12" cy="9" r="6"/><path d="m8.5 14-1.5 8 5-3 5 3-1.5-8"/>',
  target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
  trending: '<path d="m3 17 6-6 4 4 8-8" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 7h7v7" stroke-linecap="round" stroke-linejoin="round"/>',
  inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.4 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.4-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.8 1.1Z"/>',
  database: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
  activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke-linecap="round" stroke-linejoin="round"/>',
  wifi: '<path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 19.5h.01" stroke-linecap="round"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" stroke-linecap="round"/>',
  moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
  keyboard: '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8" stroke-linecap="round"/>',
  sparkles: '<path d="m12 3 1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/>',
  box: '<path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',

  // 别名 / 补充（与 Lucide 命名保持一致，避免误用占位方框）
  'refresh-cw': '<path d="M21 12a9 9 0 1 1-2.6-6.4M21 3v6h-6" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 21v-6h6" stroke-linecap="round" stroke-linejoin="round"/>',
  'chevron-right': '<path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/>',
  'alert-triangle': '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01" stroke-linecap="round"/>',
  'shield-check': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>',
  'user-plus': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6" stroke-linecap="round"/>',
  'book-open': '<path d="M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2Z"/><path d="M22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7Z"/>',
  'graduation-cap': '<path d="M22 9 12 4 2 9l10 5 10-5Z"/><path d="M6 11.5V17c0 1.5 2.7 3 6 3s6-1.5 6-3v-5.5"/><path d="M22 9v5" stroke-linecap="round"/>',
  grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13" stroke-linecap="round"/><path d="M3 6h.01M3 12h.01M3 18h.01" stroke-linecap="round"/>',
  'party-popper': '<path d="M5.8 11 4 21l10-1.8Z"/><path d="M14 4.5c1.5-.5 3.5 0 4.5 1s1.5 3 1 4.5"/><path d="M13 8c.8-.3 1.8 0 2.3.6s.6 1.5.3 2.3"/><path d="M15.5 2.5 17 4M19.5 6.5 21 8M12.5 6 14 7.5" stroke-linecap="round"/>',
  'edit-3': '<path d="M12 20h9" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  'arrow-left': '<path d="M19 12H5M11 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/>',
  'chevron-left': '<path d="m15 18-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/>',
  'log-in': '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5M15 12H3" stroke-linecap="round" stroke-linejoin="round"/>',
  'trending-up': '<path d="m22 7-8.5 8.5-5-5L2 17" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 7h6v6" stroke-linecap="round" stroke-linejoin="round"/>',
  'trending-down': '<path d="m22 17-8.5-8.5-5 5L2 7" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17h6v-6" stroke-linecap="round" stroke-linejoin="round"/>',
  'bar-chart-2': '<path d="M18 20V10M12 20V4M6 20v-6" stroke-linecap="round"/>',
  'help-circle': '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.4-1 .9-1 1.7v.5" stroke-linecap="round"/><path d="M12 17h.01" stroke-linecap="round"/>',
  home: '<path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 21v-8h6v8"/>',
  key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.8 12.2 9-9M17 4.5 20 7.5M14.5 7 17 9.5" stroke-linecap="round" stroke-linejoin="round"/>',
  shuffle: '<path d="M16 3h5v5" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 20 21 3" stroke-linecap="round"/><path d="M21 16v5h-5" stroke-linecap="round" stroke-linejoin="round"/><path d="m15 15 6 6M4 4l5 5" stroke-linecap="round"/>',
};

/**
 * 生成图标元素
 * @param {string} name  图标名
 * @param {{size?:number, stroke?:number, class?:string}} [opts]
 */
export function icon(name, opts = {}) {
  const { size = 18, stroke = 1.8, class: cls = '' } = opts;
  const d = PATHS[name];
  const ns = 'http://www.w3.org/2000/svg';
  const s = document.createElementNS(ns, 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('width', String(size));
  s.setAttribute('height', String(size));
  s.setAttribute('fill', 'none');
  s.setAttribute('stroke', 'currentColor');
  s.setAttribute('stroke-width', String(stroke));
  s.setAttribute('stroke-linejoin', 'round');
  s.setAttribute('aria-hidden', 'true');
  if (cls) s.setAttribute('class', cls);
  if (!d) {
    // 未知图标：占位方框，避免布局跳动
    s.innerHTML = '<rect x="4" y="4" width="16" height="16" rx="3"/>';
    return s;
  }
  s.innerHTML = d;
  return s;
}

export const ICON_NAMES = Object.keys(PATHS);
