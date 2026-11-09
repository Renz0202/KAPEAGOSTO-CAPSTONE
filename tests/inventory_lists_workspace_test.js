'use strict';
// Run the real stock/exception client against DOM and fetch fixtures.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let checks=0;
function check(value,label){assert.ok(value,label);checks++;console.log('PASS: '+label);}
class Element{
 constructor(dataset={}){this.dataset=dataset;this.events={};this.attributes={};this.value='';this.textContent='';this.hidden=true;this.scrollLeft=0;this.scrollTop=0;}
 addEventListener(name,fn){(this.events[name]||=[]).push(fn);}
 async fire(name,event={}){await Promise.all((this.events[name]||[]).map(fn=>fn(event)));}
 setAttribute(name,value){this.attributes[name]=value;}
 closest(selector){return selector==='[data-list-page]'&&this.dataset.listPage!==undefined?this:selector==='[data-list-details]'&&this.dataset.listDetails!==undefined?this:null;}
 focus(){this.owner.activeElement=this;}
}
function fixture(key){
 const isStock=key==='stock',document={activeElement:null},owned=node=>Object.assign(node,{owner:document});
 const search=isStock?'search':'void_search',pageKey=isStock?'stock_page':'void_page',refresh=isStock?'refreshInventory':'refreshVoidedOrders';
 const fieldNames=isStock?['search','material_group','stock_category','stock_status']:['void_search','void_from','void_to','void_status'];
 const fields=Object.fromEntries(fieldNames.map(name=>[name,owned(new Element())]));
 if(!isStock)fields.void_from.value=fields.void_to.value='2026-10-09';
 const form=owned(new Element());form.elements={namedItem:name=>fields[name]||null};
 const status=owned(new Element()),stamp=owned(new Element()),close=owned(new Element());
 const detailFields=['title','quantity','service'].map(name=>owned(new Element({listField:name})));
 const dialog=owned(new Element());dialog.open=false;dialog.querySelector=()=>close;dialog.querySelectorAll=()=>detailFields;dialog.showModal=()=>{dialog.open=true;};dialog.close=()=>{dialog.open=false;dialog.fire('close');};
 function view(data={page:3,id:42}){
  const root=owned(new Element({page:String(data.page),inventoryList:data.key||key})),frame=owned(new Element()),pageStatus=owned(new Element());
  const button=owned(new Element({listId:String(data.id||42),listDetails:JSON.stringify({title:'Full item details',quantity:'123.45 ml',service:'<script>Plain text</script>'})}));
  const edit=owned(new Element({listFocus:'edit-42'}));
  return {root,frame,pageStatus,button,edit,valid:data.valid!==false,querySelector(selector){if(selector==='[data-inventory-list]')return this.valid?root:null;if(selector==='.inventory-list-table-frame')return frame;if(selector==='[data-list-page-status]')return pageStatus;if(selector==='[data-list-id="'+button.dataset.listId+'"]')return button;if(selector==='[data-list-focus="edit-42"]')return edit;return null;}};
 }
 const container=owned(new Element());container.current=view();container.querySelector=selector=>container.current.querySelector(selector);container.replaceChildren=content=>{container.current=content;};container.contains=node=>Object.values(container.current).includes(node);
 const metrics=Object.fromEntries(['inventory-summary-items','inventory-summary-raw-materials','inventory-summary-low-stock','inventory-voided-today'].map(id=>[id,owned(new Element())]));
 const ids={...metrics,...(isStock?{inventoryStockFilters:form,inventoryRealtime:container,inventoryStockDetails:dialog,inventoryStockStatus:status,invUpdatedAt:stamp}:{inventoryVoidFilters:form,voidsRealtime:container,inventoryVoidDetails:dialog,inventoryVoidStatus:status,voidsUpdatedAt:stamp})};
 document.getElementById=id=>ids[id]||null;
 document.createElement=()=>({set innerHTML(html){this.content=view(JSON.parse(html));}});
 const window={location:{href:'http://localhost/KAPEAGOSTO/public/inventory.php?tab='+key+'&'+pageKey+'=3&log_search=KEEP'},history:{replaceState(_a,_b,url){window.location.href=String(url);}}};
 const requests=[],timers=new Map();let timerId=0,response={page:3,id:42},ok=true,deferred=false;
 const makeResponse=data=>({ok,headers:{get:name=>({'X-Inventory-Items':'42','X-Inventory-Raw-Materials':'33','X-Inventory-Low-Stock':'2','X-Inventory-Voided-Today':'7'})[name]||null},text:async()=>JSON.stringify(data)});
 const fetch=(url,options)=>{const request={url:new URL(url,window.location.href),options};requests.push(request);if(deferred)return new Promise(resolve=>{request.resolve=data=>resolve(makeResponse(data));});return Promise.resolve(makeResponse(response));};
 vm.runInNewContext(fs.readFileSync('public/scripts/inventory-lists.js','utf8'),{document,window,fetch,URL,URLSearchParams,AbortController,setTimeout:fn=>{const id=++timerId;timers.set(id,fn);return id;},clearTimeout:id=>timers.delete(id)});
 return {key,isStock,search,pageKey,refresh,document,owned,fields,form,status,stamp,close,dialog,detailFields,container,metrics,window,requests,timers,setResponse:data=>{response=data;},setOk:value=>{ok=value;},setDeferred:value=>{deferred=value;}};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 for(const key of ['stock','voided']){
  const ui=fixture(key),refresh=options=>ui.window[ui.refresh](options),prefix=key+': ';
  ui.container.current.frame.scrollLeft=55;ui.container.current.frame.scrollTop=120;ui.container.current.frame.focus();await refresh();
  check(ui.requests[0].url.searchParams.get(ui.pageKey)==='3'&&ui.container.current.root.dataset.page==='3',prefix+'automatic refresh preserves the current page');
  check(ui.container.current.frame.scrollLeft===55&&ui.container.current.frame.scrollTop===120&&ui.document.activeElement===ui.container.current.frame,prefix+'automatic refresh preserves scrolling and focus');
  check(ui.container.attributes['aria-busy']==='false'&&ui.stamp.textContent.length>0,prefix+'successful refresh clears busy state and updates time');
  check(ui.isStock?ui.metrics['inventory-summary-items'].textContent==='42'&&ui.metrics['inventory-summary-raw-materials'].textContent==='33'&&ui.metrics['inventory-summary-low-stock'].textContent==='2':ui.metrics['inventory-voided-today'].textContent==='7',prefix+'global summary counts come from response headers');
  ui.container.current.edit.focus();await refresh();check(ui.document.activeElement===ui.container.current.edit,prefix+'refresh retains stock action keyboard focus');
  ui.fields[ui.search].value='MATCH';if(ui.isStock){ui.fields.stock_status.value='low';ui.fields.material_group.value='dairy';}else ui.fields.void_status.value='cancelled';
  ui.setResponse({page:1,id:50});let submitted=false;await ui.form.fire('submit',{preventDefault(){submitted=true;}});await tick();
  let params=ui.requests.at(-1).url.searchParams;
  check(submitted&&params.get(ui.pageKey)==='1'&&params.get(ui.search)==='MATCH'&&(ui.isStock?params.get('stock_status')==='low'&&params.get('material_group')==='dairy':params.get('void_status')==='cancelled'),prefix+'combined filters apply inside the frame and reset page');
  check(new URL(ui.window.location.href).searchParams.get('log_search')==='KEEP'&&!new URL(ui.window.location.href).searchParams.has(ui.pageKey),prefix+'filter address preserves unrelated tab state');
  const next=ui.owned(new Element({listPage:'2'}));ui.setResponse({page:2,id:51});let prevented=false;await ui.container.fire('click',{target:next,preventDefault(){prevented=true;}});await tick();
  check(prevented&&ui.requests.at(-1).url.searchParams.get(ui.pageKey)==='2'&&new URL(ui.window.location.href).searchParams.get(ui.pageKey)==='2',prefix+'Next navigates and updates the address');
  check(ui.document.activeElement===ui.container.current.pageStatus,prefix+'page navigation focuses the result count');
  const before=ui.requests.length;ui.fields[ui.search].value='A';await ui.fields[ui.search].fire('input');ui.fields[ui.search].value='LATEST';await ui.fields[ui.search].fire('input');await refresh();
  check(ui.timers.size===1&&ui.requests.length===before,prefix+'search is debounced and automatic refresh waits');
  ui.setResponse({page:1,id:60});const task=[...ui.timers.values()][0];ui.timers.clear();await task();
  check(ui.requests.at(-1).url.searchParams.get(ui.search)==='LATEST'&&ui.requests.at(-1).url.searchParams.get(ui.pageKey)==='1',prefix+'search uses the latest text on page one');
  if(!ui.isStock){ui.fields.void_from.value='2026-10-10';ui.fields.void_to.value='2026-10-09';await ui.form.fire('change');await tick();check(ui.fields.void_to.value==='2026-10-10'&&ui.fields.void_to.min==='2026-10-10',prefix+'date changes normalize reversed ranges');}
  const button=ui.container.current.button;await ui.container.fire('click',{target:button});
  check(ui.dialog.open&&ui.detailFields.find(node=>node.dataset.listField==='service').textContent==='<script>Plain text</script>',prefix+'details open with complete plain text');
  const beforeDialog=ui.requests.length;await refresh();check(ui.requests.length===beforeDialog,prefix+'an open details dialog pauses refresh');
  await ui.close.fire('click');check(!ui.dialog.open&&ui.document.activeElement===button,prefix+'closing details restores row focus');
  const old=ui.container.current;ui.setOk(false);await refresh({resetPage:true});check(ui.container.current===old&&!ui.status.hidden&&ui.status.textContent.includes('previous results'),prefix+'failed requests keep the previous list');
  ui.setOk(true);ui.setResponse({page:1,valid:false});await refresh({resetPage:true});check(ui.container.current===old,prefix+'login or malformed responses cannot replace the table');
  ui.setResponse({page:1,key:ui.isStock?'voided':'stock'});await refresh({resetPage:true});check(ui.container.current===old,prefix+'responses for another tab cannot replace this list');
  ui.setDeferred(true);const first=refresh({resetPage:true});const earlier=ui.requests.at(-1),count=ui.requests.length;await refresh();check(ui.requests.length===count,prefix+'background ticks do not abort a slow request');
  const second=refresh({resetPage:true,updateUrl:true}),latest=ui.requests.at(-1);latest.resolve({page:1,id:99});await second;earlier.resolve({page:3,id:10});await first;
  check(earlier.options.signal.aborted&&ui.container.current.button.dataset.listId==='99',prefix+'stale results cannot overwrite newer filters');
  const inFlight=refresh(),pending=ui.requests.at(-1),beforePopup=ui.container.current;await ui.container.fire('click',{target:ui.container.current.button});pending.resolve({page:1,id:100});await inFlight;
  check(ui.container.current===beforePopup&&ui.dialog.open,prefix+'a dialog opened during a request retains its row');await ui.close.fire('click');
  ui.setDeferred(false);ui.setResponse({page:2,id:101});await refresh({page:999,updateUrl:true});check(new URL(ui.window.location.href).searchParams.get(ui.pageKey)==='2',prefix+'server page clamping is reflected in the address');
 }
 console.log('All '+checks+' stock and exception interaction checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
