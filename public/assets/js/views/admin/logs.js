/**
 * 审计日志视图（B2）
 * 展示系统关键操作的留痕：登录、考试增删改、成绩备份、设置变更、题库与人员增删、监考收卷/锁定、系统初始化等。
 * 可见性：受路由权限约束，仅具备 system.manage 的管理员可见（见 admin.js NAV 的 perm）。
 */

import { el, mount, clear } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  table, emptyStated, button,
} from '../../ui/components.js';
import { adminApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';
import { fmtDateTime } from '../../core/format.js';

const ACTOR_LABELS = { admin: '管理员', teacher: '教师', student: '考生', system: '系统' };

export function LogsView({ shell }) {
  const root = el('div.logs-view');

  const head = el('div.page-head', {}, [
    el('div', {}, [
      el('h2.page-title', { text: '操作审计日志' }),
      el('p.page-sub', { text: '记录关键写操作的留痕，便于事后追溯「谁在何时改了什么」。' }),
    ]),
    el('div.head-actions', {}, [
      button('刷新', { variant: 'secondary', size: 'sm', iconName: 'refresh', onClick: () => load() }),
    ]),
  ]);

  /* 过滤栏 */
  const filterKeyword = el('input.input.input-sm', { type: 'text', placeholder: '关键词（对象/操作者/详情）' });
  const filterActor = el('select.input.input-sm', {}, [
    el('option', { value: '' }, '全部操作者'),
    el('option', { value: 'admin' }, '管理员'),
    el('option', { value: 'teacher' }, '教师'),
    el('option', { value: 'student' }, '考生'),
    el('option', { value: 'system' }, '系统'),
  ]);
  const filterAction = el('select.input.input-sm', {}, [
    el('option', { value: '' }, '全部动作'),
    el('option', { value: 'auth' }, '登录/登出'),
    el('option', { value: 'exam' }, '考试管理'),
    el('option', { value: 'monitor' }, '监考操作'),
    el('option', { value: 'score' }, '成绩操作'),
    el('option', { value: 'quiz' }, '题库操作'),
    el('option', { value: 'user' }, '人员管理'),
    el('option', { value: 'settings' }, '设置变更'),
    el('option', { value: 'system' }, '系统维护'),
  ]);

  const onFilterChange = () => load();
  filterKeyword.addEventListener('input', debounce(onFilterChange, 350));
  filterActor.addEventListener('change', onFilterChange);
  filterAction.addEventListener('change', onFilterChange);

  const filterBar = el('div.filter-bar', {}, [
    el('div.field', {}, [el('label', { text: '动作' }), filterAction]),
    el('div.field', {}, [el('label', { text: '操作者' }), filterActor]),
    el('div.field.field-grow', {}, [el('label', { text: '关键词' }), filterKeyword]),
  ]);

  const statsSlot = el('div.stats-row', { style: { marginBottom: '12px' } });
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '240px' } });

  root.append(head, filterBar, statsSlot, tableSlot);

  let currentRows = [];

  function buildColumns() {
    return [
      { key: 'created_at', title: '时间', width: '170px', render: (r) => el('span.mono.fw-500', { text: fmtDateTime(r.created_at) }) },
      { key: 'actor', title: '操作者', width: '160px', render: (r) => el('span', {}, [
        el('span.tag.tag-sm', { text: ACTOR_LABELS[r.actor_type] || r.actor_type }),
        el('span', { text: ' ' + (r.actor_name || r.actor_id || '—') }),
      ]) },
      { key: 'action', title: '动作', width: '170px', render: (r) => el('span.mono', { text: r.action }) },
      { key: 'target', title: '对象', width: '150px', render: (r) => el('span.mono', { text: r.target || '—' }) },
      { key: 'ip', title: '来源 IP', width: '130px', render: (r) => el('span.mono', { text: r.ip || '—' }) },
      { key: 'detail', title: '详情', render: (r) => renderDetail(r.detail) },
    ];
  }

  function renderDetail(detail) {
    if (!detail || Object.keys(detail).length === 0) {
      return el('span.text-muted', { text: '—' });
    }
    const summary = Object.entries(detail)
      .map(([k, v]) => `${k}=${typeof v === 'object' ? JSON.stringify(v) : v}`)
      .join('，');
    return el('span.detail-text', { text: summary, title: summary });
  }

  function renderStats() {
    const total = currentRows.length;
    const byAction = {};
    for (const r of currentRows) byAction[r.action] = (byAction[r.action] || 0) + 1;
    mount(statsSlot, el('div.stat-cards', {}, [
      statCardSafe('本次返回', String(total), 'list'),
      statCardSafe('涉及动作种类', String(Object.keys(byAction).length), 'layers'),
      statCardSafe('最新一条时间', currentRows[0] ? fmtDateTime(currentRows[0].created_at) : '—', 'clock'),
    ]));
  }

  function statCardSafe(label, value, iconName) {
    const wrap = el('div.stat-card', {}, [
      icon(iconName, { size: 18, class: 'stat-icon' }),
      el('div.stat-body', {}, [
        el('div.stat-value', { text: value }),
        el('div.stat-label', { text: label }),
      ]),
    ]);
    return wrap;
  }

  function renderTable() {
    if (!currentRows.length) {
      mount(tableSlot, emptyStated('暂无日志记录', { iconName: 'shield', desc: '操作审计日志会在关键写操作发生后自动记录。' }));
      return;
    }
    mount(tableSlot, table({
      columns: buildColumns(),
      rows: currentRows,
      emptyText: '暂无日志记录',
    }));
  }

  async function load() {
    mount(tableSlot, el('div.loading-tip', { text: '加载中…' }));
    try {
      const params = {};
      const kw = filterKeyword.value.trim();
      const at = filterActor.value;
      const ac = filterAction.value;
      if (kw) params.keyword = kw;
      if (at) params.actor_type = at;
      if (ac) params.action = ac;
      const data = await adminApi.logs(params);
      currentRows = data.list || [];
      renderStats();
      renderTable();
    } catch (e) {
      mount(tableSlot, el('div.alert.alert-danger', { text: '日志加载失败：' + (e?.message || e) }));
    }
  }

  // 初次加载
  load();

  return root;
}

function debounce(fn, wait) {
  let t = null;
  return (...args) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => fn(...args), wait);
  };
}
