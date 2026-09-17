/**
 * 运行参数（客户端可见子集）
 *
 * 后台「系统设置」里的入场窗口、轮询间隔、口令位数等参数由后端下发，
 * 前端不再各自写死文案与节奏。本模块负责一次性拉取并缓存，避免每个视图
 * 各请求一遍：
 *
 *   await loadAppSettings();              // 幂等，多个视图共享同一个请求
 *   appSetting('exam_entry_lead_minutes', 15);
 *
 * 拉取失败时静默回落到 fallback，保证页面可用（不能因为读不到设置而白屏）。
 */

import { siteApi } from '../api/index.js';

/** 与后端 Setting::SCHEMA 的 public 子集保持一致的兜底默认值 */
const FALLBACK = {
  exam_entry_lead_minutes: 15,
  exam_entry_late_minutes: 0,
  exam_pwd_length: 6,
  exam_allow_view_answer: 1,
  exam_show_score_immediately: 1,
  // 模拟考试与练习：与正式考试的隔离策略及数据上限
  exercise_allow_during_exam: 1,
  mock_allow_during_exam: 1,
  mock_daily_limit: 5,
  mock_max_questions: 100,
  password_min_length: 6,
  waiting_poll_seconds: 4,
  monitor_refresh_seconds: 10,
};

let cache = null;
let inflight = null;

/** 拉取运行参数（幂等；并发调用共享同一个请求） */
export async function loadAppSettings() {
  if (cache) return cache;
  if (inflight) return inflight;

  inflight = siteApi.settings()
    .then((data) => {
      cache = { ...FALLBACK, ...(data || {}) };
      return cache;
    })
    .catch(() => {
      // 失败也缓存兜底值，避免每次渲染都重试拖慢页面
      cache = { ...FALLBACK };
      return cache;
    })
    .finally(() => { inflight = null; });

  return inflight;
}

/** 同步读取（未加载时返回兜底值） */
export function appSetting(key, fallback) {
  const src = cache || FALLBACK;
  const v = src[key];
  if (v === undefined || v === null) {
    return fallback !== undefined ? fallback : FALLBACK[key];
  }
  return v;
}

/** 取整型参数 */
export function appSettingInt(key, fallback) {
  return Number(appSetting(key, fallback)) || 0;
}

/** 布尔型参数 */
export function appSettingBool(key, fallback) {
  return !!Number(appSetting(key, fallback));
}

/**
 * 入场窗口文案（与服务端 Exam::hintFor 的表意一致，用于无需请求状态的静态提示，
 * 例如考场登录页副标题「开考前 15 分钟内凭考场口令入场」）。
 */
export function entryWindowText() {
  const lead = appSettingInt('exam_entry_lead_minutes', 15);
  const late = appSettingInt('exam_entry_late_minutes', 0);
  const head = lead > 0 ? `开考前 ${lead} 分钟内凭考场口令入场` : '已开放入场，可凭考场口令入场';
  return late > 0 ? `${head}，开考后 ${late} 分钟内仍可入场` : `${head}，开考后不可入场`;
}

/** 密码最小长度提示文案 */
export function passwordHintText() {
  return `至少 ${appSettingInt('password_min_length', 6)} 位`;
}

/** 使缓存失效（后台保存设置后调用，下次读取会重新拉取） */
export function invalidateAppSettings() {
  cache = null;
}

// 模块加载即开始预取：绝大多数视图渲染时参数已经就绪，
// 同步渲染的静态提示（如密码长度文案）也能取到正确值。
void loadAppSettings();
