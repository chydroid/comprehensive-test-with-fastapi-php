/**
 * 格式化工具 —— 日期、时长、分数、文本等
 */

/** 补零 */
const pad = (n) => String(n).padStart(2, '0');

/** 安全解析为 Date；失败返回 null */
export function toDate(value) {
  if (!value) return null;
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
  const s = String(value).trim().replace(/-/g, '/');
  const d = new Date(s);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** 2026-09-13 */
export function fmtDate(value, fallback = '—') {
  const d = toDate(value);
  if (!d) return fallback;
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** 2026-09-13 15:18 */
export function fmtDateTime(value, fallback = '—') {
  const d = toDate(value);
  if (!d) return fallback;
  return `${fmtDate(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** 15:18:22 */
export function fmtTime(value, withSeconds = false, fallback = '—') {
  const d = toDate(value);
  if (!d) return fallback;
  return withSeconds
    ? `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
    : `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** 相对时间：3 分钟前 */
export function fmtRelative(value, fallback = '—') {
  const d = toDate(value);
  if (!d) return fallback;
  const diff = Date.now() - d.getTime();
  const abs = Math.abs(diff);
  const future = diff < 0;
  const units = [
    [31536000000, '年'], [2592000000, '个月'], [604800000, '周'],
    [86400000, '天'], [3600000, '小时'], [60000, '分钟'], [1000, '秒'],
  ];
  for (const [ms, label] of units) {
    if (abs >= ms) {
      const n = Math.floor(abs / ms);
      return future ? `${n}${label}后` : `${n}${label}前`;
    }
  }
  return '刚刚';
}

/** 秒 → 01:23:45 / 23:45 */
export function fmtDuration(totalSeconds) {
  const s = Math.max(0, Math.floor(Number(totalSeconds) || 0));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  return h > 0 ? `${pad(h)}:${pad(m)}:${pad(sec)}` : `${pad(m)}:${pad(sec)}`;
}

/** 分数：保留 1 位小数，整数则不带小数点 */
export function fmtScore(value, fallback = '—') {
  if (value === null || value === undefined || value === '') return fallback;
  const n = Number(value);
  if (Number.isNaN(n)) return fallback;
  return Number.isInteger(n) ? String(n) : n.toFixed(1);
}

/** 千分位 */
export function fmtNumber(value, fallback = '0') {
  const n = Number(value);
  if (Number.isNaN(n)) return fallback;
  return n.toLocaleString('zh-CN');
}

/** 百分比 */
export function fmtPercent(value, digits = 0) {
  const n = Number(value);
  if (Number.isNaN(n)) return '—';
  return `${n.toFixed(digits)}%`;
}

/** 文件大小 */
export function fmtBytes(bytes) {
  const n = Number(bytes);
  if (!Number.isFinite(n) || n < 0) return '—';
  if (n < 1024) return `${n} B`;
  const units = ['KB', 'MB', 'GB', 'TB'];
  let v = n / 1024, i = 0;
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
  return `${v.toFixed(v >= 10 ? 0 : 1)} ${units[i]}`;
}

/** 截断文本 */
export function truncate(text, max = 60, suffix = '…') {
  const s = String(text ?? '');
  return s.length > max ? s.slice(0, max) + suffix : s;
}

/** 取姓名首字（头像占位） */
export function initials(name) {
  const s = String(name ?? '').trim();
  if (!s) return '?';
  // 中文取前 1 字，英文取首字母
  return /[\u4e00-\u9fa5]/.test(s[0]) ? s[0] : s.slice(0, 2).toUpperCase();
}

/** 由字符串稳定生成一个色调索引（头像配色） */
export function hashTone(str, tones) {
  const s = String(str ?? '');
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
  return tones[h % tones.length];
}

/** 题号：1 → A */
export function indexToLetter(i) {
  return String.fromCharCode(65 + Number(i));
}

/** 去除首尾空白并合并连续空白 */
export function normalizeText(s) {
  return String(s ?? '').replace(/\s+/g, ' ').trim();
}

/** 时间字符串归一化：1750 / 17：50 / 17.50 → 17:50 */
export function normalizeTime(raw) {
  const s = String(raw ?? '').trim().replace(/[：.．]/g, ':');
  if (!s) return '';
  if (/^\d{1,2}:\d{2}(:\d{2})?$/.test(s)) {
    const [h, m] = s.split(':');
    return `${pad(h)}:${m}`;
  }
  if (/^\d{3,4}$/.test(s)) {
    const t = s.padStart(4, '0');
    return `${t.slice(0, 2)}:${t.slice(2)}`;
  }
  if (/^\d{1,2}$/.test(s)) return `${pad(s)}:00`;
  return s;
}

/** 今日日期 YYYY-MM-DD（用于表单默认值） */
export function today() {
  return fmtDate(new Date());
}

/** 当前时间 HH:mm */
export function nowTime() {
  const d = new Date();
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
