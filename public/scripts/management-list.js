(function () {
  'use strict';
  const normalize = value => String(value || '').toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  document.querySelectorAll('[data-compact-list]').forEach(function (root) {
    const form = root.querySelector('[data-compact-filters]');
    const search = root.querySelector('[data-compact-search]');
    const filters = Array.from(root.querySelectorAll('[data-compact-filter]'));
    const rows = Array.from(root.querySelectorAll('[data-compact-row]'));
    const empty = root.querySelector('[data-compact-empty]');
    const pager = root.querySelector('[data-compact-pagination]');
    const range = root.querySelector('[data-compact-range]');
    const pageLabel = root.querySelector('[data-compact-page-label]');
    const previous = root.querySelector('[data-compact-previous]');
    const next = root.querySelector('[data-compact-next]');
    const dialog = document.getElementById(root.dataset.detailsDialog);
    if (!form || !search || !pager || !range || !previous || !next) return;
    const controls = [search, ...filters];
    const sizeOptions = new Map(filters.map(control => [control, Array.from(control.options).map(option => ({ value: option.value, label: option.textContent }))]));
    const searchableRows = new Map(rows.map(row => [row, normalize(row.dataset.search)]));
    const noun = root.dataset.listNoun || 'records';
    let page = 1, detailsOpener = null;

    function dependentOptions() {
      filters.filter(control => control.dataset.compactDependent).forEach(control => {
        const parent = filters.find(candidate => candidate.dataset.compactFilter === control.dataset.compactDependent);
        const selected = control.value;
        const choices = new Set(rows.filter(row => !parent?.value || row.dataset[parent.dataset.compactFilter] === parent.value).map(row => row.dataset[control.dataset.compactFilter]));
        const options = sizeOptions.get(control).filter(option => !option.value || choices.has(option.value));
        control.replaceChildren(...options.map(option => new Option(option.label, option.value)));
        control.value = options.some(option => option.value === selected) ? selected : '';
      });
    }
    function matches() {
      const terms = normalize(search.value).trim().split(/\s+/).filter(Boolean);
      return rows.filter(row => terms.every(term => searchableRows.get(row).includes(term)) && filters.every(control => !control.value || row.dataset[control.dataset.compactFilter] === control.value));
    }
    function updateAddress() {
      const url = new URL(window.location.href);
      controls.forEach(control => control.value ? url.searchParams.set(control.name, control.value) : url.searchParams.delete(control.name));
      if (page > 1) url.searchParams.set(root.dataset.pageParam, String(page));
      else url.searchParams.delete(root.dataset.pageParam);
      window.history.replaceState({}, '', url);
    }
    function render(options = {}) {
      const selected = matches(), pages = Math.max(1, Math.ceil(selected.length / 10));
      page = options.reset ? 1 : Math.min(pages, Math.max(1, page));
      const visible = new Set(selected.slice((page - 1) * 10, page * 10));
      rows.forEach(row => { row.hidden = !visible.has(row); });
      if (empty) empty.hidden = selected.length > 0;
      const first = selected.length ? (page - 1) * 10 + 1 : 0;
      const last = Math.min(page * 10, selected.length);
      range.textContent = 'Showing ' + first + '\u2013' + last + ' of ' + selected.length.toLocaleString('en-PH') + ' ' + noun;
      if (pageLabel) pageLabel.textContent = 'Page ' + page + ' of ' + pages;
      const count = root.querySelector('[data-compact-count]');
      if (count) count.textContent = selected.length.toLocaleString('en-PH') + (selected.length === 1 ? ' record' : ' records');
      previous.disabled = page === 1; next.disabled = page === pages;
      pager.hidden = false;
      root.dataset.currentPage = String(page);
      if (options.address) updateAddress();
      if (options.reset || options.focus) {
        const frame = root.querySelector('.management-table-frame');
        if (frame) { frame.scrollTop = 0; frame.scrollLeft = 0; }
      }
      if (options.focus) range.focus({ preventScroll: true });
    }
    function restore(address) {
      const url = new URL(window.location.href);
      controls.forEach(control => {
        const value = (url.searchParams.get(control.name) || '').slice(0, 160);
        control.value = control === search || sizeOptions.get(control).some(option => option.value === value) ? value : '';
      });
      dependentOptions();
      const requested = url.searchParams.get(root.dataset.pageParam) || '1';
      page = /^\d+$/.test(requested) ? Math.max(1, Math.min(1000000, Number(requested))) : 1;
      // Validation redirects can reopen an editor; keep its row on the visible page.
      if (!url.searchParams.has(root.dataset.pageParam) && url.searchParams.has('edit')) {
        const index = matches().findIndex(row => row.dataset.rowId === url.searchParams.get('edit'));
        if (index >= 0) page = Math.floor(index / 10) + 1;
      }
      render({ address });
    }
    function apply() { dependentOptions(); render({ reset: true, address: true }); }
    form.addEventListener('submit', event => { event.preventDefault(); apply(); });
    search.addEventListener('input', apply);
    filters.forEach(control => control.addEventListener('change', apply));
    search.addEventListener('keydown', event => {
      if (event.key === 'Escape' && search.value) { event.preventDefault(); search.value = ''; apply(); }
    });
    root.querySelector('[data-compact-reset]')?.addEventListener('click', function () {
      controls.forEach(control => { control.value = ''; }); apply(); search.focus({ preventScroll: true });
    });
    previous.addEventListener('click', function () { if (!previous.disabled) { page--; render({ address: true, focus: true }); } });
    next.addEventListener('click', function () { if (!next.disabled) { page++; render({ address: true, focus: true }); } });
    root.addEventListener('click', function (event) {
      const button = event.target.closest('[data-compact-details]');
      if (!button || !dialog) return;
      try {
        const details = JSON.parse(button.dataset.compactDetails);
        dialog.querySelectorAll('[data-compact-field]').forEach(field => { field.textContent = String(details[field.dataset.compactField] ?? 'Not recorded'); });
        detailsOpener = button;
        if (!dialog.open) dialog.showModal();
      } catch (error) { range.textContent = 'Details are unavailable. Reload this page and try again.'; }
    });
    dialog?.querySelector('[data-compact-close]')?.addEventListener('click', function () { dialog.close(); });
    dialog?.addEventListener('close', function () { (detailsOpener || search).focus({ preventScroll: true }); detailsOpener = null; });
    window.addEventListener('popstate', function () { restore(false); });
    restore(true);
  });
})();
