import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {SourceTextModule,createContext} from 'node:vm';
class Element extends EventTarget {
 constructor(tag='div'){super();this.tagName=tag.toUpperCase();this.style={};this.dataset={};this.children=[];this.value='2011';this.hidden=false;this.selectedOptions=[{textContent:'Literacy (%)'}];}
 append(...nodes){this.children.push(...nodes);for(const n of nodes){n.parentElement?.children.splice(n.parentElement.children.indexOf(n),1);n.parentElement=this;}}
 prepend(...nodes){this.children.unshift(...nodes);}
 before(){} after(){} remove(){} setAttribute(){} removeAttribute(){}
 replaceChildren(...nodes){this.children=nodes;}
 contains(node){return this.children.includes(node);}
 querySelector(){return null;}
}
const source=await readFile(new URL('../../public/js/census-year-comparison-20261017-zoom-v26.js',import.meta.url),'utf8');
async function mount(home){
 const surface=new Element();const metric=new Element('select');metric.value='literacy';const year=new Element('select');const doc=new Element();doc.body={classList:{contains:()=>home}};doc.hidden=false;
 doc.createElement=tag=>new Element(tag);doc.getElementById=id=>id==='india-map'?surface:null;
 const win=new Element();win.innerWidth=1440;const timers=[];
 const context=createContext({document:doc,window:win,Event,setTimeout:(_fn,delay)=>{timers.push(delay);return timers.length;},clearTimeout(){},cancelAnimationFrame(){},Date,performance});
 const module=new SourceTextModule(source,{context});await module.link(()=>{});await module.evaluate();module.namespace.yearPlayback(year,metric);
 return {surface,metric,year,win,timers};
}
test('homepage starts with a visible divider and internal year controls without comparison work',async()=>{
 const {surface,metric,win,timers}=await mount(true);assert.deepEqual(timers,[]);
 const footer=surface.children.find(e=>e.id==='census-comparison-footer');assert.equal(footer.dataset.interaction,'idle');
 const rail=surface.children.find(e=>e.id==='census-comparison-divider');assert.equal(rail.hidden,false);
 const event=new Event('census-map-delhi-position');event.detail={percent:36};win.dispatchEvent(event);assert.equal(rail.style.left,'36%');
 metric.dispatchEvent(new Event('change'));assert.deepEqual(timers,[]);assert.equal(rail.hidden,false);
});
test('other map pages keep their existing automatic comparison',async()=>{
 const {timers}=await mount(false);assert.deepEqual(timers,[150]);
});
