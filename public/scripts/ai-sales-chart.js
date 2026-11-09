(function () {
  const canvas = document.getElementById('aiSalesChart');
  const metric = document.getElementById('aiSalesMetric');
  const status = document.getElementById('aiSalesChartStatus');
  if (!canvas || !metric) return;
  let data = null;
  let chart = null;
  function format(value) {
    if (value === null || value === undefined) return '—';
    return metric.value === 'revenue'
      ? 'PHP ' + Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      : Number(value).toLocaleString('en-PH', { maximumFractionDigits: 1 });
  }
  function render() {
    if (!data) { status.textContent = 'Sales history is temporarily unavailable.'; return; }
    const values = data.metrics[metric.value];
    const actual = values.actual.slice(0, -2); // Exclude tomorrow and today (partial).
    const recent = actual.slice(-7).reduce(function (sum, value) { return sum + value; }, 0);
    const previous = actual.slice(-14, -7).reduce(function (sum, value) { return sum + value; }, 0);
    const pattern = document.getElementById('aiSalesPattern');
    if (pattern) {
      const change = previous > 0 ? (recent - previous) / previous * 100 : null;
      const comparison = change === null ? 'There is no prior-week baseline for a percentage comparison.'
        : 'That is ' + Math.abs(change).toFixed(1) + '% ' + (change >= 0 ? 'higher' : 'lower') + ' than the preceding 7 days.';
      const periods = data.comparison;
      const dates = periods ? ' (' + periods.recentStart + ' to ' + periods.recentEnd + ')' : '';
      pattern.textContent = data.hasHistory
        ? 'Last 7 complete days' + dates + ': ' + format(recent) + '; previous 7 complete days: ' + format(previous) + '. ' + comparison +
          ' Tomorrow estimate: ' + format(values.projection[values.projection.length - 1]) +
          '. Forecast blends recent matching weekdays with the completed-day trend. Today so far is shown separately and excluded from forecasts and period comparisons.'
        : 'Complete and record sales to build a sales history.';
    }
    status.textContent = !data.hasHistory ? 'Awaiting completed sales history. No forecast is available yet.'
      : !window.Chart ? 'Graph unavailable. Use View chart data below to inspect the results.' : '';
    const rows = document.getElementById('aiSalesChartRows');
    rows.replaceChildren();
    data.dates.forEach(function (date, index) {
      const row = document.createElement('tr');
      [date + (index === data.dates.length - 2 ? ' (today, partial)' : ''), format(values.actual[index]), format(values.average[index]), format(values.saved[index]), index === data.dates.length - 1 ? format(values.projection[index]) : '—'].forEach(function (text) {
        const cell = document.createElement('td');
        cell.textContent = text;
        row.appendChild(cell);
      });
      rows.appendChild(row);
    });
    if (!window.Chart) return;
    const datasets = [
      { label: 'Actual (today partial)', data: values.actual, borderColor: '#6d3b2b', backgroundColor: '#6d3b2b', pointRadius: 3 },
      { label: '7-day average', data: values.average, borderColor: '#287e80', backgroundColor: '#287e80', pointRadius: 0 },
      { label: 'Saved forecast', data: values.saved, borderColor: '#8066a6', backgroundColor: '#8066a6', pointStyle: 'rectRot', pointRadius: 5, showLine: false },
      { label: 'Tomorrow estimate', data: values.projection, borderColor: '#ba7a22', backgroundColor: '#ba7a22', borderDash: [6, 5], pointRadius: 5 }
    ];
    if (chart) {
      chart.data.labels = data.dates;
      chart.data.datasets = datasets;
      chart.options.scales.y.title.text = metric.value === 'revenue' ? 'Revenue (PHP)' : 'Completed orders';
      chart.update('none');
      return;
    }
    chart = new Chart(canvas, {
      type: 'line',
      data: { labels: data.dates, datasets: datasets },
      options: {
        responsive: true, maintainAspectRatio: false, spanGaps: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { boxWidth: 12, color: '#4a342c' } },
          tooltip: { callbacks: { label: function (context) { return context.dataset.label + ': ' + format(context.parsed.y); } } }
        },
        scales: {
          x: { ticks: { maxTicksLimit: 8, callback: function (value) { return this.getLabelForValue(value).slice(5); } }, title: { display: true, text: 'Date (MM-DD)' } },
          y: { beginAtZero: true, title: { display: true, text: metric.value === 'revenue' ? 'Revenue (PHP)' : 'Completed orders' } }
        }
      }
    });
  }
  window.renderAiSalesChart = function (nextData) { data = nextData; render(); };
  metric.addEventListener('change', render);
  window.renderAiSalesChart(JSON.parse(document.getElementById('aiSalesChartData').textContent));
})();
