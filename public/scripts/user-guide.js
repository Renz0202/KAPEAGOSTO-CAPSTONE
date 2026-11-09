(() => {
  'use strict';
  const bootstrap = document.getElementById('kapeGuideData');
  const dialog = document.getElementById('kapeGuide');
  if (!bootstrap || !dialog || dialog.dataset.initialized) return;
  let data;
  try { data = JSON.parse(bootstrap.textContent); } catch (_) { return; }
  if (!Array.isArray(data.topics) || !data.topics.length) return;
  dialog.dataset.initialized = 'true';
  const get = id => document.getElementById(id);
  const article = get('kgArticle'), topicsNav = get('kgTopics'), search = get('kgSearch');
  const workspace = get('kgWorkspace'), welcome = get('kgWelcome'), status = get('kgSaveStatus');
  const bar = get('kgInspectBar'), help = get('kgControlHelp');
  const pendingKey = 'kape-guide-pending-' + data.userId;
  const pageTopic = data.topics.find(topic => topic.id === data.currentTopic) || data.topics[0];
  let current = pageTopic;
  let welcomeStep = 0, welcomeOpen = false, needsWelcome = !!data.needsWelcome;
  let inspecting = false, highlighted = null, highlightTimer = null, opener = null, saving = false;
  const topicButtons = new Map();
  let pendingMemory = null;
  const storage = {
    get() { try { return sessionStorage.getItem(pendingKey) || pendingMemory; } catch (_) { return pendingMemory; } },
    set(value) { pendingMemory = value; try { sessionStorage.setItem(pendingKey, value); } catch (_) { /* Help remains available. */ } },
    clear() { pendingMemory = null; try { sessionStorage.removeItem(pendingKey); } catch (_) { /* Optional browser storage. */ } },
  };
  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const visible = node => node && !node.closest('[hidden]') && node.getClientRects().length > 0;
  const targets = selector => {
    try { return Array.from(document.querySelectorAll(selector)).filter(node => !dialog.contains(node) && visible(node)); }
    catch (_) { return []; }
  };
  function clearHighlight() {
    if (highlighted) highlighted.classList.remove('kg-control-highlight');
    highlighted = null;
    window.clearTimeout(highlightTimer);
  }
  function highlight(node) {
    clearHighlight();
    highlighted = node;
    node.classList.add('kg-control-highlight');
  }
  function stopInspect() {
    inspecting = false;
    bar.hidden = true;
    help.hidden = true;
    document.body.classList.remove('kg-inspecting');
    clearHighlight();
  }
  function guideReturnTarget(button) {
    const menu = button?.closest('[role="menu"]');
    return menu ? document.querySelector('[aria-controls="' + menu.id + '"]') || button : button;
  }
  function closeGuide() {
    if (welcomeOpen && needsWelcome) finishWelcome('skipped', false);
    welcomeOpen = false;
    dialog.close();
    if (opener && opener.isConnected) opener.focus();
  }
  function showGuide() {
    stopInspect();
    if (!dialog.open) opener = guideReturnTarget(document.activeElement);
    welcomeOpen = false;
    welcome.hidden = true;
    workspace.hidden = false;
    get('kgExplain').hidden = false;
    get('kapeGuideTitle').textContent = data.roleLabel + ' Guide';
    renderTopic(current);
    if (!dialog.open) dialog.showModal();
    search.focus();
  }
  function findControl(control) {
    const node = targets(control.selector)[0];
    if (!node) {
      status.textContent = 'This control is not visible. Open its tab or section, then try again.';
      return;
    }
    closeGuide();
    node.scrollIntoView({ block: 'center', behavior: 'auto' });
    node.focus({ preventScroll: true });
    highlight(node);
    highlightTimer = window.setTimeout(clearHighlight, 5000);
  }
  function renderTopic(topic, focus = false) {
    current = topic;
    article.replaceChildren();
    article.append(element('span', 'kg-badge', data.roleLabel + ' guide'));
    const title = element('h3', '', topic.title); title.id = 'kgTopicTitle';
    article.append(title, element('p', 'kg-intro', topic.intro));
    if (topic.access) {
      const access = element('p', 'kg-access-note');
      access.append(element('strong', '', 'Your access: '), element('span', '', topic.access));
      article.append(access);
    }
    article.append(element('h4', 'kg-section-title', topic.id === 'role' ? 'Your usual workflow' : 'How to use this page'));
    const steps = element('ol', 'kg-steps');
    topic.steps.forEach(text => steps.append(element('li', '', text)));
    article.append(steps, element('h4', 'kg-section-title', topic.id === 'role' ? 'Your responsibilities' : 'Tabs & actions'));
    const cards = element('div', 'kg-card-grid');
    topic.sections.forEach(([label, text]) => {
      const card = element('section', 'kg-card');
      card.append(element('h5', '', label), element('p', '', text));
      cards.append(card);
    });
    article.append(cards);
    const available = topic.id === pageTopic.id ? topic.controls.filter(control => targets(control.selector).length) : [];
    if (available.length) {
      article.append(element('h4', 'kg-section-title', 'Find a control on this page'));
      const buttons = element('div', 'kg-find');
      available.forEach(control => {
        const button = element('button', '', control.label); button.type = 'button';
        button.addEventListener('click', () => findControl(control));
        buttons.append(button);
      });
      article.append(buttons);
    }
    if (topic.url) {
      const link = element('a', 'kg-page-link', 'Open ' + topic.title + ' \u2192');
      link.href = topic.url;
      article.append(link);
    }
    topicButtons.forEach((button, id) => button.setAttribute('aria-current', String(id === topic.id)));
    article.scrollTop = 0;
    if (focus) article.focus();
  }
  data.topics.forEach(topic => {
    const button = element('button', 'kg-topic', topic.title);
    button.type = 'button';
    button.addEventListener('click', () => renderTopic(topic, true));
    topicsNav.append(button);
    topicButtons.set(topic.id, button);
  });
  search.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase();
    let found = 0;
    data.topics.forEach(topic => {
      const match = [topic.title, topic.intro, topic.access || '', ...topic.steps, ...topic.sections.flat()].join(' ').toLocaleLowerCase().includes(query);
      topicButtons.get(topic.id).hidden = !match;
      if (match) found++;
    });
    get('kgNoResults').hidden = found > 0;
  });
  function renderWelcome() {
    welcomeOpen = true;
    welcome.hidden = false;
    workspace.hidden = true;
    get('kgExplain').hidden = true;
    get('kapeGuideTitle').textContent = 'Getting started';
    const slide = data.welcome[welcomeStep];
    get('kgWelcomeTitle').textContent = slide[0];
    get('kgWelcomeText').textContent = slide[1];
    get('kgWelcomeCount').textContent = 'Step ' + (welcomeStep + 1) + ' of ' + data.welcome.length;
    get('kgWelcomeBack').hidden = welcomeStep === 0;
    get('kgWelcomeNext').textContent = welcomeStep === data.welcome.length - 1 ? 'Explore the guide' : 'Next';
    get('kgWelcomeDots').replaceChildren(...data.welcome.map((_, index) => element('span', index === welcomeStep ? 'is-current' : '')));
    if (!dialog.open) dialog.showModal();
    get('kgWelcomeNext').focus();
  }
  async function savePreference(value) {
    storage.set(value);
    if (saving) return;
    saving = true;
    status.textContent = 'Saving your guide preference...';
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(data.stateUrl, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ csrf: data.csrf, status: value }), signal: controller.signal,
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || 'Save failed');
      if (storage.get() === value) storage.clear();
      status.textContent = 'You can reopen this guide at any time.';
    } catch (_) {
      status.textContent = 'Guide is ready. Your preference will save when the connection returns.';
    } finally {
      window.clearTimeout(timeout);
      saving = false;
      const next = storage.get();
      if (next && next !== value) savePreference(next);
    }
  }
  function finishWelcome(value, openTopics = true) {
    if (needsWelcome) {
      needsWelcome = false;
      savePreference(value);
    }
    welcomeOpen = false;
    if (openTopics) showGuide();
  }
  get('kgReplay').addEventListener('click', () => { welcomeStep = 0; renderWelcome(); });
  get('kgWelcomeNext').addEventListener('click', () => {
    if (welcomeStep < data.welcome.length - 1) { welcomeStep++; renderWelcome(); }
    else finishWelcome('completed');
  });
  get('kgWelcomeBack').addEventListener('click', () => { welcomeStep = Math.max(0, welcomeStep - 1); renderWelcome(); });
  get('kgWelcomeSkip').addEventListener('click', () => finishWelcome('skipped'));
  dialog.querySelector('[data-guide-close]').addEventListener('click', closeGuide);
  dialog.addEventListener('cancel', event => { event.preventDefault(); closeGuide(); });
  document.querySelectorAll('[data-open-guide]').forEach(button => button.addEventListener('click', showGuide));
  get('kgExplain').addEventListener('click', () => {
    closeGuide();
    inspecting = true;
    bar.hidden = false;
    document.body.classList.add('kg-inspecting');
    get('kgInspectExit').focus();
  });
  get('kgInspectExit').addEventListener('click', () => {
    stopInspect();
    guideReturnTarget(document.querySelector('[data-open-guide]'))?.focus();
  });
  const controlSelector = 'a, button, input:not([type="hidden"]), select, textarea, [role="button"], [role="tab"], [tabindex="0"]';
  function labelFor(node) {
    return (node.getAttribute('aria-label') || node.getAttribute('title') ||
      node.labels?.[0]?.textContent || node.getAttribute('placeholder') || node.textContent || node.getAttribute('name') || 'Control')
      .replace(/\s+/g, ' ').trim().slice(0, 110);
  }
  function describe(node) {
    for (const control of pageTopic.controls) {
      try { if (node.matches(control.selector) || node.closest(control.selector)) return control; } catch (_) { /* Ignore stale selectors. */ }
    }
    const label = labelFor(node), text = label.toLocaleLowerCase();
    const rules = [
      [/restock/, 'Records materials received and increases the selected material’s stock. Check the quantity and unit before saving.'],
      [/deduct/, 'Records usage or loss and reduces the selected material’s stock. Check the quantity and provide a clear reason.'],
      [/mark.*done|complete order/, 'Completes a fully paid order and records its recipe ingredients and packaging usage once.'],
      [/void|cancel order/, 'Requests cancellation of the selected order. Review the details, approval requirements, and stock effects before confirming.'],
      [/restore.*backup/, 'Replaces the current database with the selected backup. Review the file and backup date before proceeding.'],
      [/create.*backup/, 'Creates a backup copy of the current database.'],
      [/deactivate/, 'Pauses this account’s access to the system.'],
      [/activate/, 'Restores access for this account.'],
      [/delete|remove/, 'Removes the selected record or entry after any required confirmation. Review the selected item first.'],
      [/edit/, 'Opens the selected record for editing. Saving applies your changes.'],
      [/view|details/, 'Opens more information about the selected record.'],
      [/print|receipt/, 'Opens or prints the receipt for the selected order.'],
      [/export|download/, 'Downloads the selected report or file. Check the date or selected record.'],
      [/generate/, 'Creates or updates the selected report. Check the date or date range first.'],
      [/reset|clear filter/, 'Returns the filters to their default values.'],
      [/filter|search|apply/, 'Narrows the displayed records using the selected filters.'],
      [/next|previous|first page|last page/, 'Moves through the pages of records.'],
      [/notification/, 'Opens your alerts. Read the message and follow its link to review the related record.'],
      [/profile|account/, 'Opens your account options or profile details.'],
      [/logout|log out|sign out/, 'Ends your current signed-in session.'],
      [/sidebar|navigation|menu toggle|toggle menu/, 'Shows or hides navigation so you can switch between available pages.'],
      [/realtime|real.time|live/, 'Controls automatic refresh of the displayed records.'],
      [/save|update|submit|confirm/, 'Saves or confirms the values in this form. Review the entered details before continuing.'],
      [/add|create|new /, 'Opens or adds a new entry in this section. Complete and review its details before saving.'],
    ];
    if (node.tagName === 'A') {
      const path = (node.getAttribute('href') || '').split('?')[0].split('/').pop();
      const topic = data.topics.find(topic => topic.page === path || topic.aliases.includes(path));
      if (topic) return { label: topic.title, text: topic.intro };
    }
    if (node.getAttribute('role') === 'tab') return { label, text: 'Shows the ' + label + ' section. Open Guide for this page’s steps and details.' };
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(node.tagName)) {
      return { label, text: node.tagName === 'SELECT'
        ? 'Choose the ' + label.toLocaleLowerCase() + ' value needed for this form or filter.'
        : 'Enter or review the ' + label.toLocaleLowerCase() + ' value. Check the format and unit shown with the field.' };
    }
    const rule = rules.find(([pattern]) => pattern.test(text));
    return { label, text: rule ? rule[1] : 'Use this control in the ' + pageTopic.title + ' page. Open Guide for the page’s instructions and available actions.' };
  }
  function explain(node) {
    const description = describe(node);
    get('kgControlTitle').textContent = description.label;
    get('kgControlText').textContent = description.text;
    help.hidden = false;
    highlight(node);
  }
  // Capture at window before existing document confirmation and target handlers.
  window.addEventListener('click', event => {
    if (!inspecting || bar.contains(event.target)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const node = event.target.closest(controlSelector);
    if (node) explain(node);
  }, true);
  window.addEventListener('keydown', event => {
    // Keep page-level shortcuts (including checkout) out of the help dialog.
    if (dialog.open) {
      event.stopImmediatePropagation();
      if (event.key === 'F8') event.preventDefault();
      return;
    }
    if (!inspecting) return;
    if (event.key === 'Escape') {
      event.preventDefault(); event.stopImmediatePropagation(); stopInspect();
      guideReturnTarget(document.querySelector('[data-open-guide]'))?.focus();
      return;
    }
    if (bar.contains(event.target) || event.key === 'Tab') return;
    const node = event.target.closest(controlSelector);
    event.preventDefault(); event.stopImmediatePropagation();
    if (node) explain(node);
  }, true);
  ['submit', 'beforeinput', 'change', 'drop', 'keypress', 'keyup'].forEach(type => window.addEventListener(type, event => {
    if (dialog.open) {
      if (type === 'submit') event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    if (!inspecting || bar.contains(event.target)) return;
    event.preventDefault(); event.stopImmediatePropagation();
  }, true));
  window.addEventListener('online', () => { const pending = storage.get(); if (pending) savePreference(pending); });
  renderTopic(current);
  const pending = storage.get();
  if (pending === 'completed' || pending === 'skipped') {
    needsWelcome = false;
    savePreference(pending);
  }
  if (needsWelcome) {
    opener = guideReturnTarget(document.querySelector('[data-open-guide]'));
    welcomeStep = 0;
    renderWelcome();
  }
})();
