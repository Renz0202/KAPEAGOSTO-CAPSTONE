'use strict';
// Executes the payment page's actual script with DOM and payment-response fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const pageSource = fs.readFileSync('public/payments/order_payment.php','utf8');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS: ' + label); }
class Element {
  constructor(dataset = {}) { this.dataset = dataset; this.value = ''; this.textContent = ''; this.disabled = false; this.hidden = true; this.events = {}; this.attributes = {}; this.classes = new Set(); this.classList = { add: (...v) => v.forEach(x => this.classes.add(x)), remove: (...v) => v.forEach(x => this.classes.delete(x)), toggle: (v, enabled) => enabled ? this.classes.add(v) : this.classes.delete(v) }; }
  addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
  async fire(name, event = {}) { await Promise.all((this.events[name] || []).map(fn => fn(event))); }
  setAttribute(name, value) { this.attributes[name] = value; if (name === 'disabled') this.disabled = true; }
  removeAttribute(name) { delete this.attributes[name]; if (name === 'disabled') this.disabled = false; }
  hasAttribute(name) { return name === 'disabled' ? this.disabled : Object.hasOwn(this.attributes, name); }
  focus() {}
  scrollIntoView() {}
}
function fixture(initial = {}) {
  const values = { remaining: 150, cash: 0, change: 0, complete: true, ...initial };
  const ids = Object.fromEntries(['payInput','paidValue','remainValue','totalValue','changeDueValue','recordedCashValue','submitOrderBtn','paymentClientNotice'].map(id => [id,new Element()]));
  const cash = new Element({method:'cash'});
  const keypadMarkup = pageSource.slice(pageSource.indexOf('<div class="payment-keypad"'), pageSource.indexOf('<div class="payment-notice"'));
  const buttons = [...keypadMarkup.matchAll(/<button\b([^>]*)>/g)].map(match => {
    const dataset = Object.fromEntries([...match[1].matchAll(/data-(k|action|quick)="([^"]*)"/g)].map(attr => [attr[1],attr[2]]));
    return new Element(dataset);
  });
  const exact = buttons.find(button => button.dataset.action === 'exact');
  const clear = buttons.find(button => button.dataset.action === 'clear');
  const timers = [], notices = [], requests = [];
  let response = { ok: true, order: { status:'pending', total:150, paid:0, remaining:values.remaining }, payments:[], cash_summary:{ cash_received: values.cash, change: values.change, cash_complete: values.complete } };
  let resolvePost;
  const document = {
    getElementById: id => ids[id] || null,
    querySelector: selector => selector === '[data-method="cash"]' || selector === '[data-method=cash]' ? cash : null,
    querySelectorAll: selector => selector === '.payment-keypad button' ? buttons : []
  };
  let script = pageSource.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
  script = script.replace(/<\?=([\s\S]*?)\?>/g, (_, expr) => {
    if (expr.includes('(int) $orderId')) return '1';
    if (expr.includes('htmlspecialchars($userRole')) return 'cashier';
    if (expr.includes('$isPending')) return 'true';
    if (expr.includes('$canAcceptPayment')) return String(values.remaining > 0);
    if (expr.includes('$remaining')) return String(values.remaining);
    if (expr.includes("$cashSummary['cash_received']")) return String(values.cash);
    if (expr.includes("$cashSummary['change']")) return String(values.change);
    if (expr.includes("$cashSummary['cash_complete']")) return String(values.complete);
    if (expr.includes('Void ')) return '"Void test order?"';
    throw new Error('Unhandled PHP expression: ' + expr);
  });
  const fetch = async (url, opts) => {
    if (opts?.method === 'POST') {
      requests.push({url, data:Object.fromEntries(opts.body)});
      return await new Promise(resolve => { resolvePost = payload => resolve({ok:true,json:async()=>payload}); });
    }
    return {ok:true, json:async()=>response};
  };
  vm.runInNewContext(script, { document, fetch, URLSearchParams, FormData:class{}, window:{kapeNotify:(...v)=>notices.push(v),kapeConfirm:async()=>false,location:{}}, setTimeout:fn=>timers.push(fn),setInterval:fn=>timers.push(fn) });
  return {ids,cash,buttons,exact,clear,requests,notices,timers,setResponse:v=>{response=v;},finishPost:payload=>resolvePost(payload)};
}
(async () => {
  const page = fixture();
  page.ids.payInput.value = '500'; await page.ids.payInput.fire('input');
  check(page.ids.changeDueValue.textContent === 'PHP 350.00', 'Entering PHP 500 for PHP 150 previews PHP 350 change');
  check(page.ids.recordedCashValue.textContent === 'PHP 0.00', 'A preview does not pretend cash was already saved');
  const pending = page.cash.fire('click');
  await page.ids.payInput.fire('keydown', {key:'Enter',preventDefault(){}});
  check(page.requests.length === 1 && page.requests[0].data.amount === '500' && page.cash.disabled, 'Cash and Enter share a guard and send the full tender once');
  page.setResponse({ok:true,order:{status:'pending',total:150,paid:150,remaining:0},payments:[],cash_summary:{cash_received:500,change:350,cash_complete:true}});
  page.finishPost({ok:true,tendered:500,applied:150,paid:150,remaining:0,change:350}); await pending;
  check(page.ids.payInput.value === '' && page.ids.recordedCashValue.textContent === 'PHP 500.00' && page.ids.changeDueValue.textContent === 'PHP 350.00', 'Saving clears entry while keeping the recorded cash and change visible');
  check(page.cash.disabled && !page.ids.submitOrderBtn.disabled, 'Settled orders enable receipt printing and disable further cash submissions');
  await page.timers[1]();
  check(page.ids.changeDueValue.textContent === 'PHP 350.00', 'Automatic refresh does not reset saved change to zero');
  await page.clear.fire('click');
  check(page.ids.recordedCashValue.textContent === 'PHP 500.00' && page.ids.changeDueValue.textContent === 'PHP 350.00', 'Clear removes only the draft cash entry');
  const reloaded = fixture({remaining:0,cash:500,change:350});
  check(reloaded.ids.recordedCashValue.textContent === 'PHP 500.00' && reloaded.ids.changeDueValue.textContent === 'PHP 350.00', 'Reloading a settled payment restores saved cash and change immediately');
  const entry = fixture();
  for (const key of ['7','0','.','5','0']) await entry.buttons.find(button => button.dataset.k === key).fire('click');
  check(entry.ids.payInput.value === '70.50', 'The simplified keypad still enters decimal cash amounts');
  await entry.buttons.find(button => button.dataset.action === 'back').fire('click');
  await entry.buttons.find(button => button.dataset.k === '.').fire('click');
  check(entry.ids.payInput.value === '70.5', 'Backspace edits the amount and a second decimal point is ignored');
  await entry.clear.fire('click');
  await entry.buttons.find(button => button.dataset.quick === '20').fire('click');
  await entry.buttons.find(button => button.dataset.quick === '50').fire('click');
  await entry.buttons.find(button => button.dataset.quick === '500').fire('click');
  check(entry.ids.payInput.value === '570.00' && entry.ids.changeDueValue.textContent === 'PHP 420.00' && entry.requests.length === 0, 'Cash shortcuts add amounts and preview change without submitting a payment');
  const invalid = fixture(); invalid.ids.payInput.value = '100.001'; await invalid.cash.fire('click');
  check(invalid.requests.length === 0 && invalid.notices[0][0].includes('valid'), 'Excess decimal places are rejected before a payment request');
  const keyboard = fixture(); await keyboard.exact.fire('click');
  check(keyboard.ids.payInput.value === '150.00' && keyboard.ids.changeDueValue.textContent === 'PHP 0.00', 'Exact cash uses the remaining order balance');
  let prevented = false;
  const keyRequest = keyboard.ids.payInput.fire('keydown',{key:'Enter',preventDefault:()=>{prevented=true;}});
  check(prevented && keyboard.requests[0].data.amount === '150', 'Enter records cash without a form navigation');
  keyboard.setResponse({ok:true,order:{status:'pending',total:150,paid:150,remaining:0},payments:[],cash_summary:{cash_received:150,change:0,cash_complete:true}});
  keyboard.finishPost({ok:true,tendered:150,applied:150,paid:150,remaining:0,change:0}); await keyRequest;
  const older = fixture({remaining:0,complete:false});
  check(older.ids.recordedCashValue.textContent === 'Not fully recorded' && older.ids.changeDueValue.textContent === 'Not recorded', 'Unrecorded historical amounts are not shown as zero cash');
  page.setResponse({ok:true,order:{status:'pending',total:400,paid:400,remaining:0},payments:[],cash_summary:{cash_received:500,change:100,cash_complete:true}}); await page.timers[1]();
  check(page.ids.totalValue.textContent === 'PHP 400.00' && page.ids.changeDueValue.textContent === 'PHP 100.00' && page.ids.recordedCashValue.textContent === 'PHP 500.00', 'Added items refresh the total and remaining change without hiding the original cash');
  console.log('All ' + checks + ' payment entry checks passed.');
})().catch(error => {console.error(error);process.exitCode=1;});
