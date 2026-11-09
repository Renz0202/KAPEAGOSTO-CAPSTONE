'use strict';
// Execute the real compact-list client with DOM fixtures and no account/item mutations.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let checks=0;
function check(value,label){assert.ok(value,label);checks++;console.log('PASS: '+label);}
class Element{
 constructor(dataset={}){this.dataset=dataset;this.events={};this.value='';this.hidden=false;this.disabled=false;this.textContent='';this.scrollTop=0;this.scrollLeft=0;}
 addEventListener(name,fn){(this.events[name]||=[]).push(fn);}
 fire(name,event={}){for(const fn of this.events[name]||[])fn(event);}
 focus(){this.owner.activeElement=this;}
 closest(selector){return selector==='[data-compact-details]'&&this.dataset.compactDetails!==undefined?this:null;}
}
class Select extends Element{
 constructor(name,key,values,dependent){super({compactFilter:key,...(dependent?{compactDependent:dependent}:{})});this.name=name;this.options=values.map(value=>({value,textContent:value||'All'}));}
 replaceChildren(...options){this.options=options;}
}
function fixture(key='items',url='',total=35){
 const document={activeElement:null},owned=node=>Object.assign(node,{owner:document});
 const search=owned(new Element());search.name=key==='items'?'item_search':key==='categories'?'category_search':'user_search';
 const filters=key==='items'?[owned(new Select('item_category','categoryId',['','1','2'])),owned(new Select('item_size','cupSize',['','12oz Hot','16oz','22oz'],'categoryId'))]:key==='categories'?[owned(new Select('category_usage','usage',['','linked','empty']))]:[owned(new Select('user_role','role',['','admin','manager','cashier'])),owned(new Select('user_status','status',['','active','inactive']))];
 const rows=Array.from({length:total},(_,index)=>{const id=index+1;return owned(new Element({rowId:String(id),usage:id%2?'linked':'empty',categoryId:id<=25?'1':'2',cupSize:id<=25?(id%2?'16oz':'22oz'):'12oz Hot',role:id<=25?'cashier':'manager',status:id%2?'active':'inactive',search:'Caf\u00e9 Item '+id+' CODE-'+id+' ID '+id+' customer'+id+'@test.local 0917'+String(id).padStart(6,'0')}));});
 const form=owned(new Element()),empty=owned(new Element()),pager=owned(new Element()),range=owned(new Element()),pageLabel=owned(new Element()),previous=owned(new Element()),next=owned(new Element()),reset=owned(new Element()),count=owned(new Element()),frame=owned(new Element()),close=owned(new Element());
 const detailFields=['title','recipe','email'].map(name=>owned(new Element({compactField:name})));
 const dialog=owned(new Element());dialog.open=false;dialog.querySelectorAll=()=>detailFields;dialog.querySelector=()=>close;dialog.showModal=()=>{dialog.open=true;};dialog.close=()=>{dialog.open=false;dialog.fire('close');};
 const root=owned(new Element({compactList:key,pageParam:key==='items'?'item_page':key==='categories'?'category_page':'user_page',listNoun:key,detailsDialog:'details'}));
 const nodes={'[data-compact-filters]':form,'[data-compact-search]':search,'[data-compact-empty]':empty,'[data-compact-pagination]':pager,'[data-compact-range]':range,'[data-compact-page-label]':pageLabel,'[data-compact-previous]':previous,'[data-compact-next]':next,'[data-compact-reset]':reset,'[data-compact-count]':count,'.management-table-frame':frame};
 root.querySelector=selector=>nodes[selector]||null;root.querySelectorAll=selector=>selector==='[data-compact-filter]'?filters:selector==='[data-compact-row]'?rows:[];
 document.querySelectorAll=()=>[root];document.getElementById=()=>dialog;
 const window={location:{href:'http://localhost/KAPEAGOSTO/public/'+key+'.php'+url},events:{},history:{replaceState(_a,_b,address){window.location.href=String(address);}},addEventListener(name,fn){(this.events[name]||=[]).push(fn);},fire(name){for(const fn of this.events[name]||[])fn();}};
 function Option(label,value){return {textContent:label,value};}
 vm.runInNewContext(fs.readFileSync('public/scripts/management-list.js','utf8'),{document,window,URL,Option});
 return {key,document,owned,root,search,filters,rows,form,empty,pager,range,pageLabel,previous,next,reset,count,frame,close,dialog,detailFields,window,visible:()=>rows.filter(row=>!row.hidden),params:()=>new URL(window.location.href).searchParams};
}
for(const key of ['items','users']){
 const ui=fixture(key),prefix=key+': ';
 check(ui.visible().length===10&&ui.visible()[0].dataset.rowId==='1'&&ui.previous.disabled&&!ui.next.disabled,prefix+'only ten records show on the first page');
 check(!ui.pager.hidden&&ui.range.textContent==='Showing 1\u201310 of 35 '+key&&ui.pageLabel.textContent==='Page 1 of 4',prefix+'pagination shows matching totals and page count');
 ui.frame.scrollTop=80;ui.frame.scrollLeft=20;ui.next.fire('click');
 check(ui.visible().length===10&&ui.visible()[0].dataset.rowId==='11'&&ui.params().get(ui.root.dataset.pageParam)==='2',prefix+'Next shows the next ten records and updates the address');
 check(ui.document.activeElement===ui.range&&ui.frame.scrollTop===0&&ui.frame.scrollLeft===0,prefix+'page navigation focuses the result range and resets the frame');
 const visited=new Set([...Array(10).keys()].map(i=>i+1));for(let page=2;page<=4;page++){ui.visible().forEach(row=>visited.add(Number(row.dataset.rowId)));if(page<4)ui.next.fire('click');}
 check(visited.size===35&&ui.visible().length===5&&ui.next.disabled,prefix+'all records remain reachable without duplicates');
 ui.previous.fire('click');check(ui.visible()[0].dataset.rowId==='21',prefix+'Previous returns to the preceding page');
 ui.filters[0].value=key==='items'?'1':'cashier';ui.filters[0].fire('change');
 check(ui.root.dataset.currentPage==='1'&&ui.count.textContent==='25 records',prefix+'filtering resets pagination across all records');
 ui.filters[1].value=key==='items'?'22oz':'inactive';ui.filters[1].fire('change');
 check(ui.count.textContent==='12 records'&&ui.visible().every(row=>Number(row.dataset.rowId)%2===0),prefix+'both filters combine correctly');
 ui.search.value='CAFE code-20';ui.search.fire('input');
 check(ui.visible().length===1&&ui.visible()[0].dataset.rowId==='20',prefix+'search matches multiple terms without case or accent sensitivity');
 ui.search.value='unmatched';ui.search.fire('input');check(ui.visible().length===0&&!ui.empty.hidden&&ui.previous.disabled&&ui.next.disabled&&ui.range.textContent==='Showing 0\u20130 of 0 '+key,prefix+'empty results are clear and navigation is disabled');
 let prevented=false;ui.search.fire('keydown',{key:'Escape',preventDefault(){prevented=true;}});check(prevented&&ui.search.value===''&&ui.count.textContent==='12 records',prefix+'Escape clears only the search and retains filters');
 ui.reset.fire('click');check(ui.visible().length===10&&ui.count.textContent==='35 records'&&ui.filters.every(field=>field.value==='')&&ui.document.activeElement===ui.search,prefix+'Reset clears filters and returns focus to search');
 ui.search.value='customer35@test.local';let submit=false;ui.form.fire('submit',{preventDefault(){submit=true;}});check(submit&&ui.visible()[0].dataset.rowId==='35',prefix+'hidden secondary data remains searchable through form submission');
 const button=ui.owned(new Element({compactDetails:JSON.stringify({title:'Safe title',recipe:'FRESH MILK: 125.5 ml\nCUP (22oz): 1 pcs',email:'<script>alert(1)</script>'})}));ui.root.fire('click',{target:button});
 check(ui.dialog.open&&ui.detailFields[1].textContent.includes('\nCUP (22oz)')&&ui.detailFields[2].textContent==='<script>alert(1)</script>',prefix+'details keep full recipe lines and insert all values as plain text');
 ui.close.fire('click');check(!ui.dialog.open&&ui.document.activeElement===button,prefix+'closing details returns focus to its opener');
 ui.window.location.href='http://localhost/'+key+'.php?'+ui.root.dataset.pageParam+'=2';ui.window.fire('popstate');check(ui.visible()[0].dataset.rowId==='11'&&ui.search.value==='',prefix+'browser navigation restores pagination and filters');
 const restored=fixture(key,'?'+(key==='items'?'item_category=1&item_size=22oz&item_page=2':'user_role=cashier&user_status=inactive&user_page=2')+'&other=keep');
 check(restored.visible().length===2&&restored.visible()[0].dataset.rowId==='22'&&restored.params().get('other')==='keep',prefix+'reload restores combined filters and page without losing unrelated URL state');
 const excessive=fixture(key,'?'+ui.root.dataset.pageParam+'=999');check(excessive.visible().length===5&&excessive.params().get(ui.root.dataset.pageParam)==='4',prefix+'excessive pages clamp to the last available page');
 const malformed=fixture(key,'?'+ui.root.dataset.pageParam+'=-2&'+(key==='items'?'item_category=unknown&item_size=wrong':'user_role=unknown&user_status=wrong'));check(malformed.visible().length===10&&malformed.filters.every(field=>field.value==='')&&!malformed.params().has(ui.root.dataset.pageParam),prefix+'invalid page and filter values normalize safely');
 const reopened=fixture(key,'?edit=32');check(reopened.visible().some(row=>row.dataset.rowId==='32')&&reopened.params().get(ui.root.dataset.pageParam)==='4',prefix+'validation redirects keep the reopened editor row visible');
 const noRows=fixture(key,'',0);check(!noRows.empty.hidden&&noRows.range.textContent==='Showing 0\u20130 of 0 '+key,prefix+'a completely empty list still has a useful result count');
}
const ui=fixture('items');ui.filters[1].value='22oz';ui.filters[0].value='2';ui.filters[0].fire('change');
check(ui.filters[1].options.map(option=>option.value).join(',')===',12oz Hot'&&ui.filters[1].value===''&&ui.count.textContent==='10 records','Item categories offer only their matching cup sizes and clear incompatible selections');
ui.filters[0].value='1';ui.filters[0].fire('change');check(ui.filters[1].options.map(option=>option.value).join(',')===',16oz,22oz','Returning to another category restores its available sizes');
const categoryUi=fixture('categories');
check(categoryUi.visible().length===10&&categoryUi.count.textContent==='35 records','Categories: shared pagination limits the first page to ten records');
categoryUi.next.fire('click');check(categoryUi.visible()[0].dataset.rowId==='11'&&categoryUi.params().get('category_page')==='2','Categories: Next retains its independent category page in the address');
categoryUi.filters[0].value='empty';categoryUi.filters[0].fire('change');check(categoryUi.count.textContent==='17 records'&&categoryUi.visible().every(row=>row.dataset.usage==='empty')&&categoryUi.root.dataset.currentPage==='1','Categories: the empty-category filter applies to all pages');
categoryUi.search.value='ID 30';categoryUi.search.fire('input');check(categoryUi.visible().length===1&&categoryUi.visible()[0].dataset.rowId==='30','Categories: name/ID search combines with the linked-items filter');
categoryUi.reset.fire('click');check(categoryUi.count.textContent==='35 records'&&categoryUi.filters[0].value==='','Categories: Reset restores the complete category list');
const categoryReload=fixture('categories','?category_usage=linked&category_page=2');check(categoryReload.visible().length===8&&categoryReload.visible()[0].dataset.rowId==='21'&&categoryReload.params().get('category_usage')==='linked','Categories: reload restores its usage filter and page');
console.log('All '+checks+' compact management interaction checks passed.');
