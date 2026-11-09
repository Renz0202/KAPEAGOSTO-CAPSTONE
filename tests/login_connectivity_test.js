'use strict';
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('public/scripts/login-connectivity.js', 'utf8');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS: ' + label); }
function fixture(localLogin, online) {
  const events = {}, scripts = [], timers = new Map();
  let now = 0, nextTimer = 0, callbacks, resets = 0;
  function element() {
    return { events: {}, children: [], dataset: {}, disabled: true, hidden: false, textContent: '', type: 'password', attrs: {},
      addEventListener(name, fn) { this.events[name] = fn; },
      setAttribute(name, value) { this.attrs[name] = value; } };
  }
  const response = { value: '' };
  const elements = {};
  for (const id of ['loginForm', 'loginButton', 'password', 'togglePass', 'recaptcha-widget', 'recap-container', 'loginConnectionStatus']) elements[id] = element();
  elements.loginForm.dataset = { localLogin: String(localLogin), recaptchaSiteKey: 'fixture-key' };
  elements.loginForm.querySelectorAll = () => [response];
  const navigator = { onLine: online };
  const window = { addEventListener(name, fn) { events[name] = fn; } };
  const document = {
    getElementById(id) { return elements[id] || null; },
    createElement() { return { removed: false, remove() { this.removed = true; } }; },
    head: { appendChild(script) { scripts.push(script); } },
  };
  vm.runInNewContext(source, {
    window, document, navigator,
    setTimeout(fn, delay) { const id = ++nextTimer; timers.set(id, { fn, at: now + delay }); return id; },
    clearTimeout(id) { timers.delete(id); },
  });
  return { elements, scripts, navigator, response,
    event(name) { events[name]?.(); },
    advance(ms) {
      const until = now + ms;
      for (;;) {
        const next = [...timers].filter(([, timer]) => timer.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
        if (!next) break;
        timers.delete(next[0]); now = next[1].at; next[1].fn();
      }
      now = until;
    },
    submit() { let blocked = false; elements.loginForm.events.submit({ preventDefault() { blocked = true; } }); return !blocked; },
    readyCaptcha() {
      window.grecaptcha = {
        render(target, options) { callbacks = options; target.children.push({}); return 0; },
        reset(id) { assert.equal(id, 0); resets++; response.value = ''; },
      };
      window.kapeLoginCaptchaReady();
    },
    lateReady() { window.kapeLoginCaptchaReady(); },
    solve(token = 'fixture-token') { response.value = token; callbacks.callback(token); },
    expire() { response.value = ''; callbacks['expired-callback'](); },
    widgetError() { callbacks['error-callback'](); },
    get resets() { return resets; },
  };
}

const local = fixture(true, true);
check(local.scripts.length === 1 && local.elements.loginButton.disabled && !local.submit(), 'Online local login loads Google and waits for verification');
local.readyCaptcha();
check(!local.elements['recap-container'].hidden && local.elements.loginButton.disabled && !local.submit(), 'Rendering a local CAPTCHA alone does not unlock login');
local.solve();
check(!local.elements.loginButton.disabled && local.submit(), 'Completing the online local CAPTCHA enables login');
local.lateReady();
check(local.resets === 0 && local.submit(), 'A duplicate API callback does not erase a completed challenge');
local.expire();
check(local.elements.loginButton.disabled && !local.submit(), 'An expired CAPTCHA must be completed again');
local.solve();
local.navigator.onLine = false; local.event('offline');
check(!local.elements.loginButton.disabled && local.submit() && local.elements['recap-container'].hidden, 'Losing internet enables local fallback and hides the widget');
check(local.response.value === '', 'Outage fallback clears any stale CAPTCHA response');
local.solve('late-token');
check(local.elements['recap-container'].hidden, 'Late widget responses cannot restore online mode during an outage');
local.navigator.onLine = true; local.event('online');
check(!local.elements['recap-container'].hidden && local.resets === 1 && !local.submit(), 'Reconnecting restores CAPTCHA and requires fresh verification');
local.solve();
check(local.submit(), 'Local online login works again after reconnection');
local.widgetError();
check(local.submit() && local.elements['recap-container'].hidden && local.response.value === '', 'A Google widget network error enables local fallback');
local.advance(10000);
check(!local.submit() && !local.elements['recap-container'].hidden && local.resets === 2, 'A widget error automatically retries and restores the challenge');

const offline = fixture(true, false);
check(offline.scripts.length === 0 && offline.submit() && offline.elements['recap-container'].hidden, 'Opening the page without internet permits a local fallback attempt');
offline.elements.togglePass.events.click();
check(offline.elements.password.type === 'text' && offline.elements.togglePass.textContent === 'hide', 'Password visibility still works offline');
offline.navigator.onLine = true; offline.event('online');
check(offline.scripts.length === 1 && !offline.submit(), 'An offline page starts CAPTCHA loading when internet returns');
offline.readyCaptcha(); offline.solve();
check(offline.submit() && !offline.elements['recap-container'].hidden, 'Recovered internet restores normal CAPTCHA login');

const stalled = fixture(true, true);
stalled.advance(3999);
check(!stalled.submit(), 'A pending Google request keeps online login locked');
stalled.advance(1);
check(stalled.submit() && stalled.scripts[0].removed && stalled.elements['recap-container'].hidden, 'A stalled Google script permits local fallback after four seconds');
stalled.advance(10000);
check(stalled.scripts.length === 2 && stalled.submit(), 'Background reconnection attempts keep the local fallback usable');
stalled.scripts[0].onerror();
stalled.readyCaptcha();
check(!stalled.submit() && !stalled.elements['recap-container'].hidden, 'Late failed requests cannot prevent CAPTCHA recovery');
stalled.solve(); stalled.advance(4000);
check(stalled.submit(), 'The old load timeout cannot disable a recovered and solved challenge');

const failed = fixture(true, true);
failed.scripts[0].onerror();
check(failed.submit() && failed.elements.loginConnectionStatus.textContent.includes('Internet unavailable'), 'A failed Google script immediately exposes local fallback');
failed.advance(10000); failed.readyCaptcha();
check(!failed.submit() && !failed.elements['recap-container'].hidden, 'Local fallback returns to CAPTCHA after Google becomes available');

const remote = fixture(false, false);
check(remote.elements.loginButton.disabled && !remote.submit() && remote.scripts.length === 0, 'Offline public login stays blocked');
remote.navigator.onLine = true; remote.event('online');
check(remote.scripts.length === 1 && !remote.submit(), 'Public login loads CAPTCHA after internet returns');
remote.scripts[0].onerror();
check(!remote.submit(), 'A Google script error cannot enable public fallback');
remote.advance(10000); remote.readyCaptcha();
check(!remote.submit(), 'Public login requires a solved challenge, not just a rendered widget');
remote.solve(); check(remote.submit(), 'Public login unlocks after CAPTCHA completion');
remote.widgetError(); check(!remote.submit(), 'A Google widget error keeps public login blocked');
remote.advance(10000); remote.solve();
remote.navigator.onLine = false; remote.event('offline');
check(!remote.submit(), 'Losing internet never enables public fallback');
const publicTimeout = fixture(false, true); publicTimeout.advance(4000);
check(!publicTimeout.submit(), 'A timeout cannot enable public fallback');
console.log('Passed ' + checks + ' login connectivity checks.');
