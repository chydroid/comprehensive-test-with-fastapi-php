/**
 * C2 补考 / 重考 —— 生成补考场次弹窗（管理端 / 教师端共用）
 *
 * 为什么抽出共享模块：两端的列表页都要挂「补考」按钮，而弹窗本身（候选名单、
 * 状态判定、时间填写、提交）完全没有端差异。各写一份必然漂移。
 *
 * 交互契约（与后端 ExamRetake 对齐）：
 *   - 候选状态由后端判定：failed 已交卷未达及格线 / absent 缺考 / passed 已通过；
 *   - 默认**只勾选未通过 + 缺考**；勾到已通过者时自动带上 allow_passed，
 *     否则后端会以 400 拒绝（补考是给未通过者的第二次机会，纳入已通过者需显式确认）；
 *   - 时间是「补考这一新场次」的开放与截止时间，不是源考试的；
 *   - 新场次默认「待开考」，仍需走「开放入场 → 出题 → 开考」的既有流程。
 */

import { el, clear } from '../core/dom.js';
import {
  button, badge, openModal, notify, alertBox, field, input,
  emptyStated, loadingOverlay, statCard,
} from '../ui/components.js';
import { withLoading } from '../core/bootstrap.js';
import { fmtScore } from '../core/format.js';

/** 候选状态 → 文案/色调（与 ExamRetake::STATE_* 一致） */
const STATE_META = {
  failed: { label: '未通过', tone: 'danger' },
  absent: { label: '缺考', tone: 'warning' },
  passed: { label: '已通过', tone: 'success' },
};

/** datetime-local 控件值（YYYY-MM-DDTHH:MM）→ 后端要求的 'Y-m-d H:i:s' */
function toSqlDateTime(value) {
  const raw = String(value ?? '').trim().replace('T', ' ');
  const m = /^(\d{4})-(\d{2})-(\d{2})[ ](\d{1,2}):(\d{2})/.exec(raw);
  if (!m) return '';
  const pad = (s) => String(s).padStart(2, '0');
  return `${m[1]}-${m[2]}-${m[3]} ${pad(m[4])}:${m[5]}:00`;
}

/** Date → datetime-local 控件值 */
function toLocalValue(d) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * 打开「生成补考」弹窗。
 *
 * @param {object}   opts
 * @param {object}   opts.exam   源考试行（至少含 id / exam_name）
 * @param {object}   opts.api    端上 API（teacherApi 或 adminApi），需含 retakeCandidates / createRetake
 * @param {Function} [opts.onDone] 生成成功后的回调（用于刷新列表）
 */
