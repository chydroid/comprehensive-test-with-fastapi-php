/**
 * 出题面板（管理端 / 教师端共用）
 *
 * 需求：开考前要为「已进入考场」的每一位考生出题，且出题过程要在管理页面
 * 实时可见——不是点一下转个圈，而是能看着名单里每个人依次从「排队中」变成
 * 「已出卷 N 题」。
 *
 * 做法：
 *   1. 先 GET generate/plan 拿到已入场考生名单，全部渲染成「排队中」；
 *   2. 按 CHUNK 人一批循环调用 POST generate {stu_ids:[...]}，
 *      每批回来就把这几个人点亮（已出卷 / 已有卷）；
 *   3. 全部走完显示汇总，并回调 onDone 刷新列表。
 *
 * 分批而不是一次性出完：考生多时单次请求会长时间无响应，界面看不到任何过程，
 * 且中途失败要整批重来；分批后每一批的进度都是真实落库的。
 *
 * 本模块只负责「出题过程」这一段 UI，不关心是谁在调用：
 * 传入的 api 需提供 generatePlan(id) 与 generatePapers(id, body)。
 */

import { el, clear } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { button, badge, alertBox, openModal, emptyStated } from '../ui/components.js';

/** 每批出题人数：够小以便肉眼看到推进，够大以免请求过密 */
const CHUNK = 5;

/** 每人一行的状态：pending 排队中 / doing 出题中 / done 已出卷 / kept 已有卷（未重复生成） */
const ROW_STATE = {
  pending: { label: '排队中', tone: '' },
  doing:   { label: '出题中', tone: 'brand' },
  done:    { label: '已出卷', tone: 'success' },
  kept:    { label: '已有卷', tone: 'info' },
  failed:  { label: '失败',   tone: 'danger' },
};

function stateBadge(s, extra = '') {
  const m = ROW_STATE[s] || { label: s, tone: '' };
  return badge(extra ? `${m.label} ${extra}` : m.label, { tone: m.tone });
}

/**
 * 打开出题面板。
 *
 * @param {object} opts
 * @param {number} opts.examId
 * @param {string} [opts.examName]
 * @param {object} opts.api       需含 generatePlan / generatePapers
 * @param {Function} [opts.onDone] 出题完成后回调（用于刷新列表）
 */
