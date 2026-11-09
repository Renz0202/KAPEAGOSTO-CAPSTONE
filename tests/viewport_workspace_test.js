'use strict';
// Execute the actual viewport controller against layout/focus fixtures, without changing application data.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let checks=0;function check(value,label){assert.ok(value,label);checks++;console.log('PASS: '+label);}
class Classes {
 constructor(values=[]){this.values=new Set(values);} add(...values){values.forEach(v=>this.values.add(v));} remove(...values){values.forEach(v=>this.values.delete(v));} contains(v){return this.values.has(v);} toggle(v,on){if(on===undefined)on=!this.contains(v);on?this.add(v):this.remove(v);return on;}
}
class Element {
 constructor(){this.attrs={};this.dataset={};this.classList=new Classes();this.scrollTop=0;this.scrollLeft=0;this.clientHeight=300;this.clientWidth=900;this.scrollHeight=300;this.scrollWidth=900;this.visible=true;this.hiddenParent=false;this.queries=new Map();this.events={};this.style={values:new Map(),setProperty(k,v){this.values.set(k,v);},getPropertyValue(k){return this.values.get(k)||'';},removeProperty(k){this.values.delete(k);}};}
 hasAttribute(key){return Object.hasOwn(this.attrs,key);}setAttribute(key,value){this.attrs[key]=String(value);}getAttribute(key){return this.attrs[key];}
 matches(){return false;}querySelector(s){return this.queries.get(s)?.[0]||null;}querySelectorAll(s){return this.queries.get(s)||[];}closest(s){return s==='[hidden]'&&this.hiddenParent?{}:null;}getClientRects(){return this.visible?[{}]:[];}getBoundingClientRect(){return {bottom:76};}
 addEventListener(k,fn){(this.events[k]||=[]).push(fn);}fire(k){for(const fn of this.events[k]||[])fn();}
}
function fixture({desktop=true,page='users',excluded=false,missing='',height=300,overflow=false,widthOverflow=false}={}){
 const body=new Element(),main=new Element(),topbar=new Element(),sidebar=new Element(),root=new Element(),frame=new Element(),other=new Element(),link=new Element();
 frame.clientHeight=height;other.visible=false;other.hiddenParent=true;other.clientHeight=0;
 const activePane={users:'.management-table-frame',categories:'.management-table-frame',items:'.management-table-frame',customers:'.management-table-frame',inventory:'.inventory-log-table-frame',dashboard:'[data-dashboard-tabs] .dashboard-tab-panel:not([hidden])',orders:'.order-items-grid',queue:'#orders-grid',payment:'.payment-terminal-panel',recipes:'#inventory-panel-recipes:not([hidden])',reports:'[data-sales-report-history]'}[page];
 if(activePane)main.queries.set(activePane,[frame,other]);
 main.matches=()=>['users','categories','items','inventory','orders','queue','payment','recipes','reports'].includes(page);
 if(['customers','dashboard'].includes(page))main.queries.set('[data-dashboard-tabs], .customer-history-panels',[new Element()]);
 main.clientHeight=600;main.scrollHeight=overflow?700:600;main.clientWidth=1000;main.scrollWidth=widthOverflow?1100:1000;
 body.matches=()=>excluded;
 body.queries.set(':scope > main.content',missing==='main'?[]:[main]);body.queries.set(':scope > .topbar',missing==='topbar'?[]:[topbar]);body.queries.set(':scope > .sidebar-wrapper #sidebar',missing==='sidebar'?[]:[sidebar]);
 link.dataset.tooltip='Manage Inventory';sidebar.queries.set('a[data-tooltip]',[link]);
 const media=new Element();media.matches=desktop;
 const raf=[],resizes=[],mutations=[];
 const window=new Element();window.matchMedia=q=>{window.mediaQuery=q;return media;};window.requestAnimationFrame=fn=>raf.push(fn);
 function ResizeObserver(callback){this.callback=callback;this.targets=[];this.observe=target=>this.targets.push(target);resizes.push(this);}
 function MutationObserver(callback){this.callback=callback;this.observe=(target,options)=>{this.target=target;this.options=options;};mutations.push(this);}
 window.ResizeObserver=ResizeObserver;window.MutationObserver=MutationObserver;
 const document={body,documentElement:root,fonts:{ready:{then(callback){document.fonts.callback=callback;}},addEventListener(){}}};
 vm.runInNewContext(fs.readFileSync('public/scripts/viewport-workspace.js','utf8'),{document,window,ResizeObserver,MutationObserver});
 return {body,main,topbar,sidebar,root,frame,other,link,media,raf,resizes,mutations,window,document,flush(){while(raf.length)raf.shift()();}};
}
const ui=fixture();
check(ui.root.classList.contains('viewport-root')&&ui.body.classList.contains('viewport-shell')&&ui.body.classList.contains('viewport-fill')&&ui.main.dataset.viewportMode==='panels','Desktop management pages activate a viewport with flexible record panels');
check(ui.body.style.getPropertyValue('--workspace-top')==='88px','Workspace placement uses the actual top bar bottom plus a twelve-pixel gap');
check(ui.main.getAttribute('tabindex')==='0'&&ui.main.getAttribute('aria-label')==='Workspace'&&ui.frame.getAttribute('role')==='region'&&ui.frame.getAttribute('tabindex')==='0','Workspace and scrolling records can be reached with the keyboard');
check(ui.link.getAttribute('aria-label')==='Manage Inventory'&&ui.link.getAttribute('title')==='Manage Inventory','Collapsed sidebar links retain accessible names and native tooltips');
check(ui.main.dataset.viewportMode==='panels'&&ui.other.clientHeight===0,'Hidden tabs do not force the current tab into a scrolling document');
for(const page of ['categories','items','customers','inventory','dashboard','orders','queue','payment','recipes','reports'])check(fixture({page}).main.dataset.viewportMode==='panels',page+': its actual scroll area participates in the viewport layout');
const short=fixture({height:100});check(short.main.dataset.viewportMode==='document'&&short.body.classList.contains('viewport-document')&&!short.body.classList.contains('viewport-fill'),'Too little room for readable rows uses one scrolling workspace instead of clipping the records');
const controls=fixture({overflow:true});check(controls.main.dataset.viewportMode==='document','Oversized alerts or fixed controls fall back to a fully reachable scrolling workspace');
check(fixture({widthOverflow:true}).main.dataset.viewportMode==='document','Controls wider than the frame remain reachable through the fallback workspace');
check(fixture({page:'reports',height:90}).main.dataset.viewportMode==='document','A short report history frame falls back to reachable natural content');
check(fixture({page:'reports',overflow:true}).main.dataset.viewportMode==='document','Expanded report range tools remain reachable when fixed controls need more room');
const settings=fixture({page:'settings'});check(settings.main.dataset.viewportMode==='document'&&settings.body.classList.contains('viewport-shell'),'Long settings and report forms use a single workspace frame');
const narrow=fixture({desktop:false});check(!narrow.body.classList.contains('viewport-shell')&&narrow.main.dataset.viewportMode==='flow','Small or short screens retain natural page scrolling');
check(narrow.window.mediaQuery==='screen and (min-width: 1101px) and (min-height: 640px)','Desktop mode uses the same width and height thresholds as the stylesheet');
for(const missing of ['main','topbar','sidebar'])check(!fixture({missing}).root.classList.contains('viewport-root'),'No '+missing+': pages without the complete management shell remain unchanged');
check(!fixture({excluded:true}).root.classList.contains('viewport-root'),'Cashier, receipt, welcome and authentication layouts are excluded');
ui.main.scrollTop=95;ui.main.scrollLeft=12;ui.frame.scrollTop=70;ui.topbar.getBoundingClientRect=()=>({bottom:91});ui.resizes[0].callback();ui.flush();
check(ui.body.style.getPropertyValue('--workspace-top')==='103px'&&ui.main.scrollTop===95&&ui.main.scrollLeft===12&&ui.frame.scrollTop===70,'Header resizing keeps workspace and record scroll positions');
check(ui.resizes[0].targets.includes(ui.main)&&ui.resizes[0].targets.includes(ui.topbar),'Changes in header height and sidebar-driven workspace width trigger layout review');
ui.frame.clientHeight=80;ui.mutations[0].callback();ui.flush();check(ui.main.dataset.viewportMode==='document','A live replacement or expanded notice can switch safely to the accessible fallback');
ui.frame.clientHeight=250;ui.mutations[0].callback();ui.flush();check(ui.main.dataset.viewportMode==='panels','The workspace resumes panel scrolling when room becomes available again');
const replacement=new Element();replacement.clientHeight=300;replacement.setAttribute('aria-label','Current stock table');replacement.setAttribute('role','region');replacement.setAttribute('tabindex','-1');ui.main.queries.set('.management-table-frame',[replacement]);ui.mutations[0].callback();ui.flush();
check(replacement.hasAttribute('data-workspace-scroll')&&replacement.getAttribute('aria-label')==='Current stock table'&&replacement.getAttribute('tabindex')==='-1','Live records receive scroll support without replacing existing labels or focus settings');
check(ui.mutations[0].options.attributeFilter.join(',')==='hidden,class'&&ui.mutations[0].options.subtree&&ui.mutations[0].options.childList,'Tab visibility, live rows and notices trigger updates without observing controller-owned attributes');
ui.window.fire('resize');ui.window.fire('resize');ui.mutations[0].callback();check(ui.raf.length===1,'Repeated updates are batched into one animation frame');ui.flush();
ui.media.matches=false;ui.media.fire('change');ui.flush();check(!ui.body.classList.contains('viewport-shell')&&!ui.body.classList.contains('viewport-fill')&&!ui.root.classList.contains('viewport-root')&&ui.body.style.getPropertyValue('--workspace-top')==='','Narrowing or zooming the window releases every fixed-layout constraint');
ui.media.matches=true;ui.media.fire('change');ui.flush();check(ui.main.dataset.viewportMode==='panels','Returning to desktop dimensions restores the fitted workspace');
const css=fs.readFileSync('public/css/viewport-workspace.css','utf8'),shared=fs.readFileSync('public/partials/stylesheets.php','utf8');
check(css.startsWith('/*')&&css.includes('@media screen and (min-width: 1101px) and (min-height: 640px)')&&css.includes('@media print'),'Viewport styles are scoped to suitable desktop screens and provide print flow');
check(css.includes('.inventory-log-view')&&css.includes('.inventory-log-pagination')&&css.includes('[data-inventory-list]'),'Inventory fitting includes the real live-wrapper and pager classes used by PHP renderers');
check(shared.includes("kape_asset_url('css/viewport-workspace.css')")&&shared.includes("kape_asset_url('scripts/viewport-workspace.js')")&&shared.includes('/scripts/viewport-workspace.js\')) ?>" defer'),'Shared viewport assets load through the canonical asset helper and defer execution');
console.log('All '+checks+' viewport workspace checks passed.');
