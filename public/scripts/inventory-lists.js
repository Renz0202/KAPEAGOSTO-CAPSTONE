(function () {
  'use strict';
  const lists = [
    { key: 'stock', form: 'inventoryStockFilters', container: 'inventoryRealtime', dialog: 'inventoryStockDetails', status: 'inventoryStockStatus', refresh: 'refreshInventory', ajax: 'ajax_inventory', pageKey: 'stock_page', search: 'search', fields: ['search', 'material_group', 'stock_category', 'stock_status'], stamp: 'invUpdatedAt', label: 'current stock' },
    { key: 'voided', form: 'inventoryVoidFilters', container: 'voidsRealtime', dialog: 'inventoryVoidDetails', status: 'inventoryVoidStatus', refresh: 'refreshVoidedOrders', ajax: 'ajax_voided', pageKey: 'void_page', search: 'void_search', fields: ['void_search', 'void_from', 'void_to', 'void_status'], from: 'void_from', to: 'void_to', stamp: 'voidsUpdatedAt', label: 'voided / cancelled orders' }
  ];
  lists.forEach(function (config) {
    const form = document.getElementById(config.form);
    const container = document.getElementById(config.container);
    if (!form || !container) return;
    const dialog = document.getElementById(config.dialog);
    const status = document.getElementById(config.status);
    let page = Number(container.querySelector('[data-inventory-list]')?.dataset.page || 1);
    let requestNumber = 0, controller = null, loading = false, searchTimer = null, detailsOpener = null;
    const field = name => form.elements.namedItem(name);
    function setStatus(message) {
      if (status) { status.textContent = message; status.hidden = !message; }
    }
    function updateAddress(values) {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', config.key);
      Object.entries(values).forEach(([key, value]) => value ? url.searchParams.set(key, value) : url.searchParams.delete(key));
      if (page > 1) url.searchParams.set(config.pageKey, String(page));
      else url.searchParams.delete(config.pageKey);
      window.history.replaceState({}, '', url);
    }
    window[config.refresh] = async function (options = {}) {
      const foreground = Boolean(options.resetPage || options.updateUrl || options.page !== undefined);
      if (dialog?.open || (!foreground && (loading || searchTimer !== null))) return;
      controller?.abort();
      controller = new AbortController();
      const activeController = controller, request = ++requestNumber;
      const values = Object.fromEntries(config.fields.map(name => [name, field(name)?.value.trim() || '']));
      const params = new URLSearchParams({ [config.ajax]: '1', ...values, [config.pageKey]: String(options.resetPage ? 1 : options.page || page), t: String(Date.now()) });
      loading = true;
      container.setAttribute('aria-busy', 'true');
      if (foreground) setStatus('Loading ' + config.label + '...');
      const finishLoading = window.KapeLoading?.start({ label: 'Updating ' + config.label + '...', delay: foreground ? 350 : 900 });
      try {
        const response = await fetch('inventory.php?' + params, { cache: 'no-store', signal: activeController.signal });
        if (!response.ok) throw new Error('List request failed.');
        const html = await response.text();
        if (request !== requestNumber || dialog?.open) return;
        const template = document.createElement('template');
        template.innerHTML = html;
        const nextView = template.content.querySelector('[data-inventory-list]');
        if (nextView?.dataset.inventoryList !== config.key) throw new Error('Unexpected list response.');
        const frame = container.querySelector('.inventory-list-table-frame');
        const left = frame?.scrollLeft || 0, top = frame?.scrollTop || 0;
        const focusedFrame = document.activeElement === frame;
        const focused = container.contains(document.activeElement) ? document.activeElement : null;
        const focusId = focused?.dataset?.listId, focusAction = focused?.dataset?.listFocus;
        container.replaceChildren(template.content);
        page = Number(nextView.dataset.page || 1);
        const nextFrame = container.querySelector('.inventory-list-table-frame');
        if (!foreground && nextFrame) {
          nextFrame.scrollLeft = left; nextFrame.scrollTop = top;
          if (focusedFrame) nextFrame.focus({ preventScroll: true });
          if (focusId) (container.querySelector('[data-list-id="' + focusId + '"]') || nextFrame).focus({ preventScroll: true });
          else if (focusAction) (container.querySelector('[data-list-focus="' + focusAction + '"]') || nextFrame).focus({ preventScroll: true });
        }
        if (options.page !== undefined) container.querySelector('[data-list-page-status]')?.focus({ preventScroll: true });
        if (options.updateUrl) updateAddress(values);
        const stamp = document.getElementById(config.stamp);
        if (stamp) stamp.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        const metrics = config.key === 'stock'
          ? [['inventory-summary-items', 'X-Inventory-Items'], ['inventory-summary-raw-materials', 'X-Inventory-Raw-Materials'], ['inventory-summary-low-stock', 'X-Inventory-Low-Stock']]
          : [['inventory-voided-today', 'X-Inventory-Voided-Today']];
        metrics.forEach(([id, header]) => {
          const value = response.headers.get(header), element = document.getElementById(id);
          if (element && value !== null) element.textContent = Number(value).toLocaleString('en-PH');
        });
        setStatus('');
      } catch (error) {
        if (error.name !== 'AbortError' && request === requestNumber) setStatus('Could not refresh ' + config.label + '. Showing the previous results.');
      } finally {
        finishLoading?.();
        if (request === requestNumber) { loading = false; container.setAttribute('aria-busy', 'false'); }
      }
    };
    function applyFilters() {
      clearTimeout(searchTimer); searchTimer = null;
      const from = field(config.from), to = field(config.to);
      if (from && to) {
        to.min = from.value;
        if (from.value && to.value && to.value < from.value) to.value = from.value;
      }
      return window[config.refresh]({ resetPage: true, updateUrl: true });
    }
    form.addEventListener('submit', function (event) { event.preventDefault(); applyFilters(); });
    form.addEventListener('change', applyFilters);
    field(config.search)?.addEventListener('input', function () {
      clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 300);
    });
    container.addEventListener('click', function (event) {
      const link = event.target.closest('[data-list-page]');
      if (link) {
        event.preventDefault();
        window[config.refresh]({ page: Number(link.dataset.listPage), updateUrl: true });
        return;
      }
      const button = event.target.closest('[data-list-details]');
      if (!button || !dialog) return;
      try {
        const details = JSON.parse(button.dataset.listDetails);
        dialog.querySelectorAll('[data-list-field]').forEach(element => { element.textContent = String(details[element.dataset.listField] ?? '-'); });
        detailsOpener = button;
        if (!dialog.open) dialog.showModal();
      } catch (error) { setStatus('Details are unavailable. Please refresh this list.'); }
    });
    dialog?.querySelector('[data-close-list-details]')?.addEventListener('click', function () { dialog.close(); });
    dialog?.addEventListener('close', function () {
      const replacement = detailsOpener?.dataset.listId ? container.querySelector('[data-list-id="' + detailsOpener.dataset.listId + '"]') : null;
      (replacement || field(config.search))?.focus({ preventScroll: true });
      detailsOpener = null;
    });
  });
})();
