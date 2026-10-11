// 模拟考试组卷页冒烟测试：验证页面按服务端下发的配额渲染，并就地钳制超额输入。
//
// 用法: node test/dom/mock_setup_smoke.mjs <publicDir>
// 前置: 已启动 dev server（php -S 127.0.0.1:8099 -t public public/router.php）
//
// 为什么要临时开启策略开关：
//   组卷页在「暂停」态下只渲染一条提示（存在进行中的正式考试 + 开关默认关闭，
//   见 Exam::mockPause），组卷表单根本不会出现 —— 那样本脚本就测不到表单本身。
//   故这里用管理员会话把 mock_allow_during_exam 临时置 1，跑完按快照还原原状：
//   原来有覆盖行就写回原值，原来没有就 PUT null 撤销覆盖（不能凭有效值反推，
//   否则等于把默认值实体化成一行覆盖，永久留在 siteconfig 里）。
import { JSDOM } from 'jsdom';
import path from 'path';
import { pathToFileURL } from 'url';

const publicDir = process.argv[2];
const assetsDir = path.join(publicDir, 'assets');
const BASE = 'http://127.0.0.1:8099';

const dom = new JSDOM('<!DOCTYPE html><html><body><div id="app"></div><div id="app-content"></div></body></html>', {
  url: `${BASE}/exercise`, pretendToBeVisual: true,
});
const { window } = dom;
for (const k of ['window', 'document', 'navigator', 'location', 'history', 'localStorage', 'sessionStorage', 'CSS', 'HTMLElement', 'Element', 'Node', 'NodeList', 'Event', 'CustomEvent', 'MouseEvent', 'KeyboardEvent', 'InputEvent', 'FormData', 'URLSearchParams', 'URL', 'Blob', 'File', 'AbortController', 'AbortSignal', 'DOMException', 'getComputedStyle', 'matchMedia', 'MutationObserver', 'IntersectionObserver', 'ResizeObserver', 'Text', 'Comment', 'DocumentFragment', 'DOMParser', 'XMLHttpRequest', 'Image', 'SVGElement', 'HTMLInputElement', 'HTMLSelectElement', 'HTMLTextAreaElement', 'HTMLFormElement']) {
  try { const v = window[k]; if (v !== undefined) globalThis[k] = (typeof v === 'function' && /^(getComputedStyle|matchMedia)$/.test(k)) ? v.bind(window) : v; } catch (e) { /* ignore */ }
}
globalThis.self = window;
window.scrollTo = () => {};

let COOKIE = '';
const nativeFetch = globalThis.fetch;
globalThis.fetch = async (input, init = {}) => {
  let url = typeof input === 'string' ? input : input.url;
  if (url.startsWith('/')) url = BASE + url;
  const headers = { ...(init.headers || {}) };
  if (COOKIE) headers.Cookie = COOKIE;
  const res = await nativeFetch(url, { ...init, headers });
  const sc = res.headers.get('set-cookie');
  if (sc) COOKIE = sc.split(';')[0];
  return res;
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let pass = 0;
let fail = 0;
const ok = (name, cond, info = '') => {
  console.log(`  ${cond ? 'PASS' : 'FAIL'}  ${name}${cond ? '' : '  -> ' + info}`);
  if (cond) pass++; else fail++;
};

/** 管理员登录，返回 csrf token（失败返回 ''） */
async function adminLogin() {
  const j = await (await fetch('/api/admin/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: 'admin', password: 'admin@2026' }),
  })).json();
  return j?.code === 0 ? String(j.data?.csrf_token || '') : '';
}

/** 以管理员身份写入一项运行参数 */
async function adminPutSetting(key, value) {
  const csrf = await adminLogin();
  if (!csrf) return 0;
  const res = await fetch('/api/admin/settings', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify({ [key]: value }),
  });
  return res.status;
}

/** 读「公开可见的运行参数」里的某项（考生/匿名均可读，用来确认真实生效值） */
async function publicSetting(key) {
  const j = await (await fetch('/api/public/settings')).json();
  return j?.data?.[key];
}

/**
 * 管理员快照某一项：{stored, value}
 *   stored=true  → siteconfig 里真有该行，value 是它的值，还原时必须写回
 *   stored=false → 库里没行，当前值是 schema 默认值回落出来的；
 *                  还原时只能 PUT null（撤销覆盖），不能把默认值写回去
 *                  —— 否则会凭空实体化一行等于默认值的覆盖，永久留在库里。
 */
