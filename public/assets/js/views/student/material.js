/**
 * 考生端视图：「学习资料」（C3，轻量版）。
 *
 * 只读：浏览 + 下载。考生不能上传，也不能删别人的资料。
 *
 * 一个细节：点「下载」时先记一次浏览量再跳转。顺序不能反 —— 先跳转的话
 * 当前页面可能已经开始卸载，请求会被浏览器取消，浏览量就丢了。
 */

import { el, mount } from '../../core/dom.js';
import {
  badge, input, select, emptyStated, alertBox, notify, button,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { studentApi } from '../../api/index.js';

export function StudentMaterialView() {
  const root = el('div.stack');
  const listSlot = el('div.grid-2');
  const barSlot = el('div');
  const state = { rows: [], total: 0, categories: [], keyword: '', category: '' };

  const kwCtl = input({ placeholder: '搜索资料标题', maxlength: '50' });
  kwCtl.addEventListener('keydown', (e) => { if (e.key === 'Enter') { state.keyword = kwCtl.value.trim(); load(); } });

  const catCtl = select([{ value: '', label: '全部分类' }], { value: '' });
  catCtl.addEventListener('change', () => { state.category = catCtl.value; load(); });

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [
        el('h1.page-title', { text: '学习资料' }),
        el('p.page-sub', { text: '教师上传的课件、习题与操作手册，可在线查看或下载' }),
      ]),
      el('div.flex.gap-2', {}, [kwCtl, catCtl]),
    ]),
    barSlot,
    listSlot,
  );

  async function load() {
    const res = await withLoading(listSlot, () => studentApi.materialList({
      keyword: state.keyword,
      category: state.category,
      per_page: 50,
    }), { silent: true });
    if (!res.ok) {
      mount(listSlot, alertBox(res.error?.message || '加载失败', { type: 'danger' }));
      return;
    }
    state.rows = res.result.data || [];
    state.total = Number(res.result.total) || 0;
    state.categories = res.result.categories || [];

    const cur = catCtl.value;
    catCtl.replaceChildren();
    catCtl.append(el('option', { value: '', text: '全部分类' }));
    for (const c of state.categories) {
      catCtl.append(el('option', { value: c.name, text: `${c.name}（${c.count}）` }));
    }
    catCtl.value = cur;

    render();
  }

  function render() {
    if (!state.rows.length) {
      mount(listSlot, emptyStated('暂无学习资料', {
        iconName: 'book',
        desc: '教师上传课件后，这里会显示可下载的资料',
      }));
      return;
    }

    mount(barSlot, el('div.fs-sm.c-secondary', { text: `共 ${state.total} 份资料` }));
    mount(listSlot, state.rows.map(card));
  }

  function card(r) {
    const ext = String(r.file_ext || '').toLowerCase();
    return el('div.card', {}, el('div.card-body.stack', { style: { gap: '6px' } }, [
      el('div.flex.items-start.gap-2', {}, [
        el('div', { style: { flex: '1' } }, [
          el('div.fw-500', { text: r.title }),
          r.summary ? el('div.fs-sm.c-secondary', { text: r.summary }) : null,
        ].filter(Boolean)),
        ext ? badge(ext.toUpperCase(), { tone: 'info' }) : null,
      ].filter(Boolean)),
      el('div.flex.items-center.gap-3.fs-xs.c-tertiary', {}, [
        r.category ? el('span', { text: r.category }) : null,
        r.subj_name ? el('span', { text: r.subj_name }) : null,
        el('span', { text: r.size_text || '' }),
        el('span', { text: `${r.hits ?? 0} 次浏览` }),
      ].filter(Boolean)),
      el('div.flex.justify-end', {}, [
        button('查看 / 下载', {
          variant: 'primary', size: 'sm', iconName: 'download',
          onClick: async () => {
            // 先记账再跳转：跳转会触发页面卸载，请求可能被取消
            try { await studentApi.materialHit(r.id); } catch (_) { /* 记账失败不拦下载 */ }
            window.open(r.file_url, '_blank', 'noopener');
          },
        }),
      ]),
    ]));
  }

  load();
  return root;
}