export async function openGeneratePanel({ examId, examName = '', api, onDone }) {
  const body = el('div.stack');
  const resultSlot = el('div');

  // 按钮先建好再开弹窗：openModal 的 footer 是构造期传入的，
  // 而「开始出题」的可用状态要等 plan 回来才知道，只能先占位后更新。
  const genBtn = button('读取中…', { variant: 'primary', disabled: true });
  const dlg = openModal({
    title: `出题 · ${examName || ('考试 #' + examId)}`,
    body, size: 'lg',
    footer: [button('关闭', { variant: 'secondary', onClick: () => dlg.close() }), genBtn],
  });

  body.append(el('p.muted', { text: '正在读取考场内的考生…' }));

  // http 层成功时直接返回信封里的 data（并已解包），失败时 throw ——
  // 所以这里拿到的就是 plan 本身，没有 {ok, result} 包装。
  let plan = null;
  let planError = '';
  try {
    plan = await api.generatePlan(examId);
  } catch (e) {
    planError = e?.message || '读取出题名单失败';
  }
  clear(body);

  if (!plan) {
    body.append(alertBox(planError || '读取出题名单失败', { type: 'danger' }));
    // footer 里已有「关闭」，这里把占位按钮摘掉，避免出现两个关闭
    genBtn.remove();
    return dlg;
  }
  const students = Array.isArray(plan.students) ? plan.students : [];

  if (students.length === 0) {
    body.append(emptyStated('本考场暂无考生入场，无需出卷', { iconName: 'users' }));
    body.append(el('p.fs-sm.c-tertiary', {
      text: '考生需在入场窗口内（默认开考前 15 分钟）凭考场口令进入考场后，才会出现在这里。',
    }));
    replaceBtn(genBtn, '关闭', 'secondary', () => dlg.close(), false);
    return dlg;
  }

  // 自动出题提示：让监考知道「不手动点也没关系」，并给出还剩多久
  const autoIn = Number(plan.auto_gen_in ?? NaN);
  if (Number.isFinite(autoIn)) {
    body.append(alertBox(
      autoIn > 0
        ? `距自动出题还有约 ${autoIn} 秒（也可现在手动出题）。出题后到开考时间会自动开考。`
        : '已过自动出题时点：现在出题可让考生一开考就拿到卷；不出也行，考生进入答题时会按需补卷。',
      { type: 'info', title: '自动出题' }
    ));
  }

  const pendingCount = students.filter((s) => !s.has_paper).length;
  body.append(el('div.flex.between.items-center', {}, [
    el('div', {}, [
      el('div.fw-600', { text: `已入场 ${students.length} 人` }),
      el('div.fs-sm.c-tertiary', {
        text: `待出题 ${pendingCount} 人${students.length - pendingCount ? `，已有卷 ${students.length - pendingCount} 人` : ''}`,
      }),
    ]),
    el('div.fs-sm.c-secondary', { text: `开考 ${plan.exam_start || '—'}` }),
  ]));

  // 逐人一行：状态徽标随出题推进实时替换
  const rows = new Map();
  const listNode = el('div.stack', { style: { gap: 'var(--sp-1)', marginTop: 'var(--sp-3)' } });
  students.forEach((s) => {
    const slot = el('div', {}, [
      s.has_paper ? stateBadge('kept', `${s.questions} 题`) : stateBadge('pending'),
    ]);
    const row = el('div.flex.between.items-center', {
      style: {
        padding: '6px 10px', borderRadius: 'var(--radius-sm)',
        border: '1px solid var(--border-subtle)',
      },
    }, [
      el('div.flex.gap-3.items-center', {}, [
        icon('user', { size: 15 }),
        el('span.mono.fs-sm', { text: String(s.stu_id) }),
        el('span', { text: s.stu_name || '（未登记姓名）' }),
      ]),
      slot,
    ]);
    rows.set(String(s.stu_id), slot);
    listNode.append(row);
  });
  body.append(listNode);
  body.append(resultSlot);

  // 已有卷的人不参与分批：generatePaper 幂等，重复提交只是白跑一遍查询
  const targets = students.filter((s) => !s.has_paper).map((s) => String(s.stu_id));

  let running = false;

  async function run() {
    if (running) return;
    running = true;
    genBtn.disabled = true;
    clear(resultSlot);

    let generated = 0;
    let kept = 0;
    let failed = 0;
    let batchError = '';
    const warnings = new Map();

    for (let i = 0; i < targets.length; i += CHUNK) {
      const batch = targets.slice(i, i + CHUNK);
      // 先把本批标成「出题中」，请求回来再落定为最终结果
      batch.forEach((id) => {
        const slot = rows.get(id);
        if (slot) { clear(slot); slot.append(stateBadge('doing')); }
      });

      let d = null;
      try {
        d = await api.generatePapers(examId, { stu_ids: batch });
      } catch (e) {
        d = null;
        batchError = e?.message || '出题失败';
      }

      if (!d) {
        batch.forEach((id) => {
          const slot = rows.get(id);
          if (slot) { clear(slot); slot.append(stateBadge('failed')); }
        });
        failed += batch.length;
        resultSlot.append(alertBox(
          batchError || '出题失败',
          { type: 'danger', title: `第 ${Math.floor(i / CHUNK) + 1} 批失败` }
        ));
        break;
      }

      (d.warnings || []).forEach((w) => {
        warnings.set(`${w.type}_${w.diff}`, `${w.label || w.type}${w.diff_label || ''} 需 ${w.need} 题、库存 ${w.have}`);
      });

      // 逐人落定：后端返回该批每人的题数，直接点亮
      const detail = new Map((d.details || []).map((x) => [String(x.stu_id), x]));
      batch.forEach((id) => {
        const slot = rows.get(id);
        if (!slot) return;
        const x = detail.get(id);
        clear(slot);
        if (x && x.generated) {
          slot.append(stateBadge('done', `${x.questions} 题`));
          generated++;
        } else if (x) {
          slot.append(stateBadge('kept', `${x.questions} 题`));
          kept++;
        } else {
          // 后端没回这个人：多半是他在本批开始前已离场 / 交卷
          slot.append(stateBadge('failed', '未返回'));
          failed++;
        }
      });
    }

    clear(resultSlot);
    resultSlot.append(alertBox(
      `出题完成：新生成 ${generated} 份${kept ? `，已有卷跳过 ${kept} 份` : ''}${failed ? `，失败 ${failed} 份` : ''}`,
      { type: failed ? 'warning' : 'success', title: failed ? '部分完成' : '试卷生成成功' }
    ));
    if (warnings.size) {
      resultSlot.append(alertBox([...warnings.values()].join('；'), { type: 'warning', title: '题库不足' }));
    }

    genBtn.disabled = false;
    genBtn.textContent = '重新出题';
    running = false;
    onDone?.();
  }

  if (targets.length === 0) {
    resultSlot.append(alertBox('已入场考生均已出卷，无需重复生成', { type: 'success' }));
    replaceBtn(genBtn, '关闭', 'secondary', () => dlg.close(), false);
  } else {
    replaceBtn(genBtn, '开始出题', 'primary', run, false);
  }

  return dlg;
}

/** 就地替换按钮的文案 / 样式 / 行为（避免重建节点导致 footer 引用失效） */
function replaceBtn(btn, label, variant, onClick, disabled = false) {
  btn.textContent = label;
  btn.className = `btn btn-${variant}`;
  btn.disabled = disabled;
  btn.onclick = onClick;
}
