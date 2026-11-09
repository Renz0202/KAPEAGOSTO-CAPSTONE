<section class="inventory-forecast-workspace" aria-label="Inventory planning dashboard">
  <div class="if-summary" id="inventoryForecastSummary" aria-live="polite"></div>
  <article class="dashboard-panel if-controls">
    <div><span class="panel-eyebrow">Material planning</span><h2>Stock outlook &amp; purchase budget</h2><p>Projected balances assume the current daily usage continues, with no deliveries. Different units are never added together.</p></div>
    <label>Material<select id="ifMaterial"></select></label>
    <label>Planning horizon<select id="ifHorizon"><option value="7">7 days</option><option value="14" selected>14 days</option><option value="30">30 days</option></select></label>
    <label>Estimated cost (PHP per inventory unit)<input id="ifCost" type="number" min="0" step="0.01" placeholder="Enter unit cost" inputmode="decimal"></label>
    <p id="ifBudget" role="status"></p>
    <p><small>Cost is a temporary planning input for the selected material. Estimate excludes delivery, taxes, and supplier pack sizes; it is not saved or used to place orders.</small></p>
  </article>
  <div class="if-charts">
    <article class="dashboard-panel"><h3>Projected stock balance</h3><p id="ifProjectionNote"></p><div class="if-chart"><canvas id="ifProjection" role="img" aria-label="Projected material stock and reorder threshold"></canvas></div></article>
    <article class="dashboard-panel"><h3>Coverage versus supplier lead time</h3><p>Up to 8 materials with the shortest known coverage, in days.</p><div class="if-chart"><canvas id="ifCoverage" role="img" aria-label="Material coverage compared with supplier lead time"></canvas></div></article>
    <article class="dashboard-panel"><h3>Inventory readiness</h3><p>Material counts by restock status, including missing usage history.</p><div class="if-chart"><canvas id="ifRisk" role="img" aria-label="Material counts by inventory readiness"></canvas></div></article>
  </div>
  <div class="if-filters"><label>Find material<input type="search" id="ifSearch" placeholder="Search name or unit"></label><label>Restock status<select id="ifStatus"><option value="all">All materials</option><option value="restock">Restock needed</option><option value="sufficient">Stock sufficient</option><option value="unknown">Awaiting usage history</option></select></label><span id="ifResults" role="status"></span></div>
</section>
<style>
.inventory-forecast-workspace{margin:16px 0}.if-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.if-summary article{background:#fffaf4;border:1px solid #e5d8cc;border-radius:14px;padding:16px}.if-summary strong{display:block;font-size:26px;color:#5b392b}.if-summary span{font-size:13px;color:#715a4c}.if-controls{display:flex;flex-wrap:wrap;gap:16px;margin-bottom:14px}.if-controls>div,.if-controls>p{flex-basis:100%;margin:0}.if-controls label,.if-filters label{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:600;flex:1;min-width:170px}.inventory-forecast-workspace input,.inventory-forecast-workspace select{padding:10px;border:1px solid #d7c5b5;border-radius:8px;background:white;color:#503b30;max-width:100%;width:100%}.if-charts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.if-charts article{min-width:0}.if-charts p{font-size:12px;color:#715a4c}.if-chart{height:250px;position:relative}.if-filters{display:flex;align-items:end;flex-wrap:wrap;gap:14px;margin-top:16px}.if-filters span{font-size:13px;padding:10px}#ifBudget{font-weight:700;color:#146a69}@media(max-width:760px){.if-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.if-charts{grid-template-columns:1fr}}
</style>
<style>
/* Forecast cards need their own inset; the shared panel only supplies a clipped border. */
body.kape-pages-dashboard .inventory-forecast-workspace .dashboard-panel {
  padding: 20px;
  min-width: 0;
  height: auto;
  overflow: visible;
}
body.kape-pages-dashboard .inventory-forecast-workspace .if-controls {
  display: flex;
  align-items: flex-start;
}
body.kape-pages-dashboard .inventory-forecast-workspace :is(h2, h3, p, label, small, .if-summary span) {
  white-space: normal;
  overflow-wrap: anywhere;
  text-overflow: clip;
  line-height: 1.5;
  max-height: none;
}
body.kape-pages-dashboard .inventory-forecast-workspace :is(h2, h3) { margin: 0 0 8px; }
body.kape-pages-dashboard .inventory-forecast-workspace .if-charts article {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
body.kape-pages-dashboard .inventory-forecast-workspace .if-charts p {
  margin: 0;
  font-size: 13px;
}
body.kape-pages-dashboard .inventory-forecast-workspace .if-chart {
  flex: 0 0 auto;
  min-width: 0;
  width: 100%;
  margin-top: auto;
  height: 280px;
}
body.kape-pages-dashboard .inventory-forecast-workspace :is(.if-controls label, .if-filters label) {
  min-width: 0;
  flex: 1 1 220px;
}
body.kape-pages-dashboard .inventory-forecast-workspace :is(input, select) { min-height: 44px; }
body.kape-pages-dashboard #dashboard-panel-inventory { scroll-padding-block: 16px; }
body.kape-pages-dashboard #dashboard-panel-inventory .manager-table-wrap :is(th, td) {
  white-space: normal;
  overflow-wrap: anywhere;
  line-height: 1.5;
}
@media (max-width: 760px) {
  body.kape-pages-dashboard .inventory-forecast-workspace .dashboard-panel { padding: 16px; }
}
</style>
