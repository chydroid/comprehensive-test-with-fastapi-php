/**
 * 考生端 —— 我的证书（C1 电子证书）
 *
 * 证书由服务端**惰性签发**：本页每次打开都会询问一次「有没有新达标的场次」，
 * 达标即当场补签，因此不存在「考完了却没证」的时序窗口，也不需要定时任务。
 *
 * 证书正文是**签发时的快照**：考试改名、题库清理、成绩归档都不会让一张已发出的
 * 证书变样。这也是它与「成绩查询」的本质区别 —— 前者是凭证，后者是视图。
 */

import { el, mount } from '../../core/dom.js';
import {
  button, badge, card, statCard, emptyStated, notify,
  openModal, copyWithToast, alertBox,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { studentApi } from '../../api/index.js';
import { fmtDate, fmtDateTime, fmtScore, fmtNumber } from '../../core/format.js';

export function StudentCertificatesView() {
  const root = el('div.stack');
  const statsSlot = el('div.grid-stats');
  const listSlot = el('div');
  root.append(el('div.page-head', {}, [
    el('div', {}, [
      el('h1.page-title', { text: '我的证书' }),
      el('p.page-sub', { text: '考试达标后自动签发的电子证书，可查看编号并打印' }),
    ]),
    el('div.page-head-actions', {}, [
      button('刷新', { variant: 'secondary', size: 'sm', iconName: 'refresh-cw', onClick: () => reload() }),
    ]),
  ]), statsSlot, listSlot);

  async function reload() {
    const res = await withLoading(listSlot, () => studentApi.certificates());
    if (!res.ok) return;
    const { list = [], stats = {} } = res.result || {};

    mount(statsSlot, [
      statCard({ label: '证书数量', value: fmtNumber(stats.total), iconName: 'award' }),
      statCard({ label: '最高得分', value: fmtScore(stats.best), iconName: 'trending-up', tone: 'brand' }),
    ]);

    if (!list.length) {
      mount(listSlot, emptyStated('还没有证书', {
        iconName: 'award',
        desc: '证书在考试成绩达到本场「证书达标分」后自动签发，请先完成考试',
      }));
      return;
    }

    mount(listSlot, el('div.grid-2', {}, list.map((c) => certCard(c))));
  }

  function certCard(c) {
    return card({
      title: c.exam_name || '电子证书',
      iconName: 'graduation-cap',
      actions: [
        button('查看', { variant: 'secondary', size: 'sm', iconName: 'file', onClick: () => showCert(c) }),
      ],
      body: el('div.stack.gap-2', {}, [
        el('div.flex.items-center.gap-2.flex-wrap', {}, [
          c.subj_name ? badge(c.subj_name, { tone: 'brand' }) : null,
          badge('已通过', { tone: 'success', dot: true }),
        ].filter(Boolean)),
        el('div.dl', {}, [
          dlRow('得分 / 满分', `${fmtScore(c.score)} / ${fmtScore(c.total_score)} 分`),
          dlRow('达标分', `${fmtScore(c.threshold)} 分`),
          dlRow('签发日期', fmtDate(c.issued_at)),
          dlRow('证书编号', el('span.mono.fs-xs', { text: c.cert_no || '—' })),
        ]),
      ]),
    });
  }

  /**
   * 查看单张证书：打开时向服务端**再取一次**，而不是直接渲染列表里那一行。
   * 证书是凭证，打印出来的内容必须与服务端记录一致；列表数据可能已经在页面上
   * 停留很久（甚至跨了另一次考试结束）。
   *
   * 底部按钮在取数前就渲染，靠 holder 间接引用 —— openModal 不提供 setFooter，
   * 而按钮回调用到的证书对象要等请求回来才有值。
   */
  async function showCert(row) {
    const examId = Number(row.exam_id ?? 0);
    const holder = { cert: row };
    const body = el('div.stack');
    openModal({
      title: '电子证书',
      body,
      size: 'lg',
      footer: [
        button('复制编号', { variant: 'secondary', onClick: () => copyWithToast(holder.cert.cert_no, '证书编号') }),
        button('打印 / 另存为 PDF', { variant: 'primary', iconName: 'download', onClick: () => printCert(holder.cert) }),
      ],
    });

    const res = await withLoading(body, () => studentApi.certificate(examId), { silent: true });
    if (!res.ok) {
      mount(body, alertBox(res.error?.message || '无法读取证书', { type: 'danger' }));
      return;
    }
    holder.cert = res.result?.certificate || row;
    mount(body, renderCert(holder.cert));
  }

  function renderCert(c) {
    return el('div.stack', {}, [
      el('div.cert-card', {}, [
        el('div.cert-head', {}, [
          el('div', {}, [
            el('div.cert-title', { text: c.exam_name || '电子证书' }),
            el('div.cert-sub', { text: `证书编号 ${c.cert_no || '—'}` }),
          ]),
          el('div.cert-seal', { text: '已核发' }),
        ]),
        el('div.dl', {}, [
          dlRow('持证人', c.stu_name || '—'),
          dlRow('科目', c.subj_name || '—'),
          dlRow('得分', `${fmtScore(c.score)} 分`),
          dlRow('满分', `${fmtScore(c.total_score)} 分`),
          dlRow('达标分', `${fmtScore(c.threshold)} 分`),
          dlRow('签发时间', fmtDateTime(c.issued_at)),
        ]),
        el('div.cert-no.fs-sm.c-secondary', { text: `证书编号：${c.cert_no || '—'}` }),
      ]),
      el('div.fs-xs.c-tertiary', {
        text: '证书编号可直接用于核验：在门户首页「证书核验」处输入编号即可校验真伪（为保护隐私，核验结果中的姓名会做脱敏处理）。',
      }),
    ]);
  }

  reload();
  return root;
}

function dlRow(k, v) {
  return el('div.dl-row', {}, [
    el('div.dl-key', { text: k }),
    v instanceof Node ? el('div.dl-val', {}, [v]) : el('div.dl-val', { text: String(v) }),
  ]);
}

/**
 * 打印证书：另开一个最小化的打印窗口，而不是给主界面加 @media print ——
 * 后者要处理整个外壳（侧边栏 / 顶栏 / 弹窗遮罩）的隐藏，牵一发动全身。
 */
function printCert(c) {
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (m) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[m]));
  const html = `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">
<title>电子证书 ${esc(c.cert_no)}</title>
<style>
  body{font-family:-apple-system,"Segoe UI","Microsoft YaHei",sans-serif;margin:0;padding:48px;color:#111}
  .wrap{max-width:720px;margin:0 auto;border:2px solid #b8860b;border-radius:12px;padding:40px}
  h1{font-size:24px;margin:0 0 8px}
  .sub{color:#666;font-size:13px;margin-bottom:24px}
  table{width:100%;border-collapse:collapse;font-size:15px}
  td{padding:10px 0;border-bottom:1px dashed #ddd}
  td.k{color:#666;width:120px}
  .no{margin-top:24px;font-family:ui-monospace,Consolas,monospace;font-size:13px;word-break:break-all}
  .seal{float:right;border:2px solid #b8860b;color:#b8860b;border-radius:50%;width:88px;height:88px;
        display:flex;align-items:center;justify-content:center;font-weight:700;transform:rotate(-12deg)}
  @media print{body{padding:0}.wrap{border-color:#000}}
</style></head><body><div class="wrap">
  <div class="seal">已核发</div>
  <h1>电子证书</h1>
  <div class="sub">${esc(c.exam_name || '')}</div>
  <table>
    <tr><td class="k">持证人</td><td>${esc(c.stu_name || '')}</td></tr>
    <tr><td class="k">科目</td><td>${esc(c.subj_name || '')}</td></tr>
    <tr><td class="k">得分</td><td>${esc(c.score)} 分（满分 ${esc(c.total_score)} 分，达标分 ${esc(c.threshold)} 分）</td></tr>
    <tr><td class="k">签发时间</td><td>${esc(c.issued_at || '')}</td></tr>
  </table>
  <div class="no">证书编号：${esc(c.cert_no || '')}</div>
</div><script>window.onload=function(){window.print()}<\/script></body></html>`;

  const w = window.open('', '_blank');
  if (!w) {
    notify.warning('浏览器拦截了弹出窗口，请允许弹出后重试');
    return;
  }
  w.document.write(html);
  w.document.close();
}
