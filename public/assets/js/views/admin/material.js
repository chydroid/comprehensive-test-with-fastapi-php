/**
 * 管理端视图：「学习资料」（C3，轻量版）。
 *
 * 只做三件事：上传课件、登记条目（也支持纯外链）、删除。
 * 不做课程/章节/学习进度 —— 那会把系统拽成 LMS，与「理论考核」定位不符。
 *
 * 上传与登记分成两步：先选文件拿到 url，再补全标题/科目/分类保存。
 * 合成一步的话，「只登记一个外链、不上传文件」这种常见做法就无从表达，
 * 而且上传失败会把整张表单一起丢掉。
 */

import { el, mount } from '../../core/dom.js';
import {
  button, statCard, table, badge, field, input, textarea, select, notify,
  emptyStated, alertBox, confirmDialog, openModal,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { adminApi } from '../../api/index.js';

const CATEGORIES = ['课件', '习题', '操作手册', '法规制度', '视频音频', '其他'];

/** 扩展名 → 展示用标签 */
function extBadge(ext) {
  const e = String(ext || '').toLowerCase();
  if (!e) return badge('链接', { tone: '' });
  return badge(e.toUpperCase(), { tone: '' });
}

export function MaterialView() {
  const root = el('div.stack');
  const statsSlot = el('div.grid-stats');
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '220px' } });
  const state = { rows: [], total: 0, categories: [], keyword: '', category: '', subjId: 0 };

  const kwCtl = input({ placeholder: '搜索标题 / 简介', maxlength: '50' });
  kwCtl.addEventListener('keydown', (e) => { if (e.key === 'Enter') { state.keyword = kwCtl.value.trim(); load(); } });

  const catCtl = select([{ value: '', label: '全部分类' }], { value: '' });
  catCtl.addEventListener('change', () => { state.category = catCtl.value; load(); });

  root.append(
    el('div.page-head', {}, [
      el('div', {}, [
        el('h1.page-title', { text: '学习资料' }),
        el('p.page-sub', { text: '上传课件与学习材料，考生可在「学习资料」中浏览下载' }),
      ]),
      el('div.flex.gap-2', {}, [
        kwCtl,
        catCtl,
        button('刷新', { variant: 'secondary', iconName: 'refresh', onClick: () => load() }),
        button('上传资料', { variant: 'primary', iconName: 'upload', onClick: () => openUpload() }),
      ]),
    ]),
    statsSlot,
    tableSlot,
  );

  async function load() {
    const res = await withLoading(tableSlot, () => adminApi.materials({
      keyword: state.keyword,
      category: state.category,
      subj_id: state.subjId || undefined,
      per_page: 50,
    }), { silent: true });
    if (!res.ok) {
      mount(tableSlot, alertBox(res.error?.message || '加载失败', { type: 'danger' }));
      return;
    }
    state.rows = res.result.data || [];
    state.total = Number(res.result.total) || 0;
    state.categories = res.result.categories || [];

    // 分类下拉随数据变化（不建分类表，直接从已有数据聚合）
    const cur = catCtl.value;
    catCtl.replaceChildren();
    catCtl.append(el('option', { value: '', text: '全部分类' }));
    for (const c of state.categories) {
      catCtl.append(el('option', { value: c.name, text: `${c.name}（${c.count}）` }));
    }
    catCtl.value = cur;

    renderStats();
    renderTable();
  }

  function renderStats() {
    const totalHits = state.rows.reduce((s, r) => s + Number(r.hits || 0), 0);
    mount(statsSlot, [
      statCard({ label: '资料总数', value: String(state.total), iconName: 'book', tone: 'brand' }),
      statCard({ label: '分类数', value: String(state.categories.length), iconName: 'layers' }),
      statCard({ label: '累计浏览', value: String(totalHits), iconName: 'eye', tone: 'success' }),
      statCard({ label: '当前页', value: String(state.rows.length), iconName: 'list' }),
    ]);
  }

  function renderTable() {
    if (!state.rows.length) {
      mount(tableSlot, emptyStated('还没有学习资料', {
        iconName: 'book',
        desc: '点击右上角「上传资料」添加课件、习题或操作手册',
      }));
      return;
    }
    mount(tableSlot, table({
      columns: [
        { key: 'title', title: '标题', render: (r) => el('div', {}, [
          el('div.fw-500', { text: r.title }),
          r.summary ? el('div.fs-xs.c-tertiary', { text: r.summary }) : null,
        ].filter(Boolean)) },
        { key: 'category', title: '分类', render: (r) => r.category ? badge(r.category, { tone: 'info' }) : el('span.c-tertiary.fs-xs', { text: '—' }) },
        { key: 'subj_name', title: '科目', render: (r) => el('span.muted', { text: r.subj_name || '不限' }) },
        { key: 'file', title: '文件', render: (r) => el('div.flex.items-center.gap-2', {}, [
          extBadge(r.file_ext),
          el('span.fs-xs.c-tertiary', { text: r.size_text }),
        ]) },
        { key: 'hits', title: '浏览', align: 'right', render: (r) => el('span', { text: String(r.hits ?? 0) }) },
        { key: 'uploader', title: '上传人', render: (r) => el('span.muted', { text: r.uploader || '—' }) },
        { key: '_op', title: '操作', align: 'right', render: (r) => el('div.flex.gap-2.justify-end', {}, [
          button('打开', {
            variant: 'ghost', size: 'sm', iconName: 'eye',
            onClick: () => window.open(r.file_url, '_blank', 'noopener'),
          }),
          button('删除', {
            variant: 'danger', size: 'sm', iconName: 'trash',
            onClick: () => remove(r),
          }),
        ]) },
      ],
      rows: state.rows,
      emptyText: '暂无资料',
    }));
  }

  async function remove(row) {
    const yes = await confirmDialog(`确定删除「${row.title}」吗？`, {
      detail: '仅删除资料条目，服务器上的文件不会被清理。',
      confirmText: '删除',
    });
    if (!yes) return;
    // 直接 await 而非 withLoading：http 层失败会抛 ApiError，这里就地兜住；
    // withLoading 需要挂在一个容器上，删除这种瞬时动作没有合适的挂载点。
    try {
      await adminApi.deleteMaterial(row.id);
      notify.success('已删除');
      load();
    } catch (e) {
      notify.error(e?.message || '删除失败');
    }
  }

  /* ---------------- 上传 + 登记 ---------------- */

  function openUpload() {
    const fileCtl = el('input', {
      type: 'file',
      accept: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv,.zip,.rar,.7z,.mp4,.mp3',
    });
    const titleCtl = input({ placeholder: '例如：第一章 安全生产法律法规（必填）', maxlength: '200' });
    const catCtl2 = select(
      CATEGORIES.map((c) => ({ value: c, label: c })),
      { value: '课件' },
    );
    const subjCtl = input({ placeholder: '科目编号，留空表示不限', inputmode: 'numeric', maxlength: '6' });
    const sumCtl = textarea({ placeholder: '简介（选填）', rows: 2 });

    const statusSlot = el('div');
    let uploaded = null;   // { url, filename, orig_name, size, ext }

    fileCtl.addEventListener('change', async () => {
      const file = fileCtl.files?.[0];
      if (!file) return;
      const fd = new FormData();
      fd.append('file', file);
      statusSlot.replaceChildren(el('div.fs-sm.c-secondary', { text: `正在上传 ${file.name}…` }));

      try {
        const res = await adminApi.uploadMaterial(fd);
        uploaded = res;
        statusSlot.replaceChildren(alertBox(`已上传：${res.orig_name || res.filename}（${Math.round((res.size || 0) / 1024)} KB）`, { type: 'success' }));
        if (!titleCtl.value.trim()) {
          // 用原文件名（去扩展名）预填标题，省一次输入
          titleCtl.value = String(res.orig_name || '').replace(/\.[^.]+$/, '');
        }
      } catch (e) {
        uploaded = null;
        statusSlot.replaceChildren(alertBox(e?.message || '上传失败', { type: 'danger' }));
      }
    });

    const saveBtn = button('保存', {
      variant: 'primary',
      iconName: 'save',
      onClick: async (e) => {
        if (!uploaded) { notify.warning('请先选择并上传文件'); return; }
        if (!titleCtl.value.trim()) { notify.warning('请填写标题'); titleCtl.focus(); return; }

        const res = await withLoading(e.currentTarget, () => adminApi.createMaterial({
          title: titleCtl.value.trim(),
          category: catCtl2.value,
          subj_id: Number(subjCtl.value) || 0,
          summary: sumCtl.value.trim(),
          file_url: uploaded.url,
          file_name: uploaded.orig_name || uploaded.filename,
          file_ext: uploaded.ext || '',
          file_size: uploaded.size || 0,
        }), { silent: true });
        if (res.ok) {
          notify.success('资料已添加');
          modal.close();
          load();
        } else {
          notify.error(res.error?.message || '保存失败');
        }
      },
    });

    const modal = openModal({
      title: '上传学习资料',
      size: 'md',
      body: el('div.stack', {}, [
        field('选择文件', fileCtl, { hint: '支持 PDF / Word / Excel / PPT / 文本 / 压缩包 / 音视频，单个不超过 20MB' }),
        statusSlot,
        field('标题', titleCtl, { required: true }),
        el('div.flex.gap-3', {}, [
          el('div', { style: { flex: '1' } }, [field('分类', catCtl2)]),
          el('div', { style: { flex: '1' } }, [field('科目编号', subjCtl)]),
        ]),
        field('简介', sumCtl),
      ]),
      footer: [
        button('取消', { variant: 'secondary', onClick: () => modal.close() }),
        saveBtn,
      ],
    });
  }

  load();
  return root;
}
