/**
 * 管理后台 —— 在线监考
 * 选择考试 → 实时名单 → 单人/全局 锁定·解锁·收卷·结束
 */

import { el, mount, clear } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, badge, card, openModal, confirmDialog, notify, alertBox,
  table, descList, emptyStated, segmented, skeletonRows, field, input,
  copyWithToast,
} from '../../ui/components.js';
import { adminApi } from '../../api/index.js';
import { loadAppSettings, appSettingInt, entryWindowText } from '../../core/app-settings.js';
import { withLoading } from '../../core/bootstrap.js';
import { fmtDateTime, fmtScore, fmtNumber, fmtRelative, fmtTime, initials, hashTone } from '../../core/format.js';
import { statusBadge } from './exam.js';

const TONES = [
  'linear-gradient(135deg,#6366f1,#4338ca)',
  'linear-gradient(135deg,#06b6d4,#0e7490)',
  'linear-gradient(135deg,#10b981,#047857)',
  'linear-gradient(135deg,#f59e0b,#b45309)',
  'linear-gradient(135deg,#ef4444,#b91c1c)',
  'linear-gradient(135deg,#8b5cf6,#6d28d9)',
];

const ROSTER_STATUS = {
  waiting: { label: '待考', tone: 'warning' },
  online:  { label: '在线', tone: 'success' },
  locked:  { label: '已锁定', tone: 'danger' },
  over:    { label: '已交卷', tone: '' },
};

function rosterBadge(s) {
  const m = ROSTER_STATUS[s] || { label: s || '—', tone: '' };
  return badge(m.label, { tone: m.tone, dot: true, pulse: s === 'online' });
}

