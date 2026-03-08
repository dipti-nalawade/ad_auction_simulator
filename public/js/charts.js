'use strict';

/* ── Private state ────────────────────────────────────────────────────────────
   Keyed by canvas id so each can be destroyed before recreation.             */
const _charts = {};

/* ── Shared dark theme ────────────────────────────────────────────────────────
   Background colour (#1a1a2e) is applied directly to each canvas element.    */
const _dark = {
  animation: { duration: 380 },
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor:  '#191928',
      titleColor:       '#00ff88',
      bodyColor:        '#dde1f0',
      borderColor:      '#2a2a42',
      borderWidth:      1,
      titleFont:        { family: 'JetBrains Mono', size: 11, weight: '700' },
      bodyFont:         { family: 'JetBrains Mono', size: 10 },
      padding:          10,
      displayColors:    false,
      cornerRadius:     4,
    },
  },
  scales: {
    x: {
      grid:  { color: 'rgba(42,42,66,.8)', drawBorder: false },
      ticks: { color: '#555570', font: { family: 'JetBrains Mono', size: 9 } },
    },
    y: {
      grid:  { color: 'rgba(42,42,66,.8)', drawBorder: false },
      ticks: { color: '#555570', font: { family: 'JetBrains Mono', size: 9 } },
    },
  },
};

/* ── Internal chart factory ───────────────────────────────────────────────────
   Destroys any existing instance for the canvas before creating a new one.   */
function _mk(id, config) {
  if (_charts[id]) _charts[id].destroy();
  const canvas = document.getElementById(id);
  if (!canvas) return null;
  canvas.style.background = '#1a1a2e';
  _charts[id] = new Chart(canvas.getContext('2d'), config);
  return _charts[id];
}

/* ── Public: initCharts() ─────────────────────────────────────────────────────
   Sets Chart.js global defaults and renders empty placeholder charts so the
   canvases are never visually blank on first load.                            */
function initCharts() {
  Chart.defaults.color       = '#555570';
  Chart.defaults.font.family = 'JetBrains Mono';

  // CPM trend — empty line
  _mk('chart-cpm', {
    type: 'line',
    data: {
      labels: [],
      datasets: [{ data: [], borderColor: '#00ff88', backgroundColor: 'rgba(0,255,136,.07)', fill: true }],
    },
    options: _dark,
  });

  // Win rates — empty horizontal bar
  _mk('chart-wins', {
    type: 'bar',
    data: {
      labels: [],
      datasets: [{ data: [], backgroundColor: 'rgba(0,255,136,.45)', borderColor: '#00ff88', borderWidth: 1 }],
    },
    options: { ..._dark, indexAxis: 'y' },
  });

  // Bid distribution — empty vertical bar
  _mk('chart-dist', {
    type: 'bar',
    data: {
      labels: [],
      datasets: [{ data: [], backgroundColor: 'rgba(68,136,255,.5)', borderColor: '#4488ff', borderWidth: 1 }],
    },
    options: _dark,
  });
}

/* ── Public: updateCpmChart(data) ─────────────────────────────────────────────
   data: [{date, avg_cpm, min_cpm, max_cpm}, …]  (from analytics.cpm_trend)
   Renders a filled line for avg CPM and a dashed line for max CPM.           */
function updateCpmChart(data) {
  const titleEl = document.querySelector('#chart-cpm')
    ?.closest('.chart-wrap')
    ?.querySelector('.chart-title');

  if (!data?.length) {
    if (titleEl) titleEl.textContent = 'CPM Trend — no data yet';
    return;
  }
  if (titleEl) titleEl.textContent = 'CPM Trend — 30 days';

  _mk('chart-cpm', {
    type: 'line',
    data: {
      labels: data.map(r => r.date.slice(5)),   // MM-DD
      datasets: [
        {
          label:                'Avg CPM',
          data:                 data.map(r => r.avg_cpm),
          borderColor:          '#00ff88',
          backgroundColor:      'rgba(0,255,136,.07)',
          fill:                 true,
          tension:              0.35,
          pointRadius:          3,
          pointBackgroundColor: '#00ff88',
          pointHoverRadius:     5,
        },
        {
          label:           'Max CPM',
          data:            data.map(r => r.max_cpm),
          borderColor:     'rgba(0,255,136,.30)',
          borderDash:      [4, 4],
          fill:            false,
          tension:         0.35,
          pointRadius:     0,
          pointHoverRadius: 3,
        },
      ],
    },
    options: {
      ..._dark,
      plugins: {
        ..._dark.plugins,
        legend: {
          display: true,
          labels:  { color: '#555570', font: { family: 'JetBrains Mono', size: 9 }, boxWidth: 20 },
        },
        tooltip: {
          ..._dark.plugins.tooltip,
          callbacks: {
            label: ctx => ` ${ctx.dataset.label}: $${Number(ctx.parsed.y).toFixed(4)}`,
          },
        },
      },
    },
  });
}

/* ── Public: updateWinRateChart(data) ─────────────────────────────────────────
   data: [{bidder_name, wins, total_bids, win_rate_percent}, …]
   Renders a horizontal bar chart sorted by win rate (highest first).
   Bar opacity decreases for lower-ranked bidders for a rank-heatmap effect.  */
function updateWinRateChart(data) {
  if (!data?.length) return;

  _mk('chart-wins', {
    type: 'bar',
    data: {
      labels: data.map(r => r.bidder_name),
      datasets: [{
        label:           'Win Rate %',
        data:            data.map(r => r.win_rate_percent),
        backgroundColor: data.map((_, i) => `rgba(0,255,136,${Math.max(0.20, 0.65 - i * 0.08)})`),
        borderColor:     '#00ff88',
        borderWidth:     1,
        borderRadius:    2,
      }],
    },
    options: {
      ..._dark,
      indexAxis: 'y',
      scales: {
        x: {
          ..._dark.scales.x,
          max:   100,
          ticks: { ..._dark.scales.x.ticks, callback: v => v + '%' },
        },
        y: _dark.scales.y,
      },
      plugins: {
        ..._dark.plugins,
        tooltip: {
          ..._dark.plugins.tooltip,
          callbacks: {
            label:       ctx => ` ${ctx.parsed.x.toFixed(1)}% win rate`,
            afterLabel:  ctx => {
              const d = data[ctx.dataIndex];
              return ` ${d.wins} win${d.wins !== 1 ? 's' : ''} / ${d.total_bids} auction${d.total_bids !== 1 ? 's' : ''}`;
            },
          },
        },
      },
    },
  });
}

/* ── Public: updateDistributionChart(data) ────────────────────────────────────
   data: [{bucket, count}, …]  (from analytics.bid_distribution)
   Renders a vertical bar histogram in info-blue to visually distinguish it
   from the green CPM/win-rate charts.                                         */
function updateDistributionChart(data) {
  if (!data?.length) return;

  _mk('chart-dist', {
    type: 'bar',
    data: {
      labels: data.map(r => `$${r.bucket}`),
      datasets: [{
        label:           'Bids',
        data:            data.map(r => r.count),
        backgroundColor: 'rgba(68,136,255,.50)',
        borderColor:     '#4488ff',
        borderWidth:     1,
        borderRadius:    2,
      }],
    },
    options: {
      ..._dark,
      plugins: {
        ..._dark.plugins,
        tooltip: {
          ..._dark.plugins.tooltip,
          callbacks: {
            label: ctx => ` ${ctx.parsed.y} bid${ctx.parsed.y !== 1 ? 's' : ''} in this range`,
          },
        },
      },
    },
  });
}
