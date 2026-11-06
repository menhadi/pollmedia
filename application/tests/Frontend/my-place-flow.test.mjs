import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {SourceTextModule, SyntheticModule, createContext} from 'node:vm';

class Element extends EventTarget {
    constructor(){super();this.dataset={};this.hidden=false;this.textContent='';this.children=[];}
    querySelector(){return null;}
    querySelectorAll(){return [];}
    replaceChildren(){this.children=[];this.innerHTML='';}
    focus(){}
    reportValidity(){return true;}
    click(){this.dispatchEvent(new Event('click'));}
}
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return {promise,resolve};};
const settle=()=>new Promise(resolve=>setImmediate(resolve));
async function fixture({hierarchyDeferred=false,locateDeferred=false,summaryDeferred=false,locateStatus=200}={}){
    const form=new Element(),result=new Element(),status=new Element(),choices=new Element(),detect=new Element(),manual=new Element(),root=new Element();
    root.dataset={language:'en',options:'https://pollmedia.org/my-place/options',locate:'https://pollmedia.org/my-place/locate',summary:'https://pollmedia.org/my-place/summary',initial:'{}'};
    const selected={state:'9',district:'173',subdistrict:'790',village:'131596'};
    const controls={'[data-status]':status,'[data-hierarchy]':form,'[data-place-result]':result,'[data-choices]':choices,'[data-detect]':detect,'[data-manual]':manual};
    root.querySelector=selector=>controls[selector]||null;
    const sets=[],urls=[],requests=[],positions=[],ready=deferred(),located=deferred(),summary=deferred();
    const languages=[{href:'https://pollmedia.org/my-place?state=29&district=525&lang=en'},{href:'https://pollmedia.org/my-place?state=29&district=525&lang=hi'}];
    const hierarchy={fields:{state:new Element()},set:async value=>{sets.push(value);Object.assign(selected,value);}};
    if(!hierarchyDeferred)ready.resolve(hierarchy);
    const context=createContext({Event,AbortController,AbortSignal,CustomEvent:class extends Event {constructor(type,options){super(type);this.detail=options.detail;}},URL,URLSearchParams,
        document:{querySelector:selector=>selector==='#my-place-dynamic'?root:{content:'test-token'},querySelectorAll:()=>languages,createElement:()=>new Element()},
        navigator:{permissions:{query:async()=>({state:'prompt'})},geolocation:{getCurrentPosition:(success,error)=>positions.push({success,error})}},
        location:{origin:'https://pollmedia.org'},history:{replaceState:(_state,_title,url)=>urls.push(url)},localStorage:{getItem:()=>null,setItem(){},removeItem(){}},
        FormData:class {constructor(){this.rows=Object.entries(selected);}[Symbol.iterator](){return this.rows[Symbol.iterator]();}},
        fetch:async(url,options)=>{requests.push({url:String(url),options});if(String(url).includes('/locate'))return locateDeferred?located.promise:{ok:locateStatus===200,status:locateStatus,json:async()=>({choices:[{name:'Jatipur',...selected}]})};return summaryDeferred?summary.promise:{ok:true,text:async()=>'<section>Jatipur</section>'};}
    });
    const source=await readFile(new URL('../../public/js/my-place-dynamic.js',import.meta.url),'utf8');
    const module=new SourceTextModule(source,{context});
    await module.link(specifier=>new SyntheticModule(specifier.includes('place-hierarchy')?['placeHierarchy']:['mountHub'],function(){this.setExport(specifier.includes('place-hierarchy')?'placeHierarchy':'mountHub',specifier.includes('place-hierarchy')?()=>ready.promise:()=>{});},{context}));
    await module.evaluate();await settle();
    return {form,result,status,detect,manual,sets,urls,requests,positions,ready,located,summary,hierarchy,languages};
}

test('manual selection renders the chosen village in the dashboard URL',async()=>{
    const f=await fixture();f.form.dispatchEvent(new Event('change'));f.form.dispatchEvent(new Event('submit'));await settle();
    assert.match(f.urls[0],/^\/my-place\?.*state=9&district=173&subdistrict=790&village=131596/);
    assert.equal(f.result.innerHTML,'<section>Jatipur</section>');
    assert.match(f.languages[1].href,/state=9&district=173&subdistrict=790&village=131596&lang=hi/);
    assert.match(f.languages[0].href,/village=131596&lang=en/);
});
test('a late GPS response cannot replace a manual choice',async()=>{
    const f=await fixture({locateDeferred:true});f.detect.click();const completed=f.positions[0].success({coords:{latitude:28.5,longitude:79.8,accuracy:10}});
    f.form.dispatchEvent(new Event('change'));f.located.resolve({ok:true,json:async()=>({choices:[{state:'9',district:'173',village:'131596'}]})});await completed;
    assert.deepEqual(f.sets,[]);assert.deepEqual(f.urls,[]);assert.match(f.status.textContent,/Choose your place/);
});
test('GPS opens the confirmed place without waiting for manual dropdowns',async()=>{
    const f=await fixture({hierarchyDeferred:true});f.detect.click();const completed=f.positions[0].success({coords:{latitude:28.5,longitude:79.8,accuracy:10}});await settle();
    await completed;assert.equal(f.sets.length,0);assert.match(f.urls[0],/village=131596/);assert.equal(f.form.hidden,true);
    f.ready.resolve(f.hierarchy);
});
test('opening manual selection cancels an earlier summary response',async()=>{
    const f=await fixture({summaryDeferred:true});f.form.dispatchEvent(new Event('submit'));f.manual.click();
    f.summary.resolve({ok:true,text:async()=>'<section>Previous place</section>'});await settle();
    assert.equal(f.form.hidden,false);assert.deepEqual(f.urls,[]);assert.notEqual(f.result.innerHTML,'<section>Previous place</section>');
});
test('denied GPS keeps a clear manual alternative and sends no coordinates',async()=>{
    const f=await fixture();f.detect.click();f.positions[0].error();
    assert.equal(f.form.hidden,true);assert.equal(f.requests.length,0);assert.match(f.status.textContent,/choose your place manually/);
    f.manual.click();await settle();assert.equal(f.form.hidden,false);
});

test('switching to GPS hides manual controls immediately and manual cancels the lookup',async()=>{
    const f=await fixture({locateDeferred:true});f.manual.click();await settle();assert.equal(f.form.hidden,false);
    f.detect.click();assert.equal(f.form.hidden,true);
    const finished=f.positions[0].success({coords:{latitude:28.5,longitude:79.8,accuracy:500}});
    f.manual.click();assert.equal(f.form.hidden,false);assert.equal(f.requests[0].options.signal.aborted,true);assert.equal(f.detect.disabled,false);
    f.located.resolve({ok:true,json:async()=>({choices:[]})});await finished;assert.deepEqual(f.urls,[]);
});

test('expired sessions explain how to recover and release the GPS button',async()=>{
    const f=await fixture({locateStatus:419});f.detect.click();await f.positions[0].success({coords:{latitude:28.5,longitude:79.8,accuracy:500}});
    assert.match(f.status.textContent,/session expired/);assert.equal(f.detect.disabled,false);assert.deepEqual(f.urls,[]);
});

test('permission denial tells the user how to enable location',async()=>{
    const f=await fixture();f.detect.click();f.positions[0].error({code:1});assert.match(f.status.textContent,/browser site settings/);assert.equal(f.detect.disabled,false);
});
