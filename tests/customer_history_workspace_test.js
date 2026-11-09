'use strict';
// Exercise the real customer-history client, including delayed network responses.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let checks=0;function check(value,label){assert.ok(value,label);checks++;console.log('PASS: '+label);}
class Element {
  constructor(dataset={}){this.dataset=dataset;this.events={};this.attrs={};this.hidden=false;this.disabled=false;this.value='';this.textContent='';this.scrollTop=0;this.scrollLeft=0;this.isConnected=true;this.writes=0;this.classList={toggle(){}};}
  addEventListener(name,fn){(this.events[name]||=[]).push(fn);}
  fire(name,event={}){for(const fn of this.events[name]||[])fn(event);}
  focus(){this.document.activeElement=this;}
  setAttribute(name,value){this.attrs[name]=value;}getAttribute(name){return this.attrs[name];}
  closest(selector){if(selector==='[data-compact-details]')return this.dataset.compactDetails?this:null;if(selector==='[data-customer-list] tbody')return this.inRows?this:null;return null;}
  set innerHTML(value){this.markup=value;this.writes++;}get innerHTML(){return this.markup;}
}
function fixture(query=''){
 const document={hidden:false,activeElement:null,events:{},addEventListener:Element.prototype.addEventListener,fire:Element.prototype.fire};
 const owned=node=>Object.assign(node,{document}),el=data=>owned(new Element(data));
 const keys=['customers','walkIn','pending','void'],params={customers:'customers_page',walkIn:'walkin_page',pending:'pending_page',void:'void_page'};
 const totals={customers:35,walkIn:22,pending:21,void:0};
 const window={location:{href:'http://localhost/KAPEAGOSTO/public/customers.php'+query},events:{},addEventListener:Element.prototype.addEventListener,fire:Element.prototype.fire,history:{replaceState(_a,_b,url){window.location.href=String(url);}},setInterval(fn){window.interval=fn;},setTimeout(fn){const id=++window.timerId;window.timers.set(id,fn);return id;},clearTimeout(id){window.timers.delete(id);},timerId:0,timers:new Map()};
 function payload(address){const url=new URL(address),groups={},term=url.searchParams.get('search')||'';for(const key of keys){const total=term==='ada'?2:term==='none'?0:totals[key],pages=Math.max(1,Math.ceil(total/10)),page=Math.max(1,Math.min(pages,Number(url.searchParams.get(params[key]))||1));groups[key]={total,page,pages,html:'<tr data-page="'+page+'" data-query="'+term+'" data-count="'+total+'">'+key+'</tr>'};}return {groups,orderTotal:123};}
 const initial=payload(window.location.href),url=new URL(window.location.href),active=keys.includes(url.searchParams.get('history_tab'))?url.searchParams.get('history_tab'):'customers';
 const search=el();search.value=url.searchParams.get('search')||'';
 const form=el(),status=el(),tabInput=el(),reset=el(),total=el(),initialNode=el();initialNode.textContent=JSON.stringify(initial);
 const lists={},nodes={},counts=[],tabs=[],panels={};
 for(const key of keys){const list=el({customerList:key,pageParam:params[key],listNoun:key==='customers'?'customers':'orders'});const body=el();body.innerHTML=initial.groups[key].html;const frame=el(),range=el(),label=el(),prev=el(),next=el(),pager=el();const map={'tbody':body,'.management-table-frame':frame,'[data-compact-range]':range,'[data-compact-page-label]':label,'[data-compact-previous]':prev,'[data-compact-next]':next,'[data-compact-pagination]':pager};list.querySelector=s=>map[s];lists[key]=list;nodes[key]={body,frame,range,label,prev,next,pager};const tab=el({historyTab:key});tab.setAttribute('aria-controls',key+'Panel');tabs.push(tab);panels[key+'Panel']=el();counts.push(el({historyCount:key}),el({historyCount:key}));}
 const dialogs={};for(const id of ['customerHistoryDetails','customerOrderDetails']){const dialog=el(),close=el();dialog.open=false;dialog.fields=['title','email','created'].map(key=>el({compactField:key}));dialog.querySelector=()=>close;dialog.querySelectorAll=()=>dialog.fields;dialog.showModal=()=>{dialog.open=true;};dialog.close=()=>{dialog.open=false;dialog.fire('close');};dialog.closeButton=close;dialogs[id]=dialog;}
 const root=el({activeTab:active});root.querySelectorAll=s=>s==='[data-customer-list]'?Object.values(lists):s==='[data-history-tab]'?tabs:s==='[data-history-count]'?counts:[];
 const ids={customerHistoryWorkspace:root,'customer-search':search,customerHistoryFilters:form,customerHistoryUpdated:status,customerHistoryTabInput:tabInput,customerHistoryReset:reset,customerHistoryOrderTotal:total,customerHistoryState:initialNode,...panels,...dialogs};document.getElementById=id=>ids[id]||null;
 const requests=[];let deferred=false,fail=false;
 function fetch(address,options){const call={address,options};requests.push(call);if(deferred)return new Promise(resolve=>{call.resolve=(data=payload(address))=>resolve({ok:true,json:async()=>data});});return Promise.resolve({ok:!fail,json:async()=>payload(address)});}
 vm.runInNewContext(fs.readFileSync('public/scripts/customer-history.js','utf8'),{document,window,URL,AbortController,fetch,Date});
 return {document,window,root,search,form,status,tabInput,reset,total,lists,nodes,counts,tabs,panels,dialogs,requests,totals,payload,el,params:()=>new URL(window.location.href).searchParams,defer:value=>{deferred=value;},fail:value=>{fail=value;},runTimers(){for(const [id,fn]of window.timers){window.timers.delete(id);fn();}}};
}
const flush=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 const ui=fixture();
 check(ui.requests.length===0&&ui.nodes.customers.range.textContent==='Showing 1\u201310 of 35 customers','Initial pages use server data without a duplicate network request');
 check(ui.nodes.customers.label.textContent==='Page 1 of 4'&&ui.nodes.customers.prev.disabled&&!ui.nodes.customers.next.disabled,'Customer pagination starts with the correct range and button states');
 check(ui.nodes.void.range.textContent==='Showing 0\u20130 of 0 orders'&&ui.nodes.void.prev.disabled&&ui.nodes.void.next.disabled,'An empty tab still displays useful pagination information');
 ui.nodes.customers.frame.scrollTop=80;ui.nodes.customers.frame.scrollLeft=25;ui.nodes.customers.next.fire('click');await flush();
 check(ui.lists.customers.dataset.currentPage==='2'&&ui.params().get('customers_page')==='2'&&ui.nodes.customers.body.innerHTML.includes('data-page="2"'),'Next requests only the chosen customer page and saves it in the URL');
 check(ui.document.activeElement===ui.nodes.customers.range&&ui.nodes.customers.frame.scrollTop===0&&ui.nodes.customers.frame.scrollLeft===0,'Page navigation resets its frame and focuses the visible result range');
 ui.tabs[1].fire('click');ui.nodes.walkIn.next.fire('click');await flush();
 check(ui.lists.customers.dataset.currentPage==='2'&&ui.lists.walkIn.dataset.currentPage==='2'&&ui.params().get('history_tab')==='walkIn','Each tab retains independent pagination');
 let prevented=false;ui.tabs[1].fire('keydown',{key:'End',preventDefault(){prevented=true;}});
 check(prevented&&ui.tabInput.value==='void'&&ui.document.activeElement===ui.tabs[3]&&!ui.panels.voidPanel.hidden,'Keyboard tab navigation preserves focus and accessible selection');
 ui.tabs[1].fire('click');ui.document.activeElement=ui.search;ui.nodes.walkIn.frame.scrollTop=63;
 const before=ui.nodes.walkIn.body.writes;ui.window.interval();await flush();
 check(ui.lists.walkIn.dataset.currentPage==='2'&&ui.tabInput.value==='walkIn'&&ui.nodes.walkIn.frame.scrollTop===63&&ui.nodes.walkIn.body.writes===before,'Unchanged live data keeps the selected tab, page, scroll and existing row elements');
 const requests=ui.requests.length;ui.document.hidden=true;ui.window.interval();await flush();check(ui.requests.length===requests,'Live refresh pauses while the document is hidden');ui.document.hidden=false;
 const button=ui.el({compactDetails:JSON.stringify({title:'Customer',email:'<script>alert(1)</script>',created:'Oct 9'})});button.setAttribute('aria-controls','customerHistoryDetails');button.inRows=true;ui.root.fire('click',{target:button});
 check(ui.dialogs.customerHistoryDetails.open&&ui.dialogs.customerHistoryDetails.fields[1].textContent==='<script>alert(1)</script>','Details values are inserted as plain text in a native dialog');
 ui.window.interval();await flush();check(ui.requests.length===requests,'Live refresh pauses while a details dialog is open');
 ui.dialogs.customerHistoryDetails.closeButton.fire('click');check(!ui.dialogs.customerHistoryDetails.open&&ui.document.activeElement===button,'Closing a details dialog returns focus to its opener');
 ui.window.interval();await flush();check(ui.requests.length===requests,'Live refresh leaves a focused row action undisturbed');ui.document.activeElement=ui.search;
 ui.search.value='ada';ui.search.fire('input');check(ui.requests.length===requests&&ui.window.timers.size===1,'Typing debounces server search');ui.runTimers();await flush();
 check(Object.values(ui.lists).every(list=>list.dataset.currentPage==='1')&&ui.params().get('search')==='ada'&&!ui.params().has('customers_page')&&!ui.params().has('walkin_page'),'New search resets all four pages and persists its filter');
 check(ui.counts.every(node=>node.textContent==='2')&&ui.nodes.customers.body.innerHTML.includes('data-query="ada"'),'Filtered results update all four tab and panel counts');
 ui.reset.fire('click');await flush();check(ui.search.value===''&&!ui.params().has('search')&&ui.document.activeElement===ui.search,'Reset clears search and returns focus to it');
 ui.search.value='none';ui.form.fire('submit',{preventDefault(){}});await flush();check(ui.nodes.customers.range.textContent==='Showing 0\u20130 of 0 customers'&&ui.nodes.customers.next.disabled,'Submitted searches correctly show empty results');
 ui.search.fire('keydown',{key:'Escape',preventDefault(){}});await flush();check(ui.search.value===''&&ui.nodes.customers.range.textContent.includes('of 35'),'Escape clears the search and reloads matching history');
 const restored=fixture('?history_tab=pending&pending_page=3&walkin_page=2&other=keep');check(restored.tabInput.value==='pending'&&restored.lists.pending.dataset.currentPage==='3'&&restored.lists.walkIn.dataset.currentPage==='2'&&restored.params().get('other')==='keep','Reload restores the active tab and all independent page values');
 restored.totals.pending=2;restored.window.interval();await flush();check(restored.lists.pending.dataset.currentPage==='1'&&!restored.params().has('pending_page'),'Live changes clamp a removed page to the last available page');
 restored.window.location.href='http://localhost/customers.php?history_tab=walkIn&walkin_page=2&search=ada';restored.window.fire('popstate');await flush();check(restored.search.value==='ada'&&restored.tabInput.value==='walkIn'&&restored.lists.walkIn.dataset.currentPage==='1','Browser navigation restores search and tab, then accepts server page clamping');
 const failed=fixture();failed.fail(true);failed.nodes.customers.next.fire('click');await flush();check(failed.lists.customers.dataset.currentPage==='1'&&failed.status.textContent.includes('Previous results remain')&&!failed.params().has('customers_page'),'Failed requests retain existing rows and page while displaying a retry message');
 const race=fixture();race.defer(true);race.search.value='old';race.form.fire('submit',{preventDefault(){}});const old=race.requests[0];race.search.value='ada';race.search.fire('input');race.runTimers();const latest=race.requests[1];latest.resolve();await flush();old.resolve();await flush();check(race.params().get('search')==='ada'&&race.nodes.customers.body.innerHTML.includes('data-query="ada"')&&old.options.signal.aborted,'Late responses cannot overwrite newer searches, even if a server ignores abort');
 const interaction=fixture();interaction.defer(true);interaction.window.interval();interaction.document.activeElement=interaction.el();interaction.document.activeElement.inRows=true;interaction.totals.customers=1;interaction.requests[0].resolve();await flush();check(interaction.lists.customers.dataset.currentPage==='1'&&interaction.nodes.customers.range.textContent.includes('of 35'),'A live response arriving during row interaction is deferred');
 const moving=fixture();moving.defer(true);moving.nodes.customers.next.fire('click');moving.tabs[1].fire('click');moving.requests[0].resolve();await flush();check(moving.document.activeElement!==moving.nodes.customers.range&&moving.tabInput.value==='walkIn','A pending request never focuses a hidden tab after the user switches sections');
 const popup=fixture();popup.defer(true);popup.window.interval();const pending=popup.requests[0];const opener=popup.el({compactDetails:'{"title":"Snapshot"}'});opener.setAttribute('aria-controls','customerOrderDetails');popup.root.fire('click',{target:opener});pending.resolve();await flush();check(pending.options.signal.aborted&&popup.dialogs.customerOrderDetails.open&&popup.nodes.customers.body.writes===1,'Opening details cancels in-flight refreshes and preserves the current snapshot');
 console.log('All '+checks+' customer-history interaction checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