export async function openRetakeDialog({ exam, api, onDone } = {}) {
  const examId = Number(exam?.id ?? 0);
  if (examId <= 0) return;

  const body = el('div.stack', {}, [loadingOverlay('正在统计考生名单…')]);
  const errSlot = el('div');
  const submitBtn = button('生成补考', { variant: 'primary' });

  const dlg = openModal({
    title: `生成补考 · ${exam?.exam_name || `考试 #${examId}`}`,
    body: el('div.stack', {}, [body, errSlot]),
    size: 'lg',
    footer: [button('取消', { variant: 'secondary', onClick: () => dlg.close() }), submitBtn],
  });

  // 渲染后被提交处理器读取的状态（避免用 DOM 反查勾选结果）
  let candidates = { list: [], counts: {}, exam: {} };
  let picked = new Set();
  let startInput = null;
  let endInput = null;

  const loaded = await withLoading(body, () => api.retakeCandidates(examId), { silent: true });
  if (!loaded.ok) {
    clear(body);
    body.append(alertBox(loaded.error?.message || '无法读取候选名单', { type: 'danger' }));
    submitBtn.disabled = true;
    return;
  }
  candidates = loaded.result || candidates;
  renderForm();

  function renderForm() {
    clear(body);
    errSlot.replaceChildren();

    const list = candidates.list || [];
    const counts = candidates.counts || {};
    const passScore = Number(candidates.pass_score ?? 0);

    body.append(el('div.fs-sm.c-secondary', {
      text: `及格线：满分 ${fmtScore(candidates.exam?.exam_score)} 分 × ${candidates.pass_percent}% = ${fmtScore(passScore)} 分。`
        + '生成后请到列表为补考场次「开放入场」并「出题」，考生即可凭口令入场。',
    }));

    body.append(el('div.grid-stats', {}, [
      statCard({ label: '未通过', value: String(counts.failed ?? 0), iconName: 'x-circle', tone: 'danger' }),
      statCard({ label: '缺考', value: String(counts.absent ?? 0), iconName: 'alert-triangle', tone: 'warning' }),
      statCard({ label: '已通过', value: String(counts.passed ?? 0), iconName: 'check-circle', tone: 'success' }),
    ]));

    if (!list.length) {
      body.append(emptyStated('本场没有可参考的考生', {
        iconName: 'users',
        desc: '源考试未配置参考班级，或名单内没有考生',
      }));
      submitBtn.disabled = true;
      return;
    }
    submitBtn.disabled = false;

    /* ---------- 名单勾选 ---------- */
    const boxes = new Map();
    picked = new Set(list.filter((r) => r.state !== 'passed').map((r) => String(r.stu_id)));
    const counter = el('span.fs-sm.c-secondary');

    const rows = list.map((r) => {
      const meta = STATE_META[r.state] || { label: r.state || '—', tone: '' };
      const id = String(r.stu_id);
      const cb = el('input', { type: 'checkbox', checked: picked.has(id) });
      boxes.set(id, cb);
      cb.addEventListener('change', () => {
        if (cb.checked) picked.add(id);
        else picked.delete(id);
        sync();
      });
      return el('label.retake-row.flex.items-center.gap-3', {}, [
        cb,
        el('span.fw-500', { text: r.stu_name || id }),
        el('span.mono.fs-xs.c-tertiary', { text: id }),
        el('span', { style: { flex: '1' } }),
        el('span.mono.fs-sm', { text: `${fmtScore(r.stu_score)} 分` }),
        badge(meta.label, { tone: meta.tone, dot: true }),
      ]);
    });

    function sync() {
      const passedPicked = list.filter((r) => picked.has(String(r.stu_id)) && r.state === 'passed').length;
      counter.textContent = `已选 ${picked.size} 人`
        + (passedPicked ? `（含已通过 ${passedPicked} 人，将一并纳入补考）` : '');
    }
    sync();

    body.append(el('div.stack.gap-2', {}, [
      el('div.flex.items-center.gap-2.flex-wrap', {}, [
        button('仅选未通过 + 缺考', {
          variant: 'secondary', size: 'sm',
          onClick: () => {
            picked = new Set(list.filter((r) => r.state !== 'passed').map((r) => String(r.stu_id)));
            for (const [id, cb] of boxes) cb.checked = picked.has(id);
            sync();
          },
        }),
        button('全选', {
          variant: 'ghost', size: 'sm',
          onClick: () => {
            picked = new Set(list.map((r) => String(r.stu_id)));
            for (const cb of boxes.values()) cb.checked = true;
            sync();
          },
        }),
        button('清空', {
          variant: 'ghost', size: 'sm',
          onClick: () => {
            picked = new Set();
            for (const cb of boxes.values()) cb.checked = false;
            sync();
          },
        }),
        counter,
      ]),
      el('div.retake-list', {}, rows),
    ]));

    /* ---------- 补考时间 ---------- */
    // 默认「10 分钟后开考、考 1 小时」：与新建考试的默认窗口一致，且入场窗口
    // （开考前 15 分钟）在保存后立刻处于开放状态，考务方无需再等。
    const startAt = new Date(Date.now() + 10 * 60000);
    const endAt = new Date(startAt.getTime() + 60 * 60000);
    startInput = input({ type: 'datetime-local', value: toLocalValue(startAt) });
    endInput = input({ type: 'datetime-local', value: toLocalValue(endAt) });

    body.append(el('div.form-grid', {}, [
      field('补考开始时间', startInput, { required: true }),
      field('补考结束时间', endInput, { required: true }),
    ]));
  }

  submitBtn.addEventListener('click', async () => {
    errSlot.replaceChildren();
    const pickedIds = [...picked];
    if (!pickedIds.length) {
      errSlot.append(alertBox('请至少选择一名参加补考的考生', { type: 'warning' }));
      return;
    }
    const examStart = toSqlDateTime(startInput?.value);
    const examEnd = toSqlDateTime(endInput?.value);
    if (!examStart || !examEnd) {
      errSlot.append(alertBox('请填写补考的开始时间与结束时间', { type: 'warning' }));
      return;
    }
    if (new Date(examEnd.replace(' ', 'T')) <= new Date(examStart.replace(' ', 'T'))) {
      errSlot.append(alertBox('补考结束时间必须晚于开始时间', { type: 'warning' }));
      return;
    }

    // 勾到已通过者 → 显式带上 allow_passed，否则后端按设计返回 400
    const passedIds = new Set(
      (candidates.list || []).filter((r) => r.state === 'passed').map((r) => String(r.stu_id)),
    );
    const allowPassed = pickedIds.some((id) => passedIds.has(String(id)));

    const { ok, result, error } = await withLoading(submitBtn, () => api.createRetake(examId, {
      stu_ids: pickedIds,
      exam_start: examStart,
      exam_end: examEnd,
      allow_passed: allowPassed ? 1 : 0,
    }), { silent: true });

    if (ok) {
      notify.success(
        `补考《${result?.exam_name || ''}》已生成（${result?.roster ?? pickedIds.length} 人）`,
        { title: '生成成功' },
      );
      dlg.close();
      onDone?.();
    } else if (error) {
      errSlot.append(alertBox(error.message || '生成补考失败', { type: 'danger' }));
    }
  });
}

/** 列表行内展示「本场是某场的补考」小标签（非补考返回 null） */
export function retakeBadge(row) {
  const of = Number(row?.retake_of ?? 0);
  if (!of) return null;
  return badge(`补考自 #${of}`, { tone: 'info', title: `本场是考试 #${of} 的补考场次` });
}
