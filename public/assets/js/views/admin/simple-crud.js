/**
 * 简单实体 CRUD 视图工厂 —— 用于科目/类别/单位/班级/教师/管理员这类
 * "字段少、结构一致"的模块，避免六份重复代码。
 */

import { el } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { button, badge, confirmDialog, notify } from '../../ui/components.js';
import { createListView, openFormModal, confirmDelete } from '../../ui/crud.js';
import { withLoading } from '../../core/bootstrap.js';

/**
 * @param {object} cfg
 * @param {string} cfg.title
 * @param {string} cfg.desc
 * @param {object} cfg.api         { list, create, update, remove }
 * @param {Array}  cfg.columns
 * @param {Array}  cfg.formFields  openFormModal 的 fields
 * @param {string} [cfg.createLabel]
 * @param {string} [cfg.entityName]
 * @param {boolean}[cfg.canCreate]
 * @param {boolean}[cfg.canEdit]
 * @param {boolean}[cfg.canDelete]
 * @param {(row:object)=>string} [cfg.deleteMessage]
 * @param {(values:object)=>object} [cfg.transform]
 * @param {(row:object)=>object} [cfg.toFormValues]
 * @param {boolean} [cfg.selectable]
 * @param {(selected:any[])=>Node[]} [cfg.bulkActions]
 */
export function createSimpleCrudView(cfg) {
  const {
    title, desc, api, columns, formFields, createLabel = '新增',
    entityName = '记录', canCreate = true, canEdit = true, canDelete = true,
    deleteMessage, transform, toFormValues, selectable = false, bulkActions,
    extraActions = () => [],
  } = cfg;

  let currentList = null;

  const list = createListView({
    title, desc,
    columns,
    selectable,
    bulkActions,
    defaultPerPage: 50,
    filters: cfg.filters || [],
    fetch: (params) => api.list(params),
    actions: () => [
      ...extraActions(),
      canCreate ? button(createLabel, { variant: 'primary', iconName: 'plus', onClick: () => openEditor(null) }) : null,
    ].filter(Boolean),
    rowActions: (row) => [
      canEdit ? button('', { variant: 'ghost', size: 'sm', iconName: 'edit', title: '编辑', onClick: () => openEditor(row) }) : null,
      canDelete ? button('', { variant: 'ghost', size: 'sm', iconName: 'trash', title: '删除',
        onClick: () => doDelete(row) }) : null,
    ].filter(Boolean),
  });

  currentList = list;

  function openEditor(row) {
    const isEdit = row !== null;
    const values = isEdit && toFormValues ? toFormValues(row) : (row || {});
    openFormModal({
      title: isEdit ? `编辑${entityName}` : createLabel,
      fields: formFields(row),
      values,
      transform,
      successMessage: isEdit ? '已更新' : '已创建',
      onSubmit: (payload) => (isEdit ? api.update(row.id, payload) : api.create(payload)),
      onSaved: () => list.load(),
    });
  }

  async function doDelete(row) {
    const msg = deleteMessage ? deleteMessage(row) : `确定删除这条${entityName}吗？`;
    await confirmDelete(msg, {
      onConfirm: () => api.remove(row.id),
    }).then((done) => { if (done) list.load(); });
  }

  return list.root;
}

/* ============================ 常用列渲染器 ============================ */

export const simpleCols = {
  id: { key: 'id', title: 'ID', width: '72px', sortable: true,
    render: (r) => el('span.mono.fs-sm.c-tertiary', { text: String(r.id) }) },

  name: (key, title, opts = {}) => ({
    key, title, sortable: true,
    render: (r) => el('div', {}, [
      el('div.fw-500', { text: r[key] || '—' }),
      opts.sub ? el('div.fs-xs.c-tertiary', { text: opts.sub(r) }) : null,
    ].filter(Boolean)),
  }),

  text: (key, title, opts = {}) => ({
    key, title, ...opts,
    render: (r) => el('span', { text: r[key] || '—' }),
  }),

  count: (key, title, tone = '') => ({
    key, title, align: 'center', width: '90px',
    render: (r) => badge(String(r[key] ?? 0), { tone }),
  }),
};
