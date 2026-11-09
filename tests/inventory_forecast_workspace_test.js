'use strict';
const fs = require('fs'), vm = require('vm'), assert = require('assert');
function element() {
  return { value: '', textContent: '', hidden: false, children: [], events: {},
    addEventListener(name, fn) { this.events[name] = fn; },
    replaceChildren(...children) { this.children = children; if (children.length) this.value = String(children[0].value); },
    append(...children) { this.children.push(...children); } };
}
const nodes = {}, charts = {};
['inventoryPlanningData', 'ifMaterial', 'ifCost', 'ifHorizon', 'ifBudget', 'ifProjectionNote', 'ifProjection', 'ifCoverage', 'ifRisk', 'inventoryForecastSummary', 'ifSearch', 'ifStatus', 'ifResults', 'dashboard-panel-inventory'].forEach(id => { nodes[id] = element(); nodes[id].id = id; });
nodes.ifHorizon.value = '14'; nodes.ifStatus.value = 'all';
const sample = [{ id: 1, name: '<Milk>', cup_size: '', unit: 'ml', qty: 20, days_left: 2, needs_restock: true, forecast: { daily: 10, lead: 3, reorder: 50, buffer: 20, recommended: 100 } }];
nodes.inventoryPlanningData.textContent = JSON.stringify(sample);
const tableRow = { textContent: '<Milk> ml', dataset: { forecastStatus: 'restock' }, hidden: false };
const context = { Map, Number, Array, Object, String, JSON, Math,
  document: { getElementById: id => nodes[id], createElement: element, querySelectorAll: () => [tableRow] },
  Option: function (text, value) { this.textContent = text; this.value = value; },
  MutationObserver: function () { this.observe = function () {}; },
  Chart: function (canvas, config) { this.data = config.data; this.update = function () {}; this.resize = function () {}; charts[canvas.id] = this; }
};
context.window = context;
vm.runInNewContext(fs.readFileSync('public/scripts/inventory-forecast.js', 'utf8'), context);
assert(nodes.ifBudget.textContent.includes('170 ml'), 'purchase covers lead + horizon + buffer less on-hand');
nodes.ifCost.value = '2.5'; nodes.ifCost.events.input.call(nodes.ifCost);
assert(nodes.ifBudget.textContent.includes('PHP 425'), 'budget uses purchase quantity times inventory-unit cost');
assert.strictEqual(charts.ifProjection.data.datasets[0].data[2], 0, 'projection reaches zero on depletion day');
assert.strictEqual(charts.ifProjection.data.datasets[0].data[14], 0, 'projection never goes negative');
nodes.ifHorizon.value = '7'; nodes.ifHorizon.events.change();
assert(nodes.ifBudget.textContent.includes('100 ml'), 'horizon changes planned purchase');
context.renderInventoryForecast(sample);
assert.strictEqual(nodes.ifCost.value, '2.5', 'refresh preserves entered material cost');
nodes.ifSearch.value = 'coffee'; context.filterInventoryForecast(); assert(tableRow.hidden, 'search hides nonmatching material');
nodes.ifSearch.value = ''; nodes.ifStatus.value = 'sufficient'; context.filterInventoryForecast(); assert(tableRow.hidden, 'status filter hides nonmatching status');
context.renderInventoryForecast([{ ...sample[0], qty: 0, days_left: null, forecast: { daily: 0, lead: 3, reorder: 0, buffer: 0 } }]);
assert(nodes.ifBudget.textContent.includes('Awaiting usage history'), 'missing history does not create a budget');
assert(charts.ifProjection.data.datasets[0].data.every(v => v === null), 'missing history does not create a flat forecast');
context.renderInventoryForecast([]);
assert(nodes.ifBudget.textContent.includes('No inventory records'), 'empty inventory has a clear state');
console.log('Inventory forecast planning, budget, refresh, filters, and missing-history checks passed.');
