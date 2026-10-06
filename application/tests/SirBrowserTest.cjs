const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../public/js/sir-browser.js'),'utf8');
class Element {
    constructor(tag='div'){this.tag=tag;this.style={};this.children=[];this.listeners={};this.value='';this.disabled=false;this.hidden=false;this._text='';}
    append(...items){this.children.push(...items);}
    replaceChildren(...items){this.children=items;this._text='';}
    set textContent(value){this._text=String(value);this.children=[];}
    get textContent(){return this._text+this.children.map(item=>item.textContent).join('');}
    get options(){return this.children;}
    addEventListener(type,handler){(this.listeners[type] ||= []).push(handler);}
    emit(type,event={}){for(const handler of this.listeners[type] || []) handler(event);return this['on'+type]?.(event);}
    setAttribute(name,value){(this.attributes ||= {})[name]=value;}
    closest(){return this.header;}
    querySelector(){return this.arrow;}
    scrollIntoView(){this.scrolled=true;}
    click(){return this.onclick?.();}
}
class Option extends Element {constructor(label,value){super('option');this.textContent=label;this.value=value;}}
const settle=async()=>{for(let n=0;n<40;n++) await Promise.resolve();};
function setup(){
    const elements=new Map(),requests=[],timers=new Map();let timerId=0,override;
    const el=id=>{if(!elements.has(id))elements.set(id,new Element());return elements.get(id);};
    el('record-page-size').value='50';
    const sorts=['serial','age'].map(field=>{const button=new Element('button');button.dataset={sort:field};button.header=new Element('th');button.arrow=new Element('span');return button;});
    const edition='a'.repeat(64);
    const choices={periods:[{value:'revision:2026',label:'2026'}],states:[{value:'09',label:'UP'}],pcs:[{value:'09|26',label:'PC26'}],acs:[{value:'09|127',label:'AC127'}],stations:[{value:edition+':1',label:'Part1'}],editions:[{edition_key:edition,document_date:'2026-01-06',edition:'Draft',ac_code:'127',indexed_records:933,printed_electors:933,held_records_count:0,uncertain_records:14}],statistics:[]};
    const result=body=>{const page=body.page || 1,total=body.name || body.relative_name ? 1 : 933,per=body.per_page || 50;
        return {total,current_page:page,last_page:Math.ceil(total/per),from:(page-1)*per+1,to:Math.min(page*per,total),next_page_url:page*per<total?'next':null,summary:{total,male:total,female:0,age_groups:[]},data:[{serial:(page-1)*per+1,serial_verified:true,name:body.name || body.relative_name || 'Original name',relative_name:'Parent',relationship:'Father',age:30,gender:'Male',part:1,ac_code:'127',ac_name:'Pilibhit',year:2026,edition:'Draft',station:'School',pdf_url:'/pdf#page=3',pdf_page:3,source_url:'https://eci.gov.in/roll.pdf',extraction_status:'ocr_uncertain'}]};};
    const response=(data,status=200)=>({ok:status===200,status,json:async()=>data});
    const context={document:{getElementById:el,createElement:tag=>new Element(tag),querySelectorAll:()=>sorts,querySelector:()=>({content:'csrf'})},Option,AbortController,URLSearchParams,Blob,URL,Error,SyntaxError,
        setTimeout:(fn,ms)=>{const id=++timerId;timers.set(id,{fn,ms});return id;},clearTimeout:id=>timers.delete(id),fetch:(url,options={})=>{
            if(url.startsWith('/api/sir/editions'))return Promise.resolve(response(choices));
            const body=JSON.parse(options.body);requests.push(body);
            return override ? override(body,options) : Promise.resolve(response(result(body)));
        }};
    vm.runInNewContext(source,context);
    return {el,sorts,requests,result,response,setHandler:handler=>override=handler,flush:async ms=>{for(const [id,timer] of [...timers])if(timer.ms<=ms && timers.has(id)){timers.delete(id);timer.fn();}await settle();}};
}
test('repeated voter and relative searches update automatically; stale responses cannot replace newer results',async()=>{
    const h=setup();await settle();let resolveOld;
    h.setHandler(body=>body.name==='older' ? new Promise(resolve=>resolveOld=resolve) : Promise.resolve(h.response(h.result(body))));
    h.el('record-name').value='older';h.el('record-name').emit('input');await h.flush(500);
    h.el('record-name').value='newer';h.el('record-name').emit('input');await h.flush(500);
    assert.match(h.el('record-rows').textContent,/newer/);
    resolveOld(h.response(h.result({name:'older'})));await settle();assert.match(h.el('record-rows').textContent,/newer/);
    h.el('record-name').value='';h.el('record-relative').value='hari';h.el('record-relative').emit('input');await h.flush(500);
    assert.equal(h.requests.at(-1).relative_name,'hari');assert.match(h.el('record-status').textContent,/1 records match/);
    assert.doesNotMatch(h.el('record-rows').textContent,/OCR/);
});
test('a stalled search times out and retry completes without leaving Loading',async()=>{
    const h=setup();await settle();
    h.setHandler((body,options)=>new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>reject(Object.assign(new Error('Aborted'),{name:'AbortError'})))));
    h.el('record-name').value='test';h.el('record-name').emit('input');await h.flush(500);await h.flush(20000);
    assert.match(h.el('record-status').textContent,/took too long/);assert.equal(h.el('record-retry').hidden,false);
    h.setHandler(body=>Promise.resolve(h.response(h.result(body))));await h.el('record-retry').click();await settle();
    assert.match(h.el('record-status').textContent,/1 records match/);assert.equal(h.el('record-retry').hidden,true);
});
test('first and last controls at both ends navigate to the correct page',async()=>{
    const h=setup();await settle();assert.equal(h.el('record-first-top').disabled,true);
    await h.el('record-last-top').click();await settle();assert.equal(h.requests.at(-1).page,19);assert.equal(h.el('record-last').disabled,true);
    await h.el('record-first').click();await settle();assert.equal(h.requests.at(-1).page,1);assert.equal(h.el('record-first-top').disabled,true);
    assert.equal(h.el('records-top').scrolled,true);
});
test('non-JSON server failures show a plain retry message',async()=>{
    const h=setup();await settle();h.setHandler(()=>Promise.resolve({ok:false,status:500,json:async()=>{throw new Error('HTML body');}}));
    h.el('record-name').value='again';h.el('record-name').emit('input');await h.flush(500);
    assert.match(h.el('record-status').textContent,/could not be loaded/);assert.equal(h.el('record-retry').hidden,false);
});

