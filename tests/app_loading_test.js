'use strict';
// Exercise the actual controller with fast, stalled, concurrent and cancelled lifecycles.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('public/scripts/app-loading.js', 'utf8');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS: ' + label); }
class Element {
  constructor(tag = 'div') {
    this.tagName = tag; this.children = []; this.events = {}; this.attrs = {}; this.dataset = {}; this.hidden = false; this.textContent = '';
    this.classes = new Set();
    this.classList = { add: (...items) => items.forEach(item => this.classes.add(item)), remove: (...items) => items.forEach(item => this.classes.delete(item)), contains: item => this.classes.has(item) };
  }
  setAttribute(name, value) { this.attrs[name] = String(value); }
  getAttribute(name) { return this.attrs[name] ?? null; }
  set src(value) { this.setAttribute('src', value); } get src() { return this.getAttribute('src'); }
  append(...items) { this.children.push(...items); }
  matches() { return Boolean(this.excluded); }
  addEventListener(name, listener) { (this.events[name] ||= []).push(listener); }
  fire(name, event = {}) { for (const listener of this.events[name] || []) listener(event); }
}
function fixture({ complete = false, receipt = false, optOut = false, noBody = false } = {}) {
  let now = 0, nextTimer = 0;
  const timers = new Map(), body = new Element('body'); body.excluded = optOut;
  const document = new Element('document');
  document.body = noBody ? null : body; document.readyState = complete ? 'complete' : 'loading'; document.hidden = false;
  document.currentScript = { dataset: { logo: '/KAPEAGOSTO/public/img/NEWLOGO_WHITE.png', icon: '/KAPEAGOSTO/public/img/CUP_WHITE.png' } };
  document.createElement = tag => new Element(tag);
  document.activeElement = new Element('input');
  const window = new Element('window');
  window.location = { pathname: receipt ? '/KAPEAGOSTO/public/orders/receipt_template.php' : '/KAPEAGOSTO/public/dashboard.php' };
  window.setTimeout = (callback, delay = 0) => { const id = ++nextTimer; timers.set(id, { callback, due: now + delay }); return id; };
  window.clearTimeout = id => timers.delete(id);
  const context = vm.createContext({ window, document }); vm.runInContext(source, context);
  return {
    window, document, body, api: window.KapeLoading,
    runAgain() { vm.runInContext(source, context); },
    get root() { return body.children.find(child => child.id === 'kapeLoadingIndicator'); },
    get visible() { return Boolean(this.root && !this.root.hidden && this.root.classList.contains('is-visible')); },
    get message() { return this.root?.children[0].children[2].textContent; },
    get image() { return this.root?.children[0].children[0]; },
    advance(ms) {
      const until = now + ms;
      for (;;) {
        const next = [...timers].filter(([, timer]) => timer.due <= until).sort((a, b) => a[1].due - b[1].due || a[0] - b[0])[0];
        if (!next) break;
        timers.delete(next[0]); now = next[1].due; next[1].callback();
      }
      now = until;
    }
  };
}
const fast = fixture(); fast.advance(100); fast.window.fire('load'); fast.advance(1000);
check(!fast.visible && fast.root.hidden, 'A fast page load never flashes a loading screen');
const slow = fixture(); slow.advance(460);
check(slow.visible && slow.root.dataset.mode === 'page' && slow.image.src.endsWith('NEWLOGO_WHITE.png'), 'A delayed page displays the existing full Kape Agosto logo');
check(slow.root.getAttribute('role') === 'status' && slow.root.getAttribute('aria-live') === 'polite' && slow.root.getAttribute('aria-hidden') === 'false', 'Loading is announced without moving keyboard focus');
slow.window.fire('load'); slow.advance(200);
check(!slow.visible && slow.root.hidden && slow.root.getAttribute('aria-hidden') === 'true', 'Completed loading removes the visual and accessible status');
const parsing = fixture({ noBody: true }); parsing.advance(500); parsing.document.body = parsing.body; parsing.document.fire('DOMContentLoaded');
check(parsing.visible, 'A slow HTML parse mounts safely when the body becomes available');
parsing.advance(6100); check(!parsing.visible, 'A stalled external asset does not keep an already usable page covered');
const quick = fixture({ complete: true }); const quickFinish = quick.api.start(); quick.advance(100); quickFinish(); quick.advance(1100);
check(!quick.visible, 'Fast background updates remain silent');
const background = fixture({ complete: true }); const finishBackground = background.api.start({ label: 'Updating inventory...' }); background.advance(950);
check(background.visible && background.root.dataset.mode === 'background' && background.image.src.endsWith('CUP_WHITE.png') && background.message === 'Updating inventory...', 'Slow background updates use a compact branded indicator');
const focus = background.document.activeElement; finishBackground(); finishBackground(); background.advance(200);
check(!background.visible && background.document.activeElement === focus, 'Completing a request is idempotent and preserves keyboard focus');
const overlap = fixture({ complete: true }); const finishOld = overlap.api.start({ label: 'Old search', delay: 350 }); overlap.advance(400);
const finishNew = overlap.api.start({ label: 'New search', delay: 350 }); overlap.advance(400); finishOld();
check(overlap.visible && overlap.message === 'New search', 'An aborted or stale request cannot hide the newer pending request');
finishNew(); overlap.advance(200); check(!overlap.visible, 'The indicator clears after the final concurrent request settles');
const priority = fixture({ complete: true }); const finishPoll = priority.api.start(); priority.advance(950); const finishNavigation = priority.api.navigate('Refreshing workspace...'); priority.advance(400);
check(priority.root.dataset.mode === 'page' && priority.message === 'Refreshing workspace...', 'Page reload feedback takes priority over background polling');
finishNavigation(); check(priority.visible && priority.root.dataset.mode === 'background', 'Ending navigation leaves any pending background update visible');
finishPoll(); priority.advance(200);
check(!priority.visible, 'Settled reload and background jobs leave no overlay');
const cancelled = fixture({ complete: true }); cancelled.window.fire('beforeunload', { defaultPrevented: true, returnValue: '' }); cancelled.advance(600);
check(!cancelled.visible, 'Cancelled page departures do not leave a loader');
cancelled.window.fire('beforeunload', { defaultPrevented: false, returnValue: '' }); cancelled.advance(400);
check(cancelled.visible, 'Actual navigation or browser reload displays delayed page feedback');
cancelled.window.fire('focus'); cancelled.advance(200); check(!cancelled.visible, 'Returning focus after a cancelled browser prompt releases navigation feedback');
const stalled = fixture({ complete: true }); stalled.api.navigate(); stalled.advance(20500);
check(!stalled.visible && stalled.root.hidden, 'A stalled navigation cannot leave a permanent loading screen');
stalled.api.navigate(); stalled.advance(400); check(stalled.visible, 'A retry after a timeout can display loading feedback again');
const cached = fixture({ complete: true }); const cachedFinish = cached.api.start({ delay: 0 }); cached.advance(0); cached.window.fire('pagehide');
check(!cached.visible && cached.root.hidden, 'Leaving a page clears feedback before browser back/forward caching');
cached.window.fire('pageshow', { persisted: true }); cachedFinish(); cached.advance(1000);
check(!cached.visible, 'Back/forward restoration does not revive an old loading screen');
const print = fixture({ complete: true }); print.api.start({ delay: 0 }); print.advance(0); print.window.fire('beforeprint');
check(!print.visible && print.root.hidden, 'Printing clears any pending loading indicator');
const hidden = fixture({ complete: true }); const hiddenFinish = hidden.api.start({ delay: 0 }); hidden.advance(0); hidden.document.hidden = true; hidden.document.fire('visibilitychange'); hidden.advance(200);
check(!hidden.visible, 'Hidden browser tabs do not show background loading feedback');
hidden.document.hidden = false; hidden.document.fire('visibilitychange'); check(hidden.visible, 'A genuinely pending update is shown again when the tab becomes visible'); hiddenFinish();
const receipt = fixture({ receipt: true }); receipt.api.start({ delay: 0 }); receipt.window.fire('beforeunload', {}); receipt.advance(21000);
check(!receipt.root && Object.keys(receipt.window.events).length === 0, 'Receipt pages remain free of loading overlays and navigation hooks');
const opted = fixture({ optOut: true }); opted.api.start({ delay: 0 }); opted.advance(1000); check(!opted.root, 'An explicitly excluded page can opt out of branded feedback');
const local = fixture({ complete: true }); local.document.fire('click'); local.document.fire('submit'); local.advance(1500);
check(!local.visible && !local.document.events.click && !local.document.events.submit, 'Local tabs, modal clicks and invalid forms are not intercepted');
const singleton = local.api, count = local.window.events.beforeunload.length; local.runAgain();
check(singleton === local.window.KapeLoading && local.window.events.beforeunload.length === count, 'Repeated shared includes cannot duplicate the loading controller');
const broken = fixture({ complete: true }); broken.api.start({ delay: 0 }); broken.advance(0); broken.image.fire('error');
check(broken.image.hidden && !broken.root.children[0].children[1].hidden, 'An unavailable logo falls back to the brand name');
const css = fs.readFileSync('public/css/app-loading.css', 'utf8');
check(css.includes('@media (prefers-reduced-motion: reduce)') && css.includes('animation: none') && css.includes('@media print'), 'The presentation supports reduced motion and print exclusion');
check(css.includes('pointer-events: none') && !css.includes('.sidebar'), 'Loading feedback cannot capture clicks or resize sidebar navigation');
console.log('All ' + checks + ' loading lifecycle checks passed.');
