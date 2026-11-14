import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {SourceTextModule,createContext} from 'node:vm';
class Element extends EventTarget {
 constructor(tag='div'){super();this.tagName=tag.toUpperCase();this.style={};this.dataset={};this.children=[];this.value='2011';this.hidden=false;this.selectedOptions=[{textContent:'Literacy (%)'}];}
 append(...nodes){for(const n of nodes){if(n.parentElement){const i=n.parentElement.children.indexOf(n);if(i>=0)n.parentElement.children.splice(i,1);}this.children.push(n);n.parentElement=this;}}
 prepend(...nodes){for(const n of nodes.reverse()){if(n.parentElement){const i=n.parentElement.children.indexOf(n);if(i>=0)n.parentElement.children.splice(i,1);}this.children.unshift(n);n.parentElement=this;}}
 before(){} after(){} remove(){} setAttribute(){} removeAttribute(){}
 replaceChildren(...nodes){this.children=nodes;}
 contains(node){return this.children.includes(node);}
 querySelector(){return null;}
}
const source=await readFile(new URL('../../public/js/census-year-comparison-20261017-zoom-v26.js',import.meta.url),'utf8');
async function mount(home){
 const surface=new Element();const metric=new Element('select');metric.value='literacy';const year=new Element('select');const doc=new Element();doc.body={classList:{contains:()=>home}};doc.hidden=false;
 doc.createElement=tag=>new Element(tag);doc.getElementById=id=>id==='india-map'?surface:null;
 const win=new Element();win.innerWidth=1440;const timers=[];let frames=0;win.addEventListener('census-map-capture',event=>{const canvas=new Element('canvas');canvas.dataset.snapshotYear=year.value;event.detail.resolve(canvas);});
 const context=createContext({document:doc,window:win,Event,CustomEvent:class extends Event{constructor(name,options){super(name,options);this.detail=options?.detail;}},requestAnimationFrame:()=>{frames++;return frames;},setTimeout:(fn,delay)=>{timers.push({fn,delay});return timers.length;},clearTimeout(){},cancelAnimationFrame(){},Date,performance});
 const module=new SourceTextModule(source,{context});await module.link(()=>{});await module.evaluate();module.namespace.yearPlayback(year,metric);
 return {surface,metric,year,win,timers,frames:()=>frames};
}
test('homepage prepares both year colors but does not animate until clicked',async()=>{
 const {surface,win,timers,frames}=await mount(true);assert.equal(timers[0].delay,150);
 const footer=surface.children.find(e=>e.id==='census-comparison-footer');assert.equal(footer.dataset.interaction,'idle');
 const rail=surface.children.find(e=>e.id==='census-comparison-divider');assert.equal(rail.hidden,false);
 const event=new Event('census-map-delhi-position');event.detail={percent:36};win.dispatchEvent(event);assert.equal(rail.style.left,'36%');
 await timers[0].fn();
 const plane=surface.children.find(e=>e.id==='census-year-comparison');assert.equal(plane.hidden,false);
 assert.deepEqual(plane.children.map(e=>e.dataset.snapshotYear).sort(),['2001','2011']);assert.equal(frames(),0);
 const heading=surface.children.find(e=>e.id==='census-comparison-heading');assert.equal(heading.children.length,2);assert.match(heading.children[0].children[0].textContent,/2001.*Literacy/);
});
test('other map pages keep their existing automatic comparison',async()=>{
 const {timers}=await mount(false);assert.equal(timers[0].delay,150);
});
