/**
 * 门户首页：站点统计、考试入口、公告、功能特性。
 * 全部数据来自公开接口（无需登录）。
 */

import { el, clear, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { button, badge, emptyStated } from '../ui/components.js';
import { withLoading } from '../core/bootstrap.js';
import { siteApi } from '../api/index.js';
import { fmtDate, fmtNumber } from '../core/format.js';

export function PortalView({ router }) {
  const root = el('div.portal');
  const heroSlot = el('div');
  const statsSlot = el('div.portal-stats');
  const featureSlot = el('div.portal-section');
  const newsSlot = el('div.portal-section');
  const footSlot = el('footer.portal-footer');

  root.append(
    el('nav.portal-nav', {}, [
      el('div.portal-nav-inner', {}, [
        el('div.portal-nav-brand', {}, [
          el('div.brand-mark', {}, [icon('graduation-cap', { size: 20 })]),
          el('span', { text: '在线考试系统' }),
        ]),
        el('div.portal-nav-links', {}, [
          el('a', { href: '#/portal', text: '首页' }),
          el('a', { href: '#/exercise', text: '在线练习' }),
          el('a', { href: '#/student', text: '个人中心' }),
          el('a', { href: '#/exam', text: '进入考场' }),
        ]),
        el('div.row.gap-sm', {}, [
          button('教师端', { variant: 'ghost', size: 'sm', onClick: () => router.navigate('/teacher') }),
          button('管理后台', { variant: 'primary', size: 'sm', onClick: () => router.navigate('/admin') }),
        ]),
      ]),
    ]),
    heroSlot,
    statsSlot,
    featureSlot,
    newsSlot,
    footSlot,
  );

  (async () => {
    const [siteRes, newsRes, helpRes] = await Promise.all([
      withLoading(heroSlot, () => siteApi.site()),
      siteApi.news({ per_page: 5 }).catch(() => null),
      siteApi.help().catch(() => null),
    ]);

    const site = siteRes.ok ? siteRes.result : null;
    renderHero(site);
    renderStats(site);
    renderNews(newsRes);
    renderHelp(helpRes);
    renderFooter(site);
  })();

  function renderHero(site) {
    const total = site?.quiz_stats?.total ?? 0;
    const exams = site?.exam_count ?? 0;
    mount(heroSlot, el('section.hero', {}, [
      el('div.hero-inner', {}, [
        el('span.hero-badge', {}, [icon('sparkles', { size: 14 }), el('span', { text: '新一代在线考试平台' })]),
        el('h1', {}, [
          el('span', { text: '高效、安全、专业的' }),
          el('span.gradient-text', { text: '在线考核系统' }),
        ]),
        el('p.hero-sub', {
          text: `覆盖题库管理、智能组卷、考场监考与成绩分析的完整闭环，已收录 ${fmtNumber(total)} 道题目、支撑 ${fmtNumber(exams)} 场考试。`,
        }),
        el('div.hero-actions', {}, [
          button('进入考场', { variant: 'primary', size: 'lg', iconName: 'log-in', onClick: () => router.navigate('/exam') }),
          button('开始练习', { variant: 'secondary', size: 'lg', iconName: 'edit-3', onClick: () => router.navigate('/exercise') }),
        ]),
      ]),
    ]));
  }

  function renderStats(site) {
    if (!site) return;
    const q = site.quiz_stats || {};
    const items = [
      { label: '题库总量', value: fmtNumber(q.total), iconName: 'database', tone: 'brand' },
      { label: '已开考试', value: fmtNumber(site.exam_count), iconName: 'clipboard', tone: 'success' },
      { label: '注册考生', value: fmtNumber(site.student_count), iconName: 'users', tone: 'warning' },
      { label: '题型覆盖', value: fmtNumber(['radio1', 'radio2', 'checkbox', 'text'].filter((k) => (q[k] || 0) > 0).length), iconName: 'layers', tone: 'info' },
    ];
    mount(statsSlot, el('div.portal-stats-inner', {}, items.map((it) => el('div.stat-card', {}, [
      el('div.stat-icon', {}, [icon(it.iconName, { size: 20 })]),
      el('div', {}, [
        el('div.stat-value', { text: String(it.value) }),
        el('div.stat-label', { text: it.label }),
      ]),
    ]))));
  }

  function renderHelp(helpRes) {
    const features = [
      { icon: 'database', title: '智能题库', desc: '支持判断、单选、多选、填空、问答多种题型，批量导入与自动清洗去重。' },
      { icon: 'shuffle', title: '随机组卷', desc: '按题型与难度矩阵自动抽题组卷，每位考生题目顺序随机。' },
      { icon: 'shield-check', title: '考场监考', desc: '实时查看考生状态，支持锁定、强制交卷与整场结束。' },
      { icon: 'bar-chart-2', title: '成绩分析', desc: '自动判分、成绩排名、数据备份与 CSV 导出。' },
      { icon: 'edit-3', title: '在线练习', desc: '随机抽题、即时判分、错题回顾，随时巩固知识点。' },
      { icon: 'users', title: '多角色协同', desc: '超级管理员 / 考务管理员 / 题库操作员 / 教师分权管理。' },
    ];
    mount(featureSlot, el('div', {}, [
      el('div.section-head', {}, [
        el('h2', { text: '核心功能' }),
        el('p.muted', { text: helpRes?.ok ? helpRes.result.title : '从出题到成绩的完整流程' }),
      ]),
      el('div.feature-grid', {}, features.map((f) => el('div.feature-card', {}, [
        el('div.feature-icon', {}, [icon(f.icon, { size: 22 })]),
        el('h3', { text: f.title }),
        el('p', { text: f.desc }),
      ]))),
    ]));

    // 帮助文档块：进页面时若有数据则追加流程说明
    if (helpRes?.ok && Array.isArray(helpRes.result.blocks)) {
      featureSlot.append(el('div.portal-section-inner', {}, helpRes.result.blocks.map((b) => el('div.card.help-block', {}, [
        el('div.card-head', {}, [el('div.card-title', {}, [icon('book-open', { size: 16 }), el('span', { text: b.heading })])]),
        el('div.card-body', {}, el('ol.help-list', {}, (b.items || []).map((t) => el('li', { text: t })))),
      ]))));
    }
  }

  function renderNews(newsRes) {
    const list = newsRes?.ok ? (newsRes.result.list || []) : [];
    if (!list.length) return;
    mount(newsSlot, el('div', {}, [
      el('div.section-head', {}, [el('h2', { text: '考试公告' })]),
      el('div.news-list', {}, list.map((n) => el('div.news-item', {
        on: { click: () => openNews(n) },
      }, [
        el('div.news-date', {}, [
          el('strong', { text: n.news_time ? fmtDate(n.news_time).slice(5, 10) : '—' }),
        ]),
        el('div.news-body', {}, [
          el('h4', { text: n.news_title }),
          el('p.muted', { text: String(n.news_info || '').slice(0, 90) }),
        ]),
        icon('chevron-right', { size: 16 }),
      ]))),
    ]));
  }

  async function openNews(n) {
    const { openModal } = await import('../ui/components.js');
    openModal({
      title: n.news_title,
      body: el('div.stack', {}, [
        el('div.row.between.muted', {}, [
          el('span', {}, [icon('user', { size: 13 }), el('span', { text: ' ' + (n.news_writer || '管理员') })]),
          el('span', { text: n.news_time || '' }),
        ]),
        el('div.news-content', { text: n.news_info || '' }),
      ]),
    });
  }

  function renderFooter(site) {
    const cfg = site?.config || {};
    mount(footSlot, el('div.portal-footer-inner', {}, [
      el('div', {}, [
        el('div.portal-footer-brand', {}, [
          el('div.brand-mark', {}, [icon('graduation-cap', { size: 18 })]),
          el('span', { text: cfg.title || '在线考试系统' }),
        ]),
        el('p.muted', { text: cfg.copyright || '' }),
      ]),
      el('div.muted', {}, [
        el('div', { text: cfg.address || '' }),
        el('div', { text: cfg.phone || '' }),
      ]),
    ]));
  }

  return root;
}
