/**
 * A2 成绩与学情分析 —— 教师端与管理端复用的共享视图。
 *
 * 通过依赖注入适配两端差异：
 *   - 教师端只能看「自己监考的已结束考试」→ 传 teacherApi.scores / teacherApi.examAnalysis
 *   - 管理端可看全部考试          → 传 adminApi.exams / adminApi.examAnalysis
 *
 * 数据由后端 ScoreAnalysis 服务统一产出，本视图只负责呈现，不做口径计算，
 * 避免前后端各算一套造成统计口径分叉。
 *
 * 视觉沿用系统既有的 progressBar 横向条（无第三方图表库依赖，保持零构建约束）。
 */

import { el, mount, clear } from '../core/dom.js';
import {
  button, card, badge, table, segmented, emptyStated, alertBox, statCard,
  progressBar, loadingOverlay, descList,
} from '../ui/components.js';
import { withLoading } from '../core/bootstrap.js';
import { fmtScore, fmtNumber } from '../core/format.js';

/** 正确率颜色：越低越需要关注 */
function rateTone(rate) {
  if (rate >= 80) return 'tone-success';
  if (rate >= 60) return '';
  if (rate >= 40) return 'tone-warning';
  return 'tone-danger';
}

function rateBar(label, correct, total, rate, extra) {
  return el('div.stack', { style: { gap: '4px' } }, [
    el('div.flex.items-center.justify-between', {}, [
      el('span.fs-sm', { text: label }),
      el('span.fs-sm.c-secondary.mono', {
        text: `${correct}/${total} · ${rate}%${extra ? ` · ${extra}` : ''}`,
      }),
    ]),
    progressBar(rate, { tone: rateTone(rate) }),
  ]);
}

/** 一组正确率列表 → 卡片体 */
function rateCard(title, iconName, list, emptyText) {
  return card({
    title,
    iconName,
    body: list.length
      ? el('div.stack', { style: { gap: 'var(--sp-3)' } }, list.map((r) => (
        rateBar(r.label, r.correct, r.total, r.correct_rate)
      )))
      : el('div.fs-sm.c-tertiary', { text: emptyText }),
  });
}

/**
 * @param {object} opts
 * @param {(params:object)=>Promise<any>} opts.fetchExams     拉取可选考试列表，返回 [{id, exam_name, ...}]
 * @param {(id:number)=>Promise<any>}     opts.fetchAnalysis  拉取某场考试的分析结果
 * @param {string} [opts.title]
 * @param {string} [opts.subtitle]
 * @param {string} [opts.iconName]
 */