test('summary cards preserve counts and age bars use a common scale',async()=>{
    const h=setup();await settle();h.setHandler(body=>Promise.resolve(h.response({...h.result(body),summary:{total:20,male:12,female:8,third_gender:0,unknown_gender:0,uncertain:2,age_groups:[{label:'18–30',count:10},{label:'31–40',count:5},{label:'Above 80',count:0}]}})));
    h.el('record-name').value='summary';h.el('record-name').emit('input');await h.flush(500);
    assert.equal(h.el('gender-summary').children.length,6);assert.match(h.el('gender-summary').textContent,/Total voters20Male12Female8/);
    const bars=h.el('age-summary').children;assert.equal(bars.length,3);assert.equal(bars[0].children[1].children[0].style.width,'100%');assert.equal(bars[1].children[1].children[0].style.width,'50%');assert.equal(bars[2].children[1].children[0].style.width,'0%');
    h.el('methodology-link').click();assert.equal(h.el('sir-methodology').open,true);
});

test('heading clicks toggle ordering, restart at page one and preserve sort on navigation',async()=>{
    const h=setup();await settle();await h.el('record-last-top').click();await settle();
    h.sorts[1].emit('click');await settle();assert.equal(h.requests.at(-1).sort,'age');assert.equal(h.requests.at(-1).direction,'asc');assert.equal(h.requests.at(-1).page,1);assert.equal(h.sorts[1].header.attributes['aria-sort'],'ascending');
    h.sorts[1].emit('click');await settle();assert.equal(h.requests.at(-1).direction,'desc');assert.equal(h.sorts[1].arrow.textContent,'↓');
    await h.el('record-last-top').click();await settle();assert.equal(h.requests.at(-1).sort,'age');assert.equal(h.requests.at(-1).page,19);
    h.sorts[0].emit('click');await settle();assert.equal(h.requests.at(-1).direction,'asc');assert.equal(h.sorts[1].header.attributes['aria-sort'],'none');
});
