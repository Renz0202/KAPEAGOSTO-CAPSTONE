(function () {
  'use strict';
  const form = document.getElementById('inventoryHistoryFilters');
  const container = document.getElementById('logsRealtime');
  if (!form || !container) return;
  const dialog = document.getElementById('inventoryMovementDetails');
  const status = document.getElementById('inventoryHistoryStatus');
  let page = Number(container.querySelector('[data-log-view]')?.dataset.page || 1);
  let requestNumber = 0;
  let controller = null;
  let loading = false;
  let searchTimer = null;
  let detailsOpener = null;

  function filters() {
    return Object.fromEntries(['from', 'to', 'log_type', 'log_search'].map(name => [name, form.elements.namedItem(name)?.value.trim() || '']));
  }
  function setStatus(message) {
    if (!status) return;
    status.textContent = message;
    status.hidden = !message;
  }
  function updateAddress(values, currentPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', 'logs');
    Object.entries(values).forEach(([name, value]) => value ? url.searchParams.set(name, value) : url.searchParams.delete(name));
    if (currentPage > 1) url.searchParams.set('log_page', String(currentPage));
    else url.searchParams.delete('log_page');
    window.history.replaceState({}, '', url);
  }

  window.refreshLogs = async function (options = {}) {
    const foreground = Boolean(options.resetPage || options.updateUrl || options.page !== undefined);
    if (dialog?.open || (!foreground && (loading || searchTimer !== null))) return;
    if (controller) controller.abort();
    controller = new AbortController();
    const activeController = controller;
    const request = ++requestNumber;
    const requestedPage = options.resetPage ? 1 : Number(options.page || page);
    const values = filters();
    const params = new URLSearchParams({ ajax_logs: '1', ...values, log_page: String(requestedPage), t: String(Date.now()) });
    loading = true;
    container.setAttribute('aria-busy', 'true');
    if (foreground) setStatus('Loading movements...');
    const finishLoading = window.KapeLoading?.start({ label: 'Updating movement history...', delay: foreground ? 350 : 900 });
    try {
      const response = await fetch('inventory.php?' + params, { cache: 'no-store', signal: activeController.signal });
      if (!response.ok) throw new Error('History request failed.');
      const html = await response.text();
      if (request !== requestNumber) return;
      const template = document.createElement('template');
      template.innerHTML = html;
      const nextView = template.content.querySelector('[data-log-view]');
      if (!nextView) throw new Error('History response was not a movement list.');
      // Capture immediately before replacement so scrolling during a request is preserved.
      const frame = container.querySelector('.inventory-log-table-frame');
      const left = frame?.scrollLeft || 0;
      const top = frame?.scrollTop || 0;
      const focusedFrame = frame && document.activeElement === frame;
      const focusedLogId = document.activeElement?.dataset?.logId;
      container.replaceChildren(template.content);
      page = Number(nextView.dataset.page || 1);
      const nextFrame = container.querySelector('.inventory-log-table-frame');
      if (nextFrame && !foreground) {
        nextFrame.scrollLeft = left;
        nextFrame.scrollTop = top;
        if (focusedFrame) nextFrame.focus({ preventScroll: true });
      }
      if (focusedLogId && !foreground) (container.querySelector('[data-log-id="' + focusedLogId + '"]') || nextFrame)?.focus({ preventScroll: true });
      if (options.page !== undefined) container.querySelector('[data-log-page-status]')?.focus({ preventScroll: true });
      if (options.updateUrl) updateAddress(values, page);
      const stamp = document.getElementById('logsUpdatedAt');
      if (stamp) stamp.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      setStatus('');
    } catch (error) {
      if (error.name !== 'AbortError' && request === requestNumber) setStatus('Could not refresh movement history. Showing the previous results.');
    } finally {
      finishLoading?.();
      if (request === requestNumber) {
        loading = false;
        container.setAttribute('aria-busy', 'false');
      }
    }
  };

  function applyFilters() {
    clearTimeout(searchTimer);
    searchTimer = null;
    const from = form.elements.namedItem('from');
    const to = form.elements.namedItem('to');
    if (from && to) {
      to.min = from.value;
      if (from.value && to.value && to.value < from.value) to.value = from.value;
    }
    return window.refreshLogs({ resetPage: true, updateUrl: true });
  }
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    applyFilters();
  });
  form.addEventListener('change', applyFilters);
  form.elements.namedItem('log_search')?.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
  });

  container.addEventListener('click', function (event) {
    const link = event.target.closest('[data-log-page]');
    if (link) {
      event.preventDefault();
      window.refreshLogs({ page: Number(link.dataset.logPage), updateUrl: true });
      return;
    }
    const button = event.target.closest('[data-log-details]');
    if (!button || !dialog) return;
    try {
      const details = JSON.parse(button.dataset.logDetails);
      dialog.querySelectorAll('[data-log-field]').forEach(field => {
        field.textContent = String(details[field.dataset.logField] ?? '-');
      });
      detailsOpener = button;
      if (!dialog.open) dialog.showModal();
    } catch (error) {
      setStatus('Details are unavailable. Please refresh movement history.');
    }
  });
  dialog?.querySelector('[data-close-log-details]')?.addEventListener('click', function () { dialog.close(); });
  dialog?.addEventListener('close', function () {
    const replacement = detailsOpener?.dataset.logId ? container.querySelector('[data-log-id="' + detailsOpener.dataset.logId + '"]') : null;
    (replacement || form.elements.namedItem('log_search'))?.focus({ preventScroll: true });
    detailsOpener = null;
  });
})();
