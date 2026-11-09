'use strict';
// Execute the real history client with DOM, dialog, timer, and response fixtures.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let checks=0;
function check(value,label){assert.ok(value,label);checks++;console.log('PASS: '+label);}
class Element{
 constructor(dataset={}){this.dataset=dataset;this.value='';this.events={};this.attributes={};this.textContent='';this.hidden=true;this.scrollLeft=0;this.scrollTop=0;}
 addEventListener(name,fn){(this.events[name]||=[]).push(fn);}
 async fire(name,event={}){await Promise.all((this.events[name]||[]).map(fn=>fn(event)));}
 setAttribute(name,value){this.attributes[name]=value;}
 closest(selector){return selector==='[data-log-page]'&&this.dataset.logPage!==undefined?this:selector==='[data-log-details]'&&this.dataset.logDetails!==undefined?this:null;}
 focus(options){this.owner.activeElement=this;this.focusOptions=options;}
}
function fixture(){
 const document={activeElement:null};
 const owned=node=>Object.assign(node,{owner:document});
 const fields=Object.fromEntries(['from','to','log_type','log_search'].map(name=>[name,owned(new Element())]));
 fields.from.value=fields.to.value='2026-10-09';
 const form=owned(new Element());form.elements={namedItem:name=>fields[name]};
 const status=owned(new Element()),stamp=owned(new Element()),close=owned(new Element());
 const detailFields=['title','item','sku','type','quantity','balance','current_stock','critical_level','movement_at','recorded_at','order_reference','remarks'].map(name=>owned(new Element({logField:name})));
 const dialog=owned(new Element());dialog.open=false;dialog.querySelector=()=>close;dialog.querySelectorAll=()=>detailFields;dialog.showModal=()=>{dialog.open=true;};dialog.close=()=>{dialog.open=false;dialog.fire('close');};
 function view(data={page:3,logId:'42'}){
  const root=owned(new Element({page:String(data.page)})),frame=owned(new Element()),pageStatus=owned(new Element());
  const button=owned(new Element({logId:String(data.logId||42),logDetails:JSON.stringify({title:'Movement #42',item:'MILK',remarks:'<script>unsafe</script>',balance:'150 ml',current_stock:'900 ml',movement_at:'September 16, 2026 03:53:47 PM',recorded_at:'October 10, 2026 06:55:51 AM',order_reference:'160926-01'})}));
  return {root,frame,pageStatus,button,valid:data.valid!==false,querySelector(selector){if(selector==='[data-log-view]')return this.valid?root:null;if(selector==='.inventory-log-table-frame')return frame;if(selector==='[data-log-page-status]')return pageStatus;if(selector==='[data-log-id="'+button.dataset.logId+'"]')return button;return null;}};
 }
 const container=owned(new Element());container.current=view();container.querySelector=selector=>container.current.querySelector(selector);container.replaceChildren=content=>{container.current=content;};
 document.getElementById=id=>({inventoryHistoryFilters:form,logsRealtime:container,inventoryMovementDetails:dialog,inventoryHistoryStatus:status,logsUpdatedAt:stamp})[id]||null;
 document.createElement=tag=>{assert.equal(tag,'template');return{set innerHTML(html){this.content=view(JSON.parse(html));}};};
 const timers=new Map(),requests=[];
 let timerId=0,response={page:3,logId:'42'},ok=true,deferred=false;
 const window={location:{href:'http://localhost/KAPEAGOSTO/public/inventory.php?tab=logs&log_page=3'},history:{replaceState(_a,_b,url){window.location.href=String(url);}}};
 const makeResponse=data=>({ok,json:async()=>data,text:async()=>JSON.stringify(data)});
 const fetch=(url,options)=>{const request={url:new URL(url,'http://localhost/KAPEAGOSTO/public/'),options};requests.push(request);if(deferred)return new Promise(resolve=>{request.resolve=data=>resolve(makeResponse(data));});return Promise.resolve(makeResponse(response));};
 vm.runInNewContext(fs.readFileSync('public/scripts/inventory-history.js','utf8'),{document,window,fetch,URL,URLSearchParams,AbortController,setTimeout:fn=>{const id=++timerId;timers.set(id,fn);return id;},clearTimeout:id=>timers.delete(id)});
 return {document,owned,fields,form,status,stamp,dialog,close,detailFields,container,window,requests,timers,setResponse:data=>{response=data;},setOk:value=>{ok=value;},setDeferred:value=>{deferred=value;}};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 const ui=fixture();ui.container.current.frame.scrollLeft=60;ui.container.current.frame.scrollTop=140;ui.container.current.frame.focus({preventScroll:true});
 await ui.window.refreshLogs();
 check(ui.requests[0].url.searchParams.get('log_page')==='3'&&ui.container.current.root.dataset.page==='3','Automatic refresh stays on the current page');
 check(ui.container.current.frame.scrollLeft===60&&ui.container.current.frame.scrollTop===140&&ui.document.activeElement===ui.container.current.frame,'Automatic refresh preserves table scrolling and keyboard focus');
 check(ui.container.attributes['aria-busy']==='false'&&ui.stamp.textContent.length>0,'Successful refresh clears loading state and updates its timestamp');
 ui.fields.log_search.value='FRESH MILK';ui.fields.log_type.value='used';ui.setResponse({page:1,logId:'50'});await ui.form.fire('change');await tick();
 let params=ui.requests.at(-1).url.searchParams;
 check(params.get('log_page')==='1'&&params.get('log_search')==='FRESH MILK'&&params.get('log_type')==='used'&&!params.has('search'),'Item and type filters reset pagination and stay independent of Current Stock search');
 check(new URL(ui.window.location.href).searchParams.get('log_search')==='FRESH MILK'&&!new URL(ui.window.location.href).searchParams.has('log_page'),'Filter state is preserved in a reloadable address');
 check(ui.container.current.frame.scrollTop===0,'Applying filters starts at the top of the first result page');
 const next=ui.owned(new Element({logPage:'2'}));ui.setResponse({page:2,logId:'51'});let prevented=false;await ui.container.fire('click',{target:next,preventDefault(){prevented=true;}});await tick();
 check(prevented&&ui.requests.at(-1).url.searchParams.get('log_page')==='2'&&new URL(ui.window.location.href).searchParams.get('log_page')==='2','Next navigates inside the frame and updates the page address');
 check(ui.document.activeElement===ui.container.current.pageStatus,'Keyboard page navigation announces and focuses the new result range');
 const beforeTyping=ui.requests.length;ui.fields.log_search.value='MI';await ui.fields.log_search.fire('input');ui.fields.log_search.value='MATCHA';await ui.fields.log_search.fire('input');await ui.window.refreshLogs();
 check(ui.timers.size===1&&ui.requests.length===beforeTyping,'Typing is debounced and background refresh waits for the item search');
 ui.setResponse({page:1,logId:'60'});const search=[...ui.timers.values()][0];ui.timers.clear();await search();
 check(ui.requests.at(-1).url.searchParams.get('log_search')==='MATCHA'&&ui.requests.at(-1).url.searchParams.get('log_page')==='1','Search uses the latest typed text and starts on page one');
 ui.fields.from.value='2026-10-10';ui.fields.to.value='2026-10-09';await ui.form.fire('change');await tick();
 check(ui.fields.to.value==='2026-10-10'&&ui.fields.to.min==='2026-10-10','Date changes prevent a reversed date range');
 const button=ui.container.current.button;await ui.container.fire('click',{target:button});
 check(ui.dialog.open&&ui.detailFields.find(field=>field.dataset.logField==='remarks').textContent==='<script>unsafe</script>','View Details opens a dialog and inserts complete remarks as plain text');
 check(ui.detailFields.find(field=>field.dataset.logField==='balance').textContent==='150 ml'&&ui.detailFields.find(field=>field.dataset.logField==='current_stock').textContent==='900 ml','Details distinguish the movement balance from current inventory');
 check(ui.detailFields.find(field=>field.dataset.logField==='movement_at').textContent==='September 16, 2026 03:53:47 PM'&&ui.detailFields.find(field=>field.dataset.logField==='recorded_at').textContent==='October 10, 2026 06:55:51 AM'&&ui.detailFields.find(field=>field.dataset.logField==='order_reference').textContent==='160926-01','Details retain the separate sale date, correction date and order reference');
 const beforeDialogRefresh=ui.requests.length;await ui.window.refreshLogs();check(ui.requests.length===beforeDialogRefresh,'An open details dialog is not disrupted by background refresh');
 await ui.close.fire('click');check(!ui.dialog.open&&ui.document.activeElement===button,'Closing details returns keyboard focus to the same movement');
 const oldView=ui.container.current;ui.setOk(false);await ui.window.refreshLogs({resetPage:true});check(ui.container.current===oldView&&!ui.status.hidden&&ui.status.textContent.includes('previous results'),'A request failure retains the previous list and reports the problem');
 ui.setOk(true);ui.setResponse({page:1,valid:false});await ui.window.refreshLogs({resetPage:true});check(ui.container.current===oldView,'A login page or malformed response cannot replace the movement table');
 ui.setDeferred(true);const first=ui.window.refreshLogs({resetPage:true});const earlier=ui.requests.at(-1);const count=ui.requests.length;await ui.window.refreshLogs();
 check(ui.requests.length===count,'Background ticks do not repeatedly cancel a slow request');
 ui.fields.log_search.value='LATEST';const second=ui.window.refreshLogs({resetPage:true,updateUrl:true});const latest=ui.requests.at(-1);
 latest.resolve({page:1,logId:'99'});await second;earlier.resolve({page:3,logId:'10'});await first;
 check(earlier.options.signal.aborted&&ui.container.current.button.dataset.logId==='99','An older response cannot overwrite a newer item filter');
 ui.setDeferred(false);ui.setResponse({page:2,logId:'100'});await ui.window.refreshLogs({page:999,updateUrl:true});
 check(new URL(ui.window.location.href).searchParams.get('log_page')==='2','If history shrinks, the address follows the page clamped by the server');
 console.log('All '+checks+' movement history interaction checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