export async function MonitorView({ router, query }) {
  const root = el('div.stack');
  const headSlot = el('div.page-head');
  const examPickerSlot = el('div');
  const statsSlot = el('div.grid-stats');
  const controlSlot = el('div');
  const toolbarSlot = el('div');
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '200px' } });

  root.append(headSlot, examPickerSlot, statsSlot, controlSlot, toolbarSlot, tableSlot);

  let examId = Number(query?.exam_id) || 0;
  let activeExams = [];
  let timer = null;
  let autoRefresh = true;
  let roster = [];
  let currentExam = null;
  let frameSummary = null;
  // 自动刷新间隔（毫秒）：来自后台「界面与体验 → 监考页面自动刷新间隔」
  let refreshMs = 10_000;

  /* ============================ 初始化 ============================ */
  async function init() {
    mount(tableSlot, skeletonRows(6, 5));
    // 运行参数只需拉一次；失败时沿用默认间隔
    await loadAppSettings();
    refreshMs = Math.max(3, appSettingInt('monitor_refresh_seconds', 10)) * 1000;
    try {
      const data = await adminApi.monitor(examId ? { exam_id: examId } : {});
      renderFrame(data);
    } catch (e) {
      mount(tableSlot, el('div.alert.alert-danger', { text: e?.message || '监考数据加载失败' }));
    }
  }

  function renderFrame(data) {
    activeExams = data?.exams || data?.active_exams || [];
    roster = data?.list || data?.roster || data?.students || [];
    currentExam = data?.exam || activeExams.find((e) => Number(e.id) === examId) || null;
    // 通过 URL 直接进入某场考试时，接口只返回该场（不带 exams），
    // 回填进选择器，避免误报「当前没有进行中的考试」。
    if (!activeExams.length && currentExam) activeExams = [currentExam];
    frameSummary = data?.summary || null;

    renderHead();
    renderPicker();
    renderStats();
    renderControl();
    renderTable();
    setupAutoRefresh();
  }

  function renderHead() {
    clear(headSlot);
    headSlot.append(
      el('div', {}, [
        el('h2.page-title', { text: '在线监考' }),
        el('div.page-desc', {
          text: currentExam
            ? `${currentExam.exam_name} · ${fmtDateTime(currentExam.exam_start)} ~ ${fmtDateTime(currentExam.exam_end)}`
            : '实时监控考生作答状态，可单人控制或全局操作',
        }),
      ]),
      el('div.page-actions', {}, [
        el('span.fs-sm.c-secondary.flex.items-center.gap-2', {}, [
          icon('refresh', { size: 14 }),
          el('span', { text: autoRefresh ? '自动刷新中（10 秒）' : '自动刷新已暂停' }),
        ]),
        button(autoRefresh ? '暂停刷新' : '开启刷新', {
          variant: 'secondary', size: 'sm', iconName: autoRefresh ? 'pause' : 'play',
          onClick: () => { autoRefresh = !autoRefresh; renderFrame({ exams: activeExams, roster, exam: currentExam }); },
        }),
        button('手动刷新', { variant: 'secondary', size: 'sm', iconName: 'refresh', onClick: () => reload() }),
      ])
    );
  }

  function renderPicker() {
    clear(examPickerSlot);
    if (!activeExams.length) {
      examPickerSlot.append(alertBox('当前没有进行中的考试。考试需先「启动」才能进入监考。', { type: 'info' }));
      return;
    }
    const items = activeExams.map((e) => ({
      key: String(e.id),
      label: `${e.exam_name}${Number(e.id) === examId ? '' : ''}`,
    }));
    const seg = segmented(
      [{ key: '0', label: '全部考试' }, ...items],
      String(examId),
      (k) => {
        examId = Number(k) || 0;
        const next = `/monitor${examId ? `?exam_id=${examId}` : ''}`;
        // 切换考试时路由会重建视图并重新取数；只有 hash 未变（选中当前项）
        // 时才需要手动 reload，否则一次点击会发出两个重复请求。
        if (location.hash.replace(/^#/, '') === next) reload();
        else router.navigate(next);
      }
    );
    seg.style.overflowX = 'auto';
    seg.style.maxWidth = '100%';
    examPickerSlot.append(seg);
  }

  function renderStats() {
    clear(statsSlot);
    const s = frameSummary || currentExam?.status_summary || summarize(roster);
    statsSlot.append(
      statItem('考生总数', s.total ?? roster.length, 'users', ''),
      statItem('在线作答', s.online ?? 0, 'activity', 'success'),
      statItem('已锁定', s.locked ?? 0, 'lock', 'danger'),
      statItem('已交卷', s.over ?? 0, 'check-circle', 'info'),
    );
  }

  function statItem(label, value, iconName, tone) {
    return el('div.card.stat-card', {}, [
      el('div', { class: `stat-icon${tone ? ` tone-${tone}` : ''}` }, [icon(iconName, { size: 22 })]),
      el('div.flex-1', {}, [
        el('div.stat-value', { text: fmtNumber(value) }),
        el('div.stat-label', { text: label }),
      ]),
    ]);
  }

  function summarize(list) {
    const out = { total: list.length, waiting: 0, online: 0, locked: 0, over: 0 };
    for (const r of list) {
      const k = r.stu_status || r.status;
      if (k in out) out[k]++;
    }
    return out;
  }

  /* ======================== 考场控制（开放入场 / 出题 / 开考） ======================== */
  function examStatus() { return String(currentExam?.exam_status ?? ''); }
  function examPwd() { const p = String(currentExam?.exam_pwd ?? ''); return p === '0' ? '' : p; }

  function renderControl() {
    clear(controlSlot);
    if (!examId || !currentExam) return;

    const status = examStatus();
    const pwd = examPwd();
    const pwdReady = pwd !== '';
    const over = status.startsWith('over');
    const testable = !over && status !== 'testing';   // 尚未开考

    const actions = [];
    if (testable) {
      actions.push(button(pwdReady ? '重新生成口令' : '开放入场', {
        variant: pwdReady ? 'secondary' : 'primary', size: 'sm', iconName: 'login',
        onClick: () => doOpen(pwdReady),
      }));
      if (status === 'exam') {
        actions.push(button('开始出题', {
          variant: 'primary', size: 'sm', iconName: 'sparkles',
          onClick: () => doGeneratePapers(),
        }));
      }
      actions.push(button('开考', {
        variant: 'primary', size: 'sm', iconName: 'play', onClick: () => doStart(),
      }));
    }

    const body = el('div.stack', {}, [
      el('div.flex.items-center.gap-3.flex-wrap', {}, [
        statusBadge(status),
        el('span.fs-sm.c-secondary', { text: controlHint(status, pwdReady) }),
      ]),
      pwdReady ? pwdBlock(pwd) : null,
      el('div.fs-xs.c-tertiary', {
        text: `流程：开放入场 → 告知考生口令 → 考生${entryWindowText()} → 开始出题 → 到点自动开考或手动开考`,
      }),
    ].filter(Boolean));

    controlSlot.append(card({ iconName: 'shield', title: '考场控制', actions, body }));
  }

  function controlHint(status, pwdReady) {
    if (status.startsWith('over')) return '考试已结束，不再接受入场';
    if (status === 'testing') return '考试进行中，考生正在作答';
    if (status === 'paper') return '已出题完毕，等待开考（到达开考时间将自动开考）';
    if (pwdReady) return '入场已开放，等待监考出题';
    return '尚未开放入场，考生暂时无法进入考场';
  }

  function pwdBlock(pwd) {
    return el('div.flex.items-center.gap-4.flex-wrap', {
      style: {
        padding: 'var(--sp-3) var(--sp-4)', background: 'var(--bg-sunken)',
        border: '1px solid var(--border-subtle)', borderRadius: 'var(--radius-md)',
      },
    }, [
      el('div', {}, [
        el('div.fs-xs.c-tertiary', { text: '考场口令' }),
        el('div.mono.fw-700', {
          style: { fontSize: 'var(--fs-2xl)', letterSpacing: '.12em', color: 'var(--brand-600)' },
          text: pwd,
        }),
      ]),
      button('复制', { variant: 'ghost', size: 'sm', iconName: 'copy',
        onClick: () => copyWithToast(pwd, '考场口令') }),
    ]);
  }

  async function doOpen(regenerate) {
    if (regenerate) {
      const ok = await confirmDialog('重新生成口令后，原口令立即失效，已进入考场的考生需重新输入新口令。确定继续？', {
        title: '重新生成口令', confirmText: '生成', tone: 'warning',
      });
      if (!ok) return;
    }
    const { ok: done, error, result } = await withLoading(null, () => adminApi.openExam(examId), { silent: true });
    if (!done) { notify.error(error?.message || '开放入场失败'); return; }
    notify.success(result?.message || '已开放入场');
    showPwdModal(result?.exam_pwd ?? result?.pwd ?? '', '已开放入场');
    reload();
  }

  async function doGeneratePapers() {
    const ok = await confirmDialog('将为本场考试每位考生随机生成一套试卷（已生成的不会覆盖）。确定开始出题？', {
      title: '开始出题', confirmText: '开始出题', tone: 'warning',
      detail: '出题完成后考试状态变为「已组卷」，等待开考。',
    });
    if (!ok) return;
    const { ok: done, error, result } = await withLoading(null, () => adminApi.generatePapers(examId, {}), { silent: true });
    if (!done) { notify.error(error?.message || '出题失败'); return; }
    const r = result || {};
    notify.success(`出题完成：生成 ${r.generated ?? r.count ?? 0} 份${r.skipped ? `，跳过 ${r.skipped} 份` : ''}`);
    if (r.warnings?.length) notify.warning(r.warnings.join('；'));
    reload();
  }

  async function doStart() {
    const ok = await confirmDialog('确定立即开考吗？开考后考生将无法再入场，且立即进入答题界面。', {
      title: '开始考试', confirmText: '立即开考', tone: 'warning',
    });
    if (!ok) return;
    const { ok: done, error, result } = await withLoading(null, () => adminApi.startExam(examId), { silent: true });
    if (!done) { notify.error(error?.message || '开考失败'); return; }
    notify.success('考试已开始');
    reload();
  }

  function showPwdModal(pwd, title) {
    if (!pwd) return;
    openModal({
      title,
      size: 'sm',
      body: el('div.stack', {}, [
        alertBox('请将考场口令告知考生，考试结束前请勿泄露。', { type: 'success' }),
        el('div', {
          style: {
            textAlign: 'center', padding: 'var(--sp-6)',
            background: 'var(--bg-sunken)', border: '1px solid var(--border-subtle)',
            borderRadius: 'var(--radius-md)',
          },
        }, [
          el('div.fs-sm.c-secondary.mb-2', { text: '考场口令' }),
          el('div.mono.fw-700', {
            style: { fontSize: 'var(--fs-4xl)', letterSpacing: '.12em', color: 'var(--brand-600)' },
            text: String(pwd),
          }),
        ]),
        button('复制口令', { variant: 'secondary', iconName: 'copy', block: true,
          onClick: () => copyWithToast(pwd, '考场口令') }),
      ]),
    });
  }

  function renderTable() {
    clear(toolbarSlot);
    const bulk = !examId;

    toolbarSlot.append(el('div.toolbar', {}, [
      el('div.fw-600', { text: '考生名单' }),
      badge(`${roster.length} 人`, { tone: 'brand' }),
      el('div.toolbar-spacer'),
      bulk
        ? el('span.fs-sm.c-tertiary', { text: '请先选择具体考试以启用全局操作' })
        : el('div.flex.gap-2.flex-wrap', {}, [
            button('全部锁定', { variant: 'warning', size: 'sm', iconName: 'lock',
              onClick: () => doGlobal('lockAll', '全部锁定', '将阻止所有在线考生继续作答') }),
            button('全部解锁', { variant: 'secondary', size: 'sm', iconName: 'unlock',
              onClick: () => doGlobal('unlockAll', '全部解锁', '将允许所有被锁定的考生继续作答') }),
            button('全部收卷', { variant: 'secondary', size: 'sm', iconName: 'send',
              onClick: () => doGlobal('submitAll', '全部收卷', '将强制为所有未交卷考生交卷并判分') }),
            button('结束考试', { variant: 'danger', size: 'sm', iconName: 'stop',
              onClick: () => doGlobal('overAll', '结束考试', '将收卷所有考生并把考试置为「已结束」，不可撤销') }),
          ]),
    ]));

    if (!roster.length) {
      mount(tableSlot, emptyStated(
        activeExams.length ? '该考试暂无考生' : '暂无进行中的考试',
        { iconName: 'users', desc: activeExams.length ? '考生登录后会自动出现在这里' : '请先创建并启动考试' }
      ));
      return;
    }

    mount(tableSlot, table({
      size: 'sm',
      columns: [
        { key: 'stu_id', title: '准考证号', width: '120px',
          render: (r) => el('span.mono', { text: String(r.stu_id ?? '—') }) },
        { key: 'stu_name', title: '姓名', width: '120px',
          render: (r) => el('div.flex.items-center.gap-2', {}, [
            el('div.avatar.avatar-sm', { style: { background: hashTone(r.stu_name || '', TONES) }, text: initials(r.stu_name) }),
            el('span.fw-500', { text: r.stu_name || '—' }),
          ]) },
        { key: 'stu_sex', title: '性别', width: '64px', align: 'center', render: (r) => r.stu_sex || '—' },
        { key: 'class_id', title: '班级 / 单位', width: '170px',
          render: (r) => el('div.fs-sm', {}, [
            el('div', { text: r.class_id || '—' }),
            r.grade_id ? el('div.fs-xs.c-tertiary', { text: r.grade_id }) : null,
          ].filter(Boolean)) },
        { key: 'stu_status', title: '状态', width: '100px', align: 'center',
          render: (r) => rosterBadge(r.stu_status) },
        { key: 'stu_score', title: '得分', width: '90px', align: 'right',
          render: (r) => {
            if (r.stu_status !== 'over') return el('span.c-tertiary', { text: '—' });
            return el('span.mono.fw-600', { text: fmtScore(r.stu_score) });
          } },
        // 行内操作必须作为「列」渲染：table() 并不支持 rowActions 参数，
        // 此前传进去被静默忽略 —— 锁定/解锁/收卷按钮从不出现，doOne() 成为死代码。
        { key: '_acts', title: '操作', width: '170px', align: 'right',
          render: (r) => {
            if (!examId) return el('span.fs-xs.c-tertiary', { text: '—' });
            const st = r.stu_status;
            const sid = r.stu_id;
            if (st === 'over') return el('span.fs-xs.c-tertiary', { text: '已结束' });
            return el('div.row.gap-xs.end', {}, [
              st === 'locked'
                ? button('解锁', { variant: 'secondary', size: 'xs', iconName: 'unlock',
                  onClick: () => doOne('unlock', sid, r) })
                : button('锁定', { variant: 'warning', size: 'xs', iconName: 'lock',
                  onClick: () => doOne('lock', sid, r) }),
              button('收卷', { variant: 'ghost', size: 'xs', iconName: 'send',
                onClick: () => doOne('submit', sid, r) }),
            ]);
          } },
      ],
    }));
  }

  /* ============================ 操作 ============================ */
  async function doOne(action, stuId, row) {
    const label = { lock: '锁定', unlock: '解锁', submit: '强制收卷' }[action];
    const ok = await confirmDialog(`确定对考生「${row.stuname ?? row.stu_name}」执行${label}吗？`, {
      title: `${label}考生`, confirmText: label, tone: action === 'lock' ? 'warning' : 'default',
    });
    if (!ok) return;
    const { ok: done, error } = await withLoading(null, () => adminApi[action]({ exam_id: examId, stu_id: stuId }), { silent: true });
    if (done) { notify.success(`已${label}`); reload(); }
    else notify.error(error?.message || `${label}失败`);
  }

  async function doGlobal(method, label, detail) {
    const ok = await confirmDialog(`确定执行「${label}」吗？`, {
      title: label, confirmText: '确认执行', tone: 'danger', detail,
    });
    if (!ok) return;
    const { ok: done, error, result } = await withLoading(null, () => adminApi[method]({ exam_id: examId }), { silent: true });
    if (done) {
      const n = result?.count ?? result?.affected ?? result?.submitted ?? null;
      notify.success(n !== null ? `已${label}，影响 ${n} 人` : `已${label}`);
      reload();
    } else notify.error(error?.message || `${label}失败`);
  }

  /* ============================ 自动刷新 ============================ */
  function setupAutoRefresh() {
    clearInterval(timer);
    if (!autoRefresh) return;
    timer = setInterval(async () => {
      if (!examId && !activeExams.length) return;
      try {
        const data = await adminApi.monitor(examId ? { exam_id: examId } : {});
        const newRoster = data?.roster || data?.students || data?.list || [];
        const newExam = data?.exam || null;
        const sig = (v) => JSON.stringify(v);
        // 名单、考试状态、口令任一变化即重绘（公式/状态由监考端或惰性开考推进）
        const changed = sig(newRoster) !== sig(roster)
          || String(newExam?.exam_status ?? '') !== String(currentExam?.exam_status ?? '')
          || String(newExam?.exam_pwd ?? '') !== String(currentExam?.exam_pwd ?? '');
        if (changed) {
          roster = newRoster;
          activeExams = data?.exams || data?.active_exams || activeExams;
          if (newExam) currentExam = newExam;
          frameSummary = data?.summary || frameSummary;
          renderStats();
          renderControl();
          renderTable();
        }
      } catch (_) { /* 静默失败，等待下次 */ }
    }, refreshMs);
  }

  function reload() { clearInterval(timer); init(); }

  await init();

  return { node: root, dispose: () => clearInterval(timer) };
}