export function createAnalysisView({ fetchExams, fetchAnalysis, title = '成绩分析', subtitle = '考试数据多维度统计与学情诊断', iconName = 'bar-chart-2' }) {
  return function AnalysisView({ query } = {}) {
    const root = el('div.stack');
    const pickerSlot = el('div');
    const bodySlot = el('div.stack');
    const state = { examId: Number(query?.exam_id || 0), exams: [], data: null };

    root.append(
      el('div.page-head', {}, [
        el('div', {}, [
          el('h1.page-title', { text: title }),
          el('p.page-sub', { text: subtitle }),
        ]),
        el('div.page-head-actions', {}, [
          button('刷新', {
            variant: 'secondary', size: 'sm', iconName: 'refresh',
            onClick: (e) => withLoading(e.currentTarget, () => load(), { silent: true }),
          }),
        ]),
      ]),
      pickerSlot,
      bodySlot,
    );

    async function load() {
      mount(bodySlot, loadingOverlay('加载分析数据…'));
      const res = await withLoading(bodySlot, () => fetchExams({}));
      if (!res.ok) {
        mount(bodySlot, alertBox(res.error?.message || '考试列表加载失败，请稍后重试', { type: 'danger' }));
        return;
      }
      state.exams = normalizeExams(res.result);

      if (!state.exams.length) {
        mount(bodySlot, emptyStated('暂无可分析的考试', {
          iconName,
          desc: '考试结束并完成判分后，才能进行成绩与学情分析',
        }));
        return;
      }
      if (!state.examId || !state.exams.some((e) => String(e.id) === String(state.examId))) {
        state.examId = Number(state.exams[0].id);
      }
      renderPicker();
      await loadAnalysis();
    }

    function renderPicker() {
      clear(pickerSlot);
      pickerSlot.append(card({
        iconName: 'calendar',
        body: segmented(
          state.exams.slice(0, 12).map((e) => ({ key: String(e.id), label: e.exam_name })),
          String(state.examId),
          (k) => { state.examId = Number(k); void loadAnalysis(); },
        ),
      }));
    }

    async function loadAnalysis() {
      const res = await withLoading(bodySlot, () => fetchAnalysis(state.examId));
      if (!res.ok) {
        mount(bodySlot, alertBox(res.error?.message || '分析数据加载失败', { type: 'danger' }));
        return;
      }
      state.data = res.result || null;
      renderBody();
    }

    function renderBody() {
      const d = state.data;
      if (!d) { mount(bodySlot, emptyStated('暂无分析数据')); return; }
      const s = d.summary || {};

      if (!s.count) {
        mount(bodySlot, emptyStated('该考试尚无已交卷考生', {
          iconName: 'users',
          desc: '分析仅统计状态为「已交卷」的考生；如有考生未交卷，请先在监考中心收卷',
        }));
        return;
      }

      /* ---- 概览统计卡 ---- */
      const stats = el('div.grid-stats', {}, [
        statCard({ label: '参考人数', value: fmtNumber(s.count), iconName: 'users' }),
        statCard({ label: '平均分', value: fmtScore(s.avg), iconName: 'trending-up', tone: 'brand' }),
        statCard({ label: '中位数', value: fmtScore(s.median), iconName: 'activity' }),
        statCard({ label: '最高分', value: fmtScore(s.max), iconName: 'award', tone: 'success' }),
        statCard({ label: '最低分', value: fmtScore(s.min), iconName: 'trending-down', tone: 'warning' }),
        statCard({
          label: `及格率（≥${s.pass_score ?? 0}分）`,
          value: `${s.pass_rate}%`,
          iconName: 'check-circle',
          tone: s.pass_rate >= 60 ? 'success' : 'warning',
        }),
        statCard({
          label: `优秀率（≥${s.excellent_score ?? 0}分）`,
          value: `${s.excellent_rate}%`,
          iconName: 'sparkles',
          tone: 'brand',
        }),
        statCard({ label: '标准差', value: fmtScore(s.std_dev), iconName: 'activity' }),
      ]);

      /* ---- 分数段分布 ---- */
      const distTotal = (d.distribution || []).reduce((a, b) => a + (b.count || 0), 0) || 1;
      const distCard = card({
        title: '分数段分布',
        iconName: 'bar-chart-2',
        body: el('div.stack', { style: { gap: 'var(--sp-3)' } }, (d.distribution || []).map((b) => (
          rateBar(`${b.key} 分 · ${b.label}`, b.count, distTotal, b.rate)
        ))),
      });

      /* ---- 题型 / 难度正确率 ---- */
      const typeCard = rateCard('各题型正确率', 'clipboard', d.by_type || [], '本场考试暂无客观题作答数据');
      const diffCard = rateCard('各难度正确率', 'sliders', d.by_diff || [], '题库未标注难度，无法按难度分析');

      /* ---- 知识点正确率（仅当题库有知识点） ---- */
      const kpList = (d.by_kp || []).slice().sort((a, b) => a.correct_rate - b.correct_rate);
      const kpCard = kpList.length
        ? rateCard('知识点掌握情况（由低到高）', 'target', kpList, '')
        : card({
          title: '知识点掌握情况',
          iconName: 'target',
          body: el('div.fs-sm.c-tertiary', {
            text: '题库题目尚未填写「知识点」，无法按章节分析。可在题库管理为题目补充知识点后重新查看。',
          }),
        });

      /* ---- 班级横向对比 ---- */
      const classes = d.classes || [];
      const classCard = card({
        title: '班级横向对比',
        iconName: 'layers',
        body: classes.length
          ? table({
            size: 'sm',
            columns: [
              { key: 'class_name', title: '班级', render: (r) => el('span.fw-500', { text: r.class_name }) },
              { key: 'count', title: '人数', align: 'center', render: (r) => el('span.mono', { text: String(r.count) }) },
              { key: 'avg', title: '平均分', align: 'right', render: (r) => el('strong.mono', { text: fmtScore(r.avg) }) },
              { key: 'max', title: '最高', align: 'right', render: (r) => el('span.mono', { text: fmtScore(r.max) }) },
              { key: 'min', title: '最低', align: 'right', render: (r) => el('span.mono', { text: fmtScore(r.min) }) },
              { key: 'pass_rate', title: '及格率', align: 'center', render: (r) => badge(`${r.pass_rate}%`, { tone: r.pass_rate >= 60 ? 'success' : 'warning' }) },
            ],
            rows: classes,
          })
          : el('div.fs-sm.c-tertiary', { text: '考生未关联班级，或本场考试只涉及一个班级' }),
      });

      /* ---- 薄弱题目 ---- */
      const weak = d.weak_items || [];
      const weakCard = card({
        title: '薄弱题目 TOP',
        iconName: 'alert-triangle',
        body: weak.length
          ? table({
            size: 'sm',
            columns: [
              { key: '_no', title: '#', width: '44px', align: 'center', render: (r) => el('span.mono', { text: String(weak.indexOf(r) + 1) }) },
              { key: 'quiz_title', title: '题干', render: (r) => el('div.fs-sm', { text: (r.quiz_title || '（无题干）').slice(0, 60) }) },
              { key: 'type_label', title: '题型', align: 'center', render: (r) => badge(r.type_label || r.quiz_class, { tone: 'info' }) },
              { key: 'correct_rate', title: '正确率', align: 'center', render: (r) => badge(`${r.correct_rate}%`, { tone: r.correct_rate >= 60 ? '' : 'danger' }) },
              { key: 'total', title: '作答数', align: 'right', render: (r) => el('span.mono', { text: String(r.total) }) },
            ],
            rows: weak,
          })
          : el('div.fs-sm.c-tertiary', { text: '暂无逐题统计数据' }),
      });

      /* ---- 主观题待批阅提示 ---- */
      const pending = d.pending_types || [];
      const pendingNode = pending.length
        ? alertBox(
          pending.map((p) => `${p.label} 共 ${p.pending} 份作答未纳入自动判分，需人工批阅`).join('；'),
          { type: 'info', title: '存在待批阅的主观题' },
        )
        : null;

      /* ---- 参数说明 ---- */
      const metaCard = card({
        title: '统计口径',
        iconName: 'info',
        body: descList([
          ['考试', d.exam?.name || '—'],
          ['满分', fmtScore(d.exam?.full_score)],
          ['及格线', `${s.pass_line}%（${s.pass_score} 分）`],
          ['优秀线', `${s.excellent_line}%（${s.excellent_score} 分）`],
          ['统计范围', '仅已交卷考生；未作答按答错计入分母（更能反映掌握度）'],
          ['问答题', '因无自动判分，不纳入正确率统计'],
        ]),
      });

      mount(bodySlot, [
        stats,
        el('div.grid-2', {}, [distCard, classCard]),
        el('div.grid-2', {}, [typeCard, diffCard]),
        kpCard,
        weakCard,
        pendingNode,
        metaCard,
      ]);
    }

    void load();
    return root;
  };
}

/** 统一两端的返回结构：教师端 {exams}，管理端 {list} */
function normalizeExams(result) {
  if (!result) return [];
  const raw = result.exams || result.list || [];
  return raw
    .filter((e) => {
      const st = String(e.exam_status ?? '');
      // 教师端接口已只返回已结束考试；管理端需自行过滤，未结束的没有成绩可分析
      return st === '' || st === 'over' || st.startsWith('over');
    })
    .map((e) => ({ id: e.id, exam_name: e.exam_name || `考试 #${e.id}` }));
}
