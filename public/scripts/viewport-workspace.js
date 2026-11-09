(function () {
  'use strict';
  const body = document.body;
  const main = body?.querySelector(':scope > main.content');
  const topbar = body?.querySelector(':scope > .topbar');
  const sidebar = body?.querySelector(':scope > .sidebar-wrapper #sidebar');
  if (!main || !topbar || !sidebar || body.matches('.cashier-pos-shell, .kape-orders-receipt-template, .kape-pages-welcome, [class*="kape-auth-"]')) return;
  const desktop = window.matchMedia('screen and (min-width: 1101px) and (min-height: 640px)');
  const fitPage = main.matches('.user-management-page, .category-page, .item-page, .inventory-page, .order-page, .order-list-page, .payment-page, .sales-reports-workspace, .manager-settings-page, .backup-page') || Boolean(main.querySelector('[data-dashboard-tabs], .customer-history-panels'));
  const scrollAreas = [
    ['.backup-tab-panel:not([hidden])', 'Backup section', 160],
    ['.management-table-frame', 'Records', 120],
    ['[data-sales-report-history]', 'Generated reports', 120],
    ['.inventory-log-table-frame', 'Inventory records', 120],
    ['[data-dashboard-tabs] .dashboard-tab-panel:not([hidden])', 'Dashboard section', 160],
    ['#inventory-panel-recipes:not([hidden])', 'Recipe workspace', 160],
    ['.order-items-grid', 'Menu items', 120],
    ['.summary-table-wrap', 'Current ticket', 80],
    ['#orders-grid', 'Orders', 160],
    ['.payment-terminal-panel', 'Cash payment', 200],
    ['.payment-side-panel', 'Payment and order details', 200],
    ['.manager-reorder-grid', 'Inventory reorder settings', 120]
  ];
  let queued = false, active = false;
  function schedule() {
    if (queued) return;
    queued = true;
    window.requestAnimationFrame(() => { queued = false; update(); });
  }
  function accessibleRegion(element, label) {
    element.setAttribute('data-workspace-scroll', '');
    if (!element.hasAttribute('tabindex')) element.setAttribute('tabindex', '0');
    if (!element.hasAttribute('role') && element !== main) element.setAttribute('role', 'region');
    if (!element.hasAttribute('aria-label') && !element.hasAttribute('aria-labelledby')) element.setAttribute('aria-label', label);
  }
  function update() {
    if (!desktop.matches) {
      body.classList.remove('viewport-shell', 'viewport-fill', 'viewport-document');
      document.documentElement.classList.remove('viewport-root');
      body.style.removeProperty('--workspace-top');
      main.dataset.viewportMode = 'flow';
      active = false;
      return;
    }
    const firstActivation = !active;
    active = true;
    const savedTop = main.scrollTop, savedLeft = main.scrollLeft;
    const top = Math.max(0, Math.ceil(topbar.getBoundingClientRect().bottom)) + 12;
    const offset = top + 'px';
    if (body.style.getPropertyValue('--workspace-top') !== offset) body.style.setProperty('--workspace-top', offset);
    document.documentElement.classList.add('viewport-root');
    body.classList.add('viewport-shell');
    body.classList.toggle('viewport-fill', fitPage);
    body.classList.toggle('viewport-document', !fitPage);
    accessibleRegion(main, 'Workspace');
    const visible = [];
    scrollAreas.forEach(([selector, label, minimum]) => {
      main.querySelectorAll(selector).forEach(element => {
        accessibleRegion(element, label);
        if (!element.closest('[hidden]') && element.getClientRects().length) visible.push({ element, minimum });
      });
    });
    // A short record area or overflowing fixed controls needs natural flow in one frame.
    const fixedPosWorkspace = main.matches('.order-page') && main.clientHeight >= 700;
    const fits = fitPage && visible.length > 0 && visible.every(({element,minimum}) => element.clientHeight >= minimum) && (fixedPosWorkspace || (main.scrollHeight <= main.clientHeight + 2 && main.scrollWidth <= main.clientWidth + 2));
    body.classList.toggle('viewport-fill', fits);
    body.classList.toggle('viewport-document', !fits);
    main.dataset.viewportMode = fits ? 'panels' : 'document';
    if (fixedPosWorkspace && fits) { main.scrollTop = 0; main.scrollLeft = 0; }
    else if (!firstActivation) { main.scrollTop = savedTop; main.scrollLeft = savedLeft; }
    sidebar.querySelectorAll('a[data-tooltip]').forEach(link => {
      if (!link.hasAttribute('title')) link.setAttribute('title', link.dataset.tooltip);
      if (!link.hasAttribute('aria-label')) link.setAttribute('aria-label', link.dataset.tooltip);
    });
  }
  desktop.addEventListener('change', schedule);
  window.addEventListener('resize', schedule);
  window.addEventListener('pageshow', schedule);
  window.addEventListener('afterprint', schedule);
  if (window.ResizeObserver) {
    const resize = new ResizeObserver(schedule);
    resize.observe(main); resize.observe(topbar);
  }
  if (window.MutationObserver) {
    new MutationObserver(schedule).observe(main, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['hidden', 'class'] });
  }
  if (document.fonts) {
    document.fonts.ready.then(schedule);
    document.fonts.addEventListener?.('loadingdone', schedule);
  }
  update();
})();
