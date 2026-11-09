(function () {
  'use strict';
  if (window.KapeLoading) return;
  const script = document.currentScript;
  const logoUrl = script?.dataset.logo || '';
  const iconUrl = script?.dataset.icon || logoUrl;
  const jobs = new Set();
  let indicator, image, fallback, message, fadeTimer, initialFinish, navigationFinish;
  const excluded = () => /(?:^|\/)receipt_template\.php$/i.test(window.location.pathname) || document.body?.matches('.kape-orders-receipt-template, [data-kape-loading="off"]');

  function mount() {
    if (indicator || !document.body || excluded()) return;
    indicator = document.createElement('div');
    indicator.id = 'kapeLoadingIndicator';
    indicator.hidden = true;
    indicator.setAttribute('role', 'status');
    indicator.setAttribute('aria-live', 'polite');
    indicator.setAttribute('aria-atomic', 'true');
    indicator.setAttribute('aria-hidden', 'true');
    const card = document.createElement('div');
    card.className = 'kape-loading-card';
    image = document.createElement('img');
    image.className = 'kape-loading-logo';
    image.alt = '';
    image.setAttribute('aria-hidden', 'true');
    image.src = logoUrl;
    fallback = document.createElement('strong');
    fallback.className = 'kape-loading-brand';
    fallback.textContent = 'Kape Agosto';
    fallback.hidden = Boolean(logoUrl);
    image.hidden = !logoUrl;
    image.addEventListener('error', () => { image.hidden = true; fallback.hidden = false; });
    message = document.createElement('span');
    message.className = 'kape-loading-message';
    const progress = document.createElement('span');
    progress.className = 'kape-loading-progress';
    progress.setAttribute('aria-hidden', 'true');
    card.append(image, fallback, message, progress);
    indicator.append(card);
    document.body.append(indicator);
  }

  function render() {
    mount();
    if (!indicator) return;
    const ready = Array.from(jobs).filter(job => job.ready);
    const job = ready.find(job => job.mode === 'page') || ready[ready.length - 1];
    window.clearTimeout(fadeTimer);
    if (!job || document.hidden || excluded()) {
      indicator.classList.remove('is-visible');
      indicator.setAttribute('aria-hidden', 'true');
      fadeTimer = window.setTimeout(() => { indicator.hidden = true; }, 180);
      return;
    }
    const source = job.mode === 'page' ? logoUrl : iconUrl;
    if (image.getAttribute('src') !== source) { image.hidden = !source; fallback.hidden = Boolean(source); image.src = source; }
    indicator.dataset.mode = job.mode;
    if (message.textContent !== job.label) message.textContent = job.label;
    indicator.hidden = false;
    indicator.setAttribute('aria-hidden', 'false');
    indicator.classList.add('is-visible');
  }

  // Each request owns its own completion callback, including aborted/stale requests.
  function start(options = {}) {
    if (excluded()) return () => {};
    const mode = options.mode === 'page' ? 'page' : 'background';
    const job = { mode, label: String(options.label || (mode === 'page' ? 'Loading workspace...' : 'Updating...')), ready: false };
    const delay = Number.isFinite(options.delay) ? Math.max(0, Math.min(options.delay, 2000)) : (mode === 'page' ? 350 : 900);
    jobs.add(job);
    let finished = false;
    const finish = () => {
      if (finished) return;
      finished = true;
      window.clearTimeout(job.timer);
      window.clearTimeout(job.expiry);
      jobs.delete(job);
      if (navigationFinish === finish) navigationFinish = null;
      if (initialFinish === finish) initialFinish = null;
      render();
    };
    job.finish = finish;
    job.timer = window.setTimeout(() => { job.ready = true; render(); }, delay);
    // Never leave a permanent visual mask if a browser request or extension stalls.
    job.expiry = window.setTimeout(finish, 20000);
    return finish;
  }

  function navigate(label) {
    if (!navigationFinish) {
      initialFinish?.();
      navigationFinish = start({ mode: 'page', label: label || 'Loading workspace...' });
    }
    return navigationFinish;
  }
  function cancelNavigation() { navigationFinish?.(); navigationFinish = null; }
  function reset() {
    Array.from(jobs).forEach(job => job.finish());
    initialFinish = navigationFinish = null;
    window.clearTimeout(fadeTimer);
    if (indicator) { indicator.hidden = true; indicator.classList.remove('is-visible'); indicator.setAttribute('aria-hidden', 'true'); }
  }
  window.KapeLoading = Object.freeze({ start, navigate, reset });
  if (excluded()) return;

  if (document.readyState !== 'complete') initialFinish = start({ mode: 'page', label: 'Loading workspace...', delay: 450 });
  document.addEventListener('DOMContentLoaded', () => {
    render();
    // A stalled third-party asset must not conceal an already usable page.
    window.setTimeout(() => initialFinish?.(), 6000);
  }, { once: true });
  window.addEventListener('load', () => { initialFinish?.(); initialFinish = null; }, { once: true });
  // Only actual page departures trigger this; local tabs, modals and validation do not.
  window.addEventListener('beforeunload', event => {
    navigate();
    window.setTimeout(() => { if (event.defaultPrevented || event.returnValue) cancelNavigation(); }, 0);
  });
  window.addEventListener('pagehide', reset);
  window.addEventListener('pageshow', event => { if (event.persisted) reset(); });
  window.addEventListener('focus', () => { if (!document.hidden) cancelNavigation(); });
  document.addEventListener('visibilitychange', render);
  window.addEventListener('beforeprint', reset);
})();
