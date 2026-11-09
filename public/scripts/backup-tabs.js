(function () {
  'use strict';
  const root = document.querySelector('[data-backup-tabs]');
  if (!root) return;
  const tabs = Array.from(root.querySelectorAll('[data-backup-tab]'));
  const panels = Array.from(root.querySelectorAll('[role="tabpanel"]'));
  function activate(name, updateUrl) {
    const selected = tabs.find(tab => tab.dataset.backupTab === name) || tabs[0];
    tabs.forEach(tab => {
      tab.setAttribute('aria-selected', String(tab === selected));
      tab.tabIndex = tab === selected ? 0 : -1;
    });
    panels.forEach(panel => { panel.hidden = panel.id !== selected.getAttribute('aria-controls'); });
    if (updateUrl) {
      const url = new URL(location.href);
      url.searchParams.set('tab', selected.dataset.backupTab);
      history.replaceState({}, '', url);
    }
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', event => { event.preventDefault(); activate(tab.dataset.backupTab, true); });
    tab.addEventListener('keydown', event => {
      let next;
      if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
      else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = tabs.length - 1;
      else return;
      event.preventDefault(); tabs[next].focus(); activate(tabs[next].dataset.backupTab, true);
    });
  });
})();
