(function () {
  'use strict';
  const source = document.getElementById('inventoryPlanningData');
  if (!source) return;
  const get = id => document.getElementById(id);
  const material = get('ifMaterial');
  if (!material) return;
  let rows = [], charts = {};
  const costs = new Map();
  const number = value => Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
  const label = row => row.name + (row.cup_size ? ' (' + row.cup_size + ')' : '');
  function chart(id, type, labels, datasets, options = {}) {
    if (!window.Chart) return;
    if (charts[id]) { charts[id].data = { labels, datasets }; charts[id].update('none'); return; }
    charts[id] = new Chart(get(id), { type, data: { labels, datasets }, options: { responsive: true, maintainAspectRatio: false, ...options } });
  }
  function draw() {
    const row = rows.find(r => String(r.id) === material.value);
    get('ifCost').value = costs.has(material.value) ? costs.get(material.value) : '';
    if (!row) {
      get('ifBudget').textContent = 'No inventory records available.';
      get('ifProjectionNote').textContent = 'Add inventory materials to start planning.';
      chart('ifProjection', 'line', [], []);
      return;
    }
    const f = row.forecast, daily = Number(f.daily), horizon = Number(get('ifHorizon').value);
    const qty = Math.max(0, Number(row.qty));
    const target = Math.ceil(daily * (Number(f.lead) + horizon) + Number(f.buffer));
    const buy = daily > 0 ? Math.max(0, Math.ceil(target - Number(row.qty))) : null;
    const cost = costs.get(material.value);
    get('ifBudget').textContent = buy === null ? 'Awaiting usage history. Budget cannot be estimated yet.' :
      'Planning purchase: ' + number(buy) + ' ' + row.unit + ' to cover lead time + ' + horizon + ' days + safety buffer. ' +
      (cost === undefined || cost === '' ? 'Enter unit cost to estimate budget.' : 'Estimated budget: PHP ' + number(buy * Number(cost)));
    const balance = daily > 0 ? Math.max(0, qty - daily * horizon) : null;
    get('ifProjectionNote').textContent = daily > 0 ? label(row) + ': ' + number(daily) + ' ' + row.unit + '/day. Day ' + horizon + ' balance: ' + number(balance) + ' ' + row.unit + '. ' + (qty / daily <= horizon ? 'Estimated depletion in ' + number(qty / daily) + ' days.' : 'Coverage exceeds this horizon.') : 'Awaiting usage history; no stock projection available.';
    const days = Array.from({ length: horizon + 1 }, (_, i) => 'Day ' + i);
    chart('ifProjection', 'line', days, [
      { label: 'Projected balance (' + row.unit + ')', data: days.map((_, i) => daily > 0 ? Math.max(0, qty - daily * i) : null), borderColor: '#137e83', pointRadius: 0 },
      { label: 'Reorder point (' + row.unit + ')', data: days.map(() => daily > 0 ? Number(f.reorder) : null), borderColor: '#aa8545', borderDash: [6, 4], pointRadius: 0 }
    ], { scales: { y: { beginAtZero: true } } });
  }
  window.filterInventoryForecast = function () {
    const query = get('ifSearch').value.trim().toLowerCase(), status = get('ifStatus').value;
    let shown = 0, total = 0;
    document.querySelectorAll('#dashboardInventoryForecastRows tr[data-forecast-status]').forEach(row => {
      total++;
      row.hidden = !(row.textContent.toLowerCase().includes(query) && (status === 'all' || row.dataset.forecastStatus === status));
      if (!row.hidden) shown++;
    });
    get('ifResults').textContent = shown + ' of ' + total + ' materials';
  };
  window.renderInventoryForecast = function (data) {
    rows = data;
    const selected = material.value;
    material.replaceChildren(...rows.map(row => new Option(label(row) + ' · ' + row.unit, row.id)));
    if (rows.some(row => String(row.id) === selected)) material.value = selected;
    const restock = rows.filter(r => r.needs_restock).length;
    const unknown = rows.filter(r => !r.needs_restock && Number(r.forecast.daily) <= 0).length;
    const sufficient = rows.length - restock - unknown;
    const empty = rows.filter(r => Number(r.qty) <= 0).length;
    get('inventoryForecastSummary').replaceChildren(...[[rows.length, 'Materials monitored'], [restock, 'Need restocking'], [empty, 'Out of stock'], [rows.filter(r => Number(r.forecast.daily) <= 0).length, 'Without usage history']].map(([value, title]) => {
      const card = document.createElement('article'), strong = document.createElement('strong'), span = document.createElement('span');
      strong.textContent = value; span.textContent = title; card.append(strong, span); return card;
    }));
    chart('ifRisk', 'doughnut', ['Restock needed', 'Stock sufficient', 'Awaiting history'], [{ data: [restock, sufficient, unknown], backgroundColor: ['#b45b4c', '#137e83', '#c9bba5'] }]);
    const priority = rows.filter(r => r.days_left !== null).sort((a, b) => a.days_left - b.days_left).slice(0, 8);
    chart('ifCoverage', 'bar', priority.map(label), [
      { label: 'Coverage (days)', data: priority.map(r => Number(r.days_left)), backgroundColor: '#137e83' },
      { label: 'Supplier lead time (days)', data: priority.map(r => Number(r.forecast.lead)), backgroundColor: '#aa8545' }
    ], { indexAxis: 'y', scales: { x: { beginAtZero: true } } });
    draw();
  };
  material.addEventListener('change', draw);
  get('ifHorizon').addEventListener('change', draw);
  get('ifCost').addEventListener('input', function () {
    const raw = this.value;
    costs.set(material.value, raw !== '' && Number.isFinite(Number(raw)) && Number(raw) >= 0 ? raw : '');
    draw();
  });
  get('ifSearch').addEventListener('input', window.filterInventoryForecast);
  get('ifStatus').addEventListener('change', window.filterInventoryForecast);
  new MutationObserver(() => { if (!get('dashboard-panel-inventory').hidden) Object.values(charts).forEach(c => c.resize()); }).observe(get('dashboard-panel-inventory'), { attributes: true, attributeFilter: ['hidden'] });
  window.renderInventoryForecast(JSON.parse(source.textContent));
  window.filterInventoryForecast();
})();
