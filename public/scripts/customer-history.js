(function () {
  'use strict';
  const root = document.getElementById('customerHistoryWorkspace');
  if (!root) return;
  const search = document.getElementById('customer-search');
  const form = document.getElementById('customerHistoryFilters');
  const status = document.getElementById('customerHistoryUpdated');
  const tabInput = document.getElementById('customerHistoryTabInput');
  const tabs = Array.from(root.querySelectorAll('[data-history-tab]'));
  const lists = new Map(Array.from(root.querySelectorAll('[data-customer-list]')).map(list => [list.dataset.customerList, list]));
  const dialogs = ['customerHistoryDetails', 'customerOrderDetails'].map(id => document.getElementById(id));
  let state;
  try { state = JSON.parse(document.getElementById('customerHistoryState').textContent); } catch (error) { return; }
  let active = lists.has(root.dataset.activeTab) ? root.dataset.activeTab : 'customers';
  let appliedSearch = search.value.trim(), requestedSearch = appliedSearch;
  let pages = Object.fromEntries(Array.from(lists.keys()).map(key => [key, state.groups[key].page]));
  let requestVersion = 0, controller = null, inFlight = false, debounce = null, detailsOpener = null;

  function updateAddress() {
    const url = new URL(window.location.href);
    url.searchParams.delete('ajax_customer_history'); url.searchParams.delete('_');
    appliedSearch ? url.searchParams.set('search', appliedSearch) : url.searchParams.delete('search');
    active === 'customers' ? url.searchParams.delete('history_tab') : url.searchParams.set('history_tab', active);
    lists.forEach((list, key) => state.groups[key].page > 1 ? url.searchParams.set(list.dataset.pageParam, String(state.groups[key].page)) : url.searchParams.delete(list.dataset.pageParam));
    window.history.replaceState({}, '', url);
  }
  function activateTab(key, focus, address = true) {
    active = lists.has(key) ? key : 'customers';
    tabs.forEach(tab => {
      const selected = tab.dataset.historyTab === active;
      tab.classList.toggle('is-active', selected);
      tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1;
      document.getElementById(tab.getAttribute('aria-controls')).hidden = !selected;
      if (selected && focus) tab.focus({ preventScroll: true });
    });
    tabInput.value = active;
    if (address) updateAddress();
  }
  function render(data, replaceRows) {
    lists.forEach((list, key) => {
      const group = data.groups[key];
      const frame = list.querySelector('.management-table-frame');
      const body = list.querySelector('tbody');
      if (replaceRows && body.innerHTML !== group.html) {
        const top = frame.scrollTop, left = frame.scrollLeft;
        body.innerHTML = group.html;
        frame.scrollTop = top; frame.scrollLeft = left;
      }
      const first = group.total ? (group.page - 1) * 10 + 1 : 0;
      list.querySelector('[data-compact-range]').textContent = 'Showing ' + first + '\u2013' + Math.min(group.page * 10, group.total) + ' of ' + Number(group.total).toLocaleString('en-PH') + ' ' + list.dataset.listNoun;
      list.querySelector('[data-compact-page-label]').textContent = 'Page ' + group.page + ' of ' + group.pages;
      list.querySelector('[data-compact-previous]').disabled = group.page <= 1;
      list.querySelector('[data-compact-next]').disabled = group.page >= group.pages;
      list.querySelector('[data-compact-pagination]').hidden = false;
      list.dataset.currentPage = String(group.page);
    });
    root.querySelectorAll('[data-history-count]').forEach(node => { node.textContent = Number(data.groups[node.dataset.historyCount].total).toLocaleString('en-PH'); });
    document.getElementById('customerHistoryOrderTotal').textContent = Number(data.orderTotal).toLocaleString('en-PH');
  }
  function interactionInProgress() {
    return document.hidden || dialogs.some(dialog => dialog?.open) || Boolean(document.activeElement?.closest('[data-customer-list] tbody'));
  }
  function invalidate() {
    requestVersion++;
    if (controller) controller.abort();
    inFlight = false;
  }
  async function refresh(options = {}) {
    if (options.auto && (inFlight || debounce || interactionInProgress() || search.value.trim() !== appliedSearch)) return;
    invalidate();
    const version = requestVersion;
    const query = requestedSearch;
    controller = new AbortController();
    inFlight = true;
    const url = new URL(window.location.href);
    url.searchParams.set('ajax_customer_history', '1'); url.searchParams.set('search', query);
    lists.forEach((list, key) => url.searchParams.set(list.dataset.pageParam, String(pages[key])));
    url.searchParams.set('_', String(Date.now()));
    if (!options.auto) status.textContent = 'Loading history...';
    const finishLoading = window.KapeLoading?.start({ label: 'Updating customer history...', delay: options.auto ? 900 : 350 });
    try {
      const response = await fetch(url.toString(), { cache: 'no-store', signal: controller.signal });
      if (!response.ok) throw new Error('History refresh failed');
      const data = await response.json();
      if (version !== requestVersion || (options.auto && interactionInProgress())) return;
      if (!data.groups || Array.from(lists.keys()).some(key => !data.groups[key] || typeof data.groups[key].html !== 'string')) throw new Error('Incomplete history response');
      state = data; appliedSearch = query;
      pages = Object.fromEntries(Array.from(lists.keys()).map(key => [key, data.groups[key].page]));
      render(data, true); updateAddress();
      if (options.focusKey && active === options.focusKey) {
        const list = lists.get(options.focusKey), frame = list.querySelector('.management-table-frame');
        frame.scrollTop = 0; frame.scrollLeft = 0;
        list.querySelector('[data-compact-range]').focus({ preventScroll: true });
      } else if (options.reset) {
        lists.forEach(list => { const frame = list.querySelector('.management-table-frame'); frame.scrollTop = 0; frame.scrollLeft = 0; });
      }
      status.textContent = 'Live \u00b7 Updated ' + new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    } catch (error) {
      if (version === requestVersion && error.name !== 'AbortError') status.textContent = 'Could not update history. Previous results remain; use Search to retry.';
    } finally { finishLoading?.(); if (version === requestVersion) { inFlight = false; controller = null; } }
  }
  function applySearch() {
    window.clearTimeout(debounce); debounce = null;
    requestedSearch = search.value.trim().slice(0, 160);
    pages = Object.fromEntries(Array.from(lists.keys()).map(key => [key, 1]));
    refresh({ reset: true });
  }
  form.addEventListener('submit', event => { event.preventDefault(); applySearch(); });
  search.addEventListener('input', () => {
    invalidate(); window.clearTimeout(debounce);
    status.textContent = 'Search ready...';
    debounce = window.setTimeout(applySearch, 300);
  });
  search.addEventListener('keydown', event => { if (event.key === 'Escape' && search.value) { event.preventDefault(); search.value = ''; applySearch(); } });
  document.getElementById('customerHistoryReset').addEventListener('click', () => { search.value = ''; applySearch(); search.focus({ preventScroll: true }); });
  lists.forEach((list, key) => {
    [-1, 1].forEach(direction => {
      const button = list.querySelector(direction < 0 ? '[data-compact-previous]' : '[data-compact-next]');
      button.addEventListener('click', () => {
        if (button.disabled || inFlight || debounce) return;
        pages = Object.fromEntries(Array.from(lists.keys()).map(name => [name, state.groups[name].page]));
        pages[key] += direction; requestedSearch = appliedSearch;
        refresh({ focusKey: key });
      });
    });
  });
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activateTab(tab.dataset.historyTab, false));
    tab.addEventListener('keydown', event => {
      let next;
      if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
      else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = tabs.length - 1;
      else return;
      event.preventDefault(); activateTab(tabs[next].dataset.historyTab, true);
    });
  });
  root.addEventListener('click', event => {
    const button = event.target.closest('[data-compact-details]');
    if (!button) return;
    const dialog = document.getElementById(button.getAttribute('aria-controls'));
    if (!dialogs.includes(dialog)) return;
    try {
      const details = JSON.parse(button.dataset.compactDetails);
      dialog.querySelectorAll('[data-compact-field]').forEach(field => { field.textContent = String(details[field.dataset.compactField] ?? 'Not recorded'); });
      invalidate();
      pages = Object.fromEntries(Array.from(lists.keys()).map(key => [key, state.groups[key].page]));
      detailsOpener = button; if (!dialog.open) dialog.showModal();
      status.textContent = 'Live updates pause while details are open.';
    } catch (error) { status.textContent = 'Details unavailable. Reload this page and try again.'; }
  });
  dialogs.forEach(dialog => {
    dialog.querySelector('[data-compact-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
      (detailsOpener?.isConnected ? detailsOpener : search).focus({ preventScroll: true }); detailsOpener = null;
      status.textContent = search.value.trim() === appliedSearch ? 'Auto-updates every 5 seconds' : 'Use Search to apply your filter.';
    });
  });
  window.addEventListener('popstate', () => {
    const url = new URL(window.location.href);
    search.value = (url.searchParams.get('search') || '').slice(0, 160); requestedSearch = search.value.trim();
    window.clearTimeout(debounce); debounce = null;
    lists.forEach((list, key) => { const page = url.searchParams.get(list.dataset.pageParam) || '1'; pages[key] = /^\d{1,7}$/.test(page) ? Math.max(1, Math.min(1000000, Number(page))) : 1; });
    activateTab(url.searchParams.get('history_tab'), false, false); refresh({ reset: true });
  });
  render(state, false); activateTab(active, false);
  window.setInterval(() => refresh({ auto: true }), 5000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh({ auto: true }); });
})();