async function adminSnapshot(key) {
  const csrf = await adminLogin();
  if (!csrf) return { stored: null, value: undefined };
  const j = await (await fetch('/api/admin/settings')).json();
  const stored = Array.isArray(j?.data?.stored) ? j.data.stored : null;
  return {
    stored: stored === null ? null : stored.includes(key),
    value: j?.data?.values?.[key],
  };
}

const KEY = 'mock_allow_during_exam';
let snap = { stored: null, value: undefined };

try {
  // 顺序很重要：AuthSession::login() 会 purge() 掉其他身份，
  // 因此必须先以管理员写入策略（管理员快照原值），再登录考生；
  // 反过来做会把刚建立的考生会话清掉，组卷页只会渲染出空白。
  snap = await adminSnapshot(KEY);
  const putStatus = await adminPutSetting(KEY, 1);
  ok('管理员已将策略开关临时置为开启', putStatus === 200, `PUT /api/admin/settings -> ${putStatus}`);
  ok('开关确认生效（公开参数回显 1）', Number(await publicSetting(KEY)) === 1, `value=${await publicSetting(KEY)}`);

  const login = await (await fetch('/api/student/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: '165165', password: '123456' }),
  })).json();
  ok('考生登录', login && login.code === 0, JSON.stringify(login).slice(0, 160));

  const mod = await import(pathToFileURL(path.join(assetsDir, 'js/views/student/mock.js')).href + '?t=' + Date.now());
  const view = await mod.MockSetupView({ router: { navigate() {} } });
  const c = window.document.getElementById('app-content');
  const node = view && view.node ? view.node : view;
  c.innerHTML = '';
  c.append(node.nodeType ? node : (node.node || node));
  await sleep(1500);

  const txt = window.document.body.textContent || '';
  ok('页面渲染标题', txt.includes('模拟考试'));
  ok('未落入暂停态（表单已渲染）', !txt.includes('已暂停'), txt.slice(0, 200));
  ok('渲染「单场题目上限」行', txt.includes('单场题目上限'), txt.slice(0, 240));
  ok('渲染「今日剩余场次」行', txt.includes('今日剩余场次'), txt.slice(0, 240));
  ok('剩余场次显示为 N / M 场格式', /\d+\s*\/\s*\d+\s*场/.test(txt), (txt.match(/[\d\s/]+场/) || [''])[0]);
  ok('渲染「考试科目」字段', txt.includes('考试科目'));
  ok('列名改为「可抽数量」', txt.includes('可抽数量'));

  const nums = [...window.document.querySelectorAll('input[type="number"]')];
  ok('渲染 4 个题型数量输入框', nums.length === 4, '实际 ' + nums.length);
  ok('输入框均带 max 约束（可抽数量）', nums.length === 4 && nums.every((n) => n.getAttribute('max') !== null),
    nums.map((n) => n.getAttribute('max')).join(','));

  // 钳制：单框输入 999 应被就地收敛到可抽数量（不等于 max 即说明钳制失效）
  if (nums.length === 4) {
    const before = nums[0].value;
    nums[0].value = '999';
    nums[0].dispatchEvent(new window.Event('input', { bubbles: true }));
    const maxAttr = Number(nums[0].getAttribute('max'));
    ok('超额输入被钳制到可抽数量', Number(nums[0].value) === maxAttr && Number(nums[0].value) <= maxAttr,
      `输入 999 -> ${nums[0].value}（max=${maxAttr}，原值 ${before}）`);
  }
} finally {
  // 还原必须区分两种原状，否则会把开关永久留成覆盖行：
  //   原来有行 → 写回原值；原来没行 → PUT null 撤销覆盖，让它回到「未配置」。
  // 仅凭有效值反推（写回 0）会在 siteconfig 里凭空落一行 '0'，
  // 效果上等价于默认值，但库不再干净，之后也无法再验证「无残留」。
  if (snap.stored === null) {
    console.log('  WARN  无法读取 stored 快照，跳过还原（请手工核对 siteconfig）');
  } else if (snap.stored) {
    const want = Number(snap.value) ? 1 : 0;
    const back = await adminPutSetting(KEY, want);
    const now = await publicSetting(KEY);
    ok(`策略开关已还原为原值 ${want}`, back === 200 && Number(now) === want, `PUT -> ${back}, value=${now}`);
  } else {
    const back = await adminPutSetting(KEY, null); // null = 撤销覆盖
    const after = await adminSnapshot(KEY);
    ok('策略开关已还原为「未配置」（撤销覆盖，无残留行）',
      back === 200 && after.stored === false, `PUT -> ${back}, stored=${after.stored}`);
  }
}

console.log(`\n结果: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
