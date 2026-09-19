/**
 * 公开证书核验（C1）
 *
 * 只需证书编号即可核验真伪。编号 = CT + 8 位日期 + 32 位随机十六进制，
 * 随机段让编号不可枚举，因此「知道编号」本身等价于「持有证书」，
 * 无需再加验证码 —— 否则每核验一次都要人机交互，反而挡住正常使用。
 *
 * 姓名由服务端脱敏（首尾保留、中间打星）：核验的目的只是确认「这张证是真的」，
 * 不需要把持证人全名公开在这个任何人都能访问的页面上。
 */

import { el } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { appName } from '../core/brand.js';
import { button, field, input, card, alertBox, badge, copyWithToast } from '../ui/components.js';
import { siteApi } from '../api/index.js';
import { withLoading } from '../core/bootstrap.js';
import { fmtDateTime, fmtScore } from '../core/format.js';

export function CertVerifyView({ router }) {
  const root = el('div.stack', { style: { maxWidth: '720px', margin: '0 auto', padding: 'var(--sp-6)' } });

  const inputEl = input({ placeholder: '请输入证书编号，如 CT20260920A1B2C3…', value: '' });
  const resultSlot = el('div');

  async function verify() {
    const no = String(inputEl.value || '').trim();
    if (!no) {
      resultSlot.replaceChildren(alertBox('请输入证书编号', { type: 'warning' }));
      return;
    }
    const res = await withLoading(resultSlot, () => siteApi.verifyCertificate(no), { silent: true });
    if (!res.ok) {
      resultSlot.replaceChildren(alertBox(res.error?.message || '核验失败，请稍后重试', { type: 'danger' }));
      return;
    }
    render(res.result || {});
  }

  function render(d) {
    if (!d.valid) {
      resultSlot.replaceChildren(el('div.card', {}, el('div.card-body.stack', {}, [
        el('div.flex.items-center.gap-2', {}, [
          icon('x-circle', { size: 20 }),
          el('strong', { text: '未查询到该证书' }),
        ]),
        el('div.fs-sm.c-secondary', {
          text: '请核对编号是否完整、是否存在多余空格。证书编号区分大小写，建议直接复制粘贴。',
        }),
      ])));
      return;
    }

    const c = d.certificate || {};
    resultSlot.replaceChildren(el('div.card', {}, el('div.card-body.stack', {}, [
      el('div.flex.items-center.gap-3', {}, [
        badge('核验通过', { tone: 'success', dot: true }),
        el('span', { style: { flex: '1' } }),
        button('复制编号', { variant: 'ghost', size: 'sm', onClick: () => copyWithToast(c.cert_no, '证书编号') }),
      ]),
      el('div.cert-card', {}, [
        el('div.cert-head', {}, [
          el('div', {}, [
            el('div.cert-title', { text: c.exam_name || '电子证书' }),
            el('div.cert-sub', { text: `证书编号 ${c.cert_no || '—'}` }),
          ]),
          el('div.cert-seal', { text: '已核验' }),
        ]),
        el('div.dl', {}, [
          row('持证人', c.stu_name || '—'),
          row('科目', c.subj_name || '—'),
          row('得分', `${fmtScore(c.score)} / ${fmtScore(c.total_score)} 分（达标分 ${fmtScore(c.threshold)} 分）`),
          row('签发时间', fmtDateTime(c.issued_at)),
        ]),
      ]),
      el('div.fs-xs.c-tertiary', {
        text: '为保护隐私，核验结果中的姓名已做脱敏处理（保留首尾字符）。',
      }),
    ])));
  }

  const verifyBtn = button('核验', { variant: 'primary', iconName: 'shield-check', onClick: () => verify() });
  inputEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') verify(); });

  root.append(
    el('div.flex.items-center.gap-2', {}, [
      button('返回首页', {
        variant: 'ghost', size: 'sm', iconName: 'arrow-left',
        onClick: () => (router ? router.navigate('/portal') : location.assign('/portal')),
      }),
      el('span.fw-600', { text: `${appName()} · 证书核验` }),
    ]),
    card({
      title: '证书核验',
      iconName: 'shield-check',
      body: el('div.stack', {}, [
        el('div.fs-sm.c-secondary', {
          text: '输入证书上的编号即可核验真伪。证书由考试系统在考生成绩达到本场达标分后自动签发。',
        }),
        el('div.flex.items-end.gap-3', { style: { alignItems: 'flex-end' } }, [
          el('div', { style: { flex: '1' } }, [field('证书编号', inputEl)]),
          verifyBtn,
        ]),
        resultSlot,
      ]),
    }),
  );

  return root;
}

function row(k, v) {
  return el('div.dl-row', {}, [
    el('div.dl-key', { text: k }),
    el('div.dl-val', { text: String(v) }),
  ]);
}
