'use strict';
// Exercise the actual browser controller with isolated DOM, network, and account fixtures.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('public/scripts/user-guide.js', 'utf8');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS: ' + label); }
class Element {
  constructor(tag = 'div', doc = null) {
    this.tagName = tag.toUpperCase(); this.doc = doc; this.children = []; this.parent = null; this.attrs = {};
    this.dataset = {}; this.hidden = false; this.events = {}; this.value = ''; this._text = ''; this.isConnected = true; this.classes = new Set();
    this.classList = { add: (...names) => names.forEach(name => this.classes.add(name)), remove: (...names) => names.forEach(name => this.classes.delete(name)), contains: name => this.classes.has(name) || this.className?.split(' ').includes(name) };
  }
  set textContent(value) { this._text = value; this.children = []; } get textContent() { return this._text + this.children.map(child => child.textContent).join(' '); }
  setAttribute(key, value) { this.attrs[key] = String(value); } getAttribute(key) { return this.attrs[key] ?? null; }
  append(...nodes) { nodes.forEach(node => { node.parent = this; this.children.push(node); }); }
  replaceChildren(...nodes) { this.children = []; this._text = ''; this.append(...nodes); }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  matches(selector) {
    return selector.split(',').some(part => {
      part = part.trim();
      if (part === '[hidden]') return this.hidden;
      if (part.startsWith('#')) return this.id === part.slice(1);
      const attr = part.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/);
      if (attr) return this.attrs[attr[1]] !== undefined && (attr[2] === undefined || this.attrs[attr[1]] === attr[2]);
      if (part === 'input:not([type="hidden"])') return this.tagName === 'INPUT' && this.attrs.type !== 'hidden';
      return this.tagName.toLowerCase() === part.toLowerCase();
    });
  }
  closest(selector) { return this.matches(selector) ? this : this.parent?.closest(selector) || null; }
  querySelectorAll(selector) { return this.children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]); }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  getClientRects() { return this.hidden ? [] : [{}]; }
  addEventListener(type, fn, capture = false) { (this.events[type] ||= []).push({ fn, capture: !!capture }); }
  fire(type, event = {}, capture = false) { for (const item of this.events[type] || []) if (item.capture === capture && !event.stopped) item.fn(event); }
  focus() { this.doc.activeElement = this; }
  scrollIntoView() { this.scrolled = true; }
  showModal() { this.open = true; } close() { this.open = false; }
}
function fixture({ needsWelcome = false, userId = 3, fail = false, pending = null, accountMenu = false, roleLabel = 'Admin', includeRole = false } = {}) {
  const document = new Element('document'); document.doc = document;
  document.createElement = tag => new Element(tag, document);
  const body = document.createElement('body'); document.append(body); document.body = body;
  const ids = {};
  function add(id, tag = 'div', parent = body) { const node = document.createElement(tag); node.id = id; parent.append(node); ids[id] = node; return node; }
  const dialog = add('kapeGuide', 'dialog');
  ['kapeGuideTitle','kgWorkspace','kgWelcome','kgSaveStatus','kgWelcomeTitle','kgWelcomeText','kgWelcomeCount','kgWelcomeDots','kgNoResults'].forEach(id => add(id, 'div', dialog));
  add('kgArticle','article',ids.kgWorkspace); add('kgTopics','nav',ids.kgWorkspace); add('kgSearch','input',ids.kgWorkspace);
  ['kgExplain','kgReplay','kgWelcomeNext','kgWelcomeBack','kgWelcomeSkip'].forEach(id => add(id,'button',dialog));
  const close = add('close','button',dialog); close.setAttribute('data-guide-close','');
  const profileToggle = accountMenu ? add('accountToggle','button') : null;
  const accountDropdown = accountMenu ? add('accountDropdown') : null;
  if (accountMenu) { profileToggle.setAttribute('aria-controls','accountDropdown'); accountDropdown.setAttribute('role','menu'); accountDropdown.hidden = needsWelcome; }
  const opener = add('open','button',accountDropdown || body); opener.setAttribute('data-open-guide',''); opener.textContent = 'Guide';
  if (accountMenu) opener.addEventListener('click',()=>{ accountDropdown.hidden=true; profileToggle.focus(); });
  const bar = add('kgInspectBar'); bar.hidden = true; add('kgInspectExit','button',bar);
  const help = add('kgControlHelp'); help.hidden = true; add('kgControlTitle','strong',help); add('kgControlText','p',help);
  const restock = add('restock','button'); restock.textContent = 'Restock';
  const submit = add('save','button'); submit.textContent = 'Save'; submit.setAttribute('type','submit');
  const input = add('qty','input'); input.setAttribute('aria-label','Quantity in ml');
  const tab = add('stock','button'); tab.setAttribute('role','tab'); tab.textContent = 'Current Stock';
  const link = add('profile','a'); link.setAttribute('href','/public/profile.php'); link.textContent = 'Profile';
  const hidden = add('hidden','button'); hidden.hidden = true;
  const data = {
    userId, roleLabel, needsWelcome, csrf: 'fixture-csrf', stateUrl: '/public/user_guide_state.php', currentTopic: 'inventory',
    welcome: [['Welcome','Introduction'],['Workspace','Role instructions'],['Help','Explain controls instructions']],
    topics: [
      { id:'inventory',page:'inventory.php',title:'Inventory',intro:'Review real stock.',access:'Maintain stock with verified quantities and units.',steps:['First','Second','Third'],sections:[['Recipes','Amounts per serving'],['History','Original sale date']],
        controls:[{selector:'#restock',label:'Restock material',text:'Records received material and increases stock.'},{selector:'#hidden',label:'Hidden control',text:'Hidden'}],aliases:[],url:'/public/inventory.php' },
      { id:'profile',page:'profile.php',title:'Profile',intro:'Update your own details.',steps:['Review','Edit','Save'],sections:[['Password','Change only when needed']],controls:[],aliases:[],url:'/public/profile.php' },
    ],
  };
  if (includeRole) data.topics.push({id:'role',title:'Your Role & Tasks',intro:'Review business performance.',access:'This guide follows the role assigned by an admin.',steps:['Review sales','Prepare reports','Update planning'],sections:[['Ask an admin','Request changes outside your access.']],controls:[],aliases:[],url:''});
  add('kapeGuideData','script').textContent = JSON.stringify(data);
  document.getElementById = id => ids[id] || null; document.activeElement = profileToggle || opener;
  const window = new Element('window',document), timers = new Map(); let timerId = 0;
  window.setTimeout = (fn, delay) => { const id=++timerId; timers.set(id,{fn,delay}); return id; };
  window.clearTimeout = id => timers.delete(id);
  const storage = new Map(); if (pending) storage.set('kape-guide-pending-'+userId,pending);
  let networkFail = fail; const requests = [];
  const fetch = async (url, options) => { requests.push({url,...options,body:JSON.parse(options.body)}); if(networkFail) throw Error('Offline'); return {ok:true,json:async()=>({success:true})}; };
  let earlierCapture=0;
  document.addEventListener('click',event=>{ if(event.target===restock) earlierCapture++; },true);
  const context = vm.createContext({ document,window,sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},fetch,AbortController });
  vm.runInContext(source,context);
  function dispatch(node, type='click', details={}) {
    const event={target:node,defaultPrevented:false,stopped:false,preventDefault(){this.defaultPrevented=true;},stopImmediatePropagation(){this.stopped=true;},...details};
    window.fire(type,event,true); if(!event.stopped) document.fire(type,event,true); if(!event.stopped) node.fire(type,event); if(!event.stopped) document.fire(type,event);
    return event;
  }
  return { get earlierCapture(){return earlierCapture;}, ids,dialog,opener,profileToggle,accountDropdown,restock,submit,input,tab,link,hidden,document,window,storage,requests,timers,data,
    dispatch,click:node=>dispatch(node), key:(node,key)=>dispatch(node,'keydown',{key}), runAgain:()=>vm.runInContext(source,context),
    setOffline:value=>{networkFail=value;}, online:()=>window.fire('online'), text:()=>ids.kgArticle.textContent,
  };
}
async function settle(){ for(let i=0;i<8;i++) await Promise.resolve(); }
(async()=>{
  const f=fixture();
  check(!f.dialog.open, 'Existing accounts are not interrupted by an automatic welcome');
  f.click(f.opener);
  check(f.dialog.open && f.text().includes('Original sale date') && f.document.activeElement===f.ids.kgSearch, 'Guide opens the current page with keyboard focus on search');
  let pageShortcut=0; f.document.addEventListener('keydown',()=>pageShortcut++);
  const modalShortcut=f.key(f.ids.kgSearch,'F8');
  check(modalShortcut.defaultPrevented && pageShortcut===0, 'An open guide blocks the page checkout shortcut');
  check(!f.key(f.ids.kgSearch,'a').defaultPrevented && pageShortcut===0, 'Guide search keeps native typing without triggering page keyboard handlers');
  check(f.ids.kgArticle.querySelectorAll('button').length===1, 'Find on page lists visible controls only');
  f.ids.kgSearch.value='password'; f.dispatch(f.ids.kgSearch,'input');
  check(f.ids.kgTopics.children[0].hidden && !f.ids.kgTopics.children[1].hidden && f.ids.kgNoResults.hidden, 'Search finds action text inside another topic');
  f.ids.kgSearch.value='notfound'; f.dispatch(f.ids.kgSearch,'input');
  check(!f.ids.kgNoResults.hidden, 'Search reports an honest empty result');
  f.click(f.ids.kgTopics.children[1]);
  check(f.text().includes('Update your own details') && f.document.activeElement===f.ids.kgArticle, 'Choosing a topic renders its instructions and moves focus to the article');
  f.click(f.ids.kgTopics.children[0]);
  let activated=0; f.restock.addEventListener('click',()=>activated++);
  f.click(f.ids.kgArticle.querySelector('button'));
  check(!f.dialog.open && activated===0 && f.restock.scrolled && f.document.activeElement===f.restock, 'Find on page highlights and focuses without activating the real control');
  f.click(f.opener); f.click(f.ids.kgTopics.children[1]); f.click(f.ids.kgExplain);
  check(!f.dialog.open && !f.ids.kgInspectBar.hidden && f.document.body.classList.contains('kg-inspecting'), 'Explain controls enters a clearly labeled mode with an exit button');
  const inspect=f.click(f.restock);
  check(inspect.defaultPrevented && inspect.stopped && activated===0 && f.earlierCapture===0 && f.ids.kgControlText.textContent.includes('increases stock'), 'Explain blocks existing document confirmation and target handlers before they run');
  let submitted=0; f.submit.addEventListener('submit',()=>submitted++);
  const submit=f.dispatch(f.submit,'submit');
  check(submit.defaultPrevented && submitted===0, 'Guide mode blocks form submission');
  const input=f.key(f.input,'9'); const enter=f.key(f.submit,'Enter'); const shortcut=f.key(f.document.body,'F8');
  check(input.defaultPrevented && enter.defaultPrevented && shortcut.defaultPrevented, 'Guide mode blocks field edits, Enter activation, and checkout shortcuts');
  check(!f.key(f.input,'Tab').defaultPrevented, 'Keyboard users can still move between controls with Tab');
  f.click(f.tab); check(f.ids.kgControlTitle.textContent==='Current Stock' && f.ids.kgControlText.textContent.includes('section'), 'Unannotated tabs receive a clear section explanation');
  f.click(f.link); check(f.ids.kgControlTitle.textContent==='Profile' && f.ids.kgControlText.textContent==='Update your own details.', 'Navigation icons use the permitted page’s explanation');
  f.key(f.restock,'Escape'); f.click(f.restock);
  check(f.ids.kgInspectBar.hidden && f.ids.kgControlHelp.hidden && activated===1, 'Escape exits help mode and restores normal actions');
  f.click(f.opener); f.click(f.ids.kgExplain); f.click(f.ids.kgInspectExit);
  check(f.ids.kgInspectBar.hidden && f.document.activeElement===f.opener, 'The visible exit control restores focus to Guide');
  const count=f.ids.kgTopics.children.length; f.runAgain();
  check(f.ids.kgTopics.children.length===count, 'Repeated initialization does not duplicate topics or handlers');

  const menu=fixture({accountMenu:true});
  menu.click(menu.opener);
  check(menu.dialog.open && menu.accountDropdown.hidden, 'Selecting Guide opens help and closes the account dropdown');
  menu.click(menu.ids.close);
  check(menu.document.activeElement===menu.profileToggle, 'Closing the guide returns focus to the visible account toggle');
  menu.accountDropdown.hidden=false; menu.click(menu.opener); menu.click(menu.ids.kgExplain); menu.click(menu.ids.kgInspectExit);
  check(menu.document.activeElement===menu.profileToggle, 'Exiting explanation mode focuses the account toggle rather than a hidden menu item');
  const newMenu=fixture({accountMenu:true,needsWelcome:true});
  newMenu.click(newMenu.ids.kgWelcomeNext); newMenu.click(newMenu.ids.kgWelcomeNext); newMenu.click(newMenu.ids.kgWelcomeNext); await settle(); newMenu.click(newMenu.ids.close);
  check(newMenu.document.activeElement===newMenu.profileToggle, 'The first welcome and guide preserve a visible return target');

  const assigned=fixture({roleLabel:'Manager',includeRole:true}); assigned.click(assigned.opener);
  check(assigned.ids.kapeGuideTitle.textContent==='Manager Guide' && assigned.text().includes('Your access:'), 'Guide shows account identity and the current page access note');
  assigned.click(assigned.ids.kgTopics.children[2]);
  check(assigned.text().includes('Your usual workflow') && assigned.text().includes('Your responsibilities') && assigned.text().includes('Ask an admin'), 'Role overview explains responsibilities and when admin help is needed');
  assigned.ids.kgSearch.value='assigned by an admin'; assigned.dispatch(assigned.ids.kgSearch,'input');
  check(!assigned.ids.kgTopics.children[2].hidden && assigned.ids.kgTopics.children[0].hidden, 'Search includes role assignment and access explanations');

  const first=fixture({needsWelcome:true});
  check(first.dialog.open && first.ids.kgWorkspace.hidden && first.ids.kgWelcomeTitle.textContent==='Welcome', 'New accounts receive the first welcome step');
  first.click(first.ids.kgWelcomeNext); first.click(first.ids.kgWelcomeBack);
  check(first.ids.kgWelcomeTitle.textContent==='Welcome' && first.requests.length===0, 'Welcome navigation does not save incomplete progress');
  first.click(first.ids.kgWelcomeNext); first.click(first.ids.kgWelcomeNext); first.click(first.ids.kgWelcomeNext); await settle();
  check(first.requests.length===1 && first.requests[0].body.status==='completed' && !('user_id' in first.requests[0].body), 'Finishing saves only the signed-in account preference with CSRF');
  check(!first.ids.kgWorkspace.hidden && first.storage.size===0, 'Completion opens the page guides and clears pending storage');
  first.click(first.ids.kgReplay); first.click(first.ids.kgWelcomeSkip); await settle();
  check(first.requests.length===1 && !first.ids.kgWorkspace.hidden, 'Replay is always available without downgrading completed progress');

  const skip=fixture({needsWelcome:true,userId:8}); skip.click(skip.ids.close); await settle();
  check(!skip.dialog.open && skip.requests[0].body.status==='skipped', 'Closing the initial welcome remembers Skip');
  const offline=fixture({needsWelcome:true,userId:9,fail:true}); offline.click(offline.ids.kgWelcomeSkip); await settle();
  check(offline.storage.get('kape-guide-pending-9')==='skipped' && offline.ids.kgSaveStatus.textContent.includes('connection returns'), 'A dropped connection preserves the preference for retry while keeping help usable');
  offline.click(offline.ids.kgExplain); offline.click(offline.restock);
  check(offline.ids.kgControlText.textContent.includes('increases stock'), 'Control explanations work while the connection is lost');
  offline.setOffline(false); offline.online(); await settle();
  check(offline.requests.length===2 && offline.storage.size===0, 'The preference retries once the connection returns');
  const returning=fixture({needsWelcome:true,userId:9,pending:'completed'}); await settle();
  check(!returning.dialog.open && returning.requests[0].body.status==='completed', 'A page reload retries pending progress without repeating the welcome');
  const another=fixture({needsWelcome:true,userId:10});
  check(another.dialog.open && another.requests.length===0, 'Another account keeps its own welcome state');
  console.log('All '+checks+' guide interaction checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
