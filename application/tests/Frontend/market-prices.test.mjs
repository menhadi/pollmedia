import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {SourceTextModule,createContext} from 'node:vm';
class Element extends EventTarget{
 constructor(tag='div',text=''){super();this.tagName=tag;this.textContent=text;this.children=[];this.dataset={};this.hidden=false;this.isConnected=true;this.value='';}
 append(...children){this.children.push(...children);}
 replaceChildren(...children){this.children=children;this.textContent='';}
 setAttribute(){}
 scrollIntoView(){}
}
const settle=()=>new Promise(resolve=>setImmediate(resolve));
test('changing crop immediately removes old prices and history, including after request failure',async()=>{
 const status=new Element(),target=new Element(),panel=new Element();panel.querySelector=s=>s==='[data-market-status]'?status:target;
 let rejectSecond;let requests=0;
 const context=createContext({Event,URL,URLSearchParams,document:{createElement:tag=>new Element(tag),createTextNode:text=>new Element('text',text)},Option:class extends Element{constructor(text,value){super('option',text);this.value=value;}},
 fetch:async()=>{if(++requests===2)return new Promise((_resolve,reject)=>{rejectSecond=reject;});return {ok:true,json:async()=>({records:[{market:'Pilibhit',commodity:'Paddy',variety:'Common',grade:'FAQ',unit:'Quintal',min:1800,modal:1850,max:1900,date:'2026-10-21'}],catalog:[{name:'Paddy'},{name:'Wheat'}],next_offset:1000})};}});
 const module=new SourceTextModule(await readFile(new URL('../../public/js/market-prices.js',import.meta.url),'utf8'),{context});await module.link(()=>{});await module.evaluate();
 await module.namespace.loadMarkets(panel,{dataset:{language:'en',state:'9',district:'173',marketUrl:'https://pollmedia.org/my-place/markets',historyUrl:'https://pollmedia.org/my-place/market-history'}},(en)=>en);
 const [controls,list,history]=target.children;const select=controls.children[0].children[0];const more=controls.children[1];assert.equal(list.children.length,1);history.hidden=false;
 select.value='Wheat';select.dispatchEvent(new Event('change'));await settle();
 assert.equal(list.children.length,0);assert.equal(history.hidden,true);assert.equal(more.hidden,true);assert.equal(select.disabled,true);
 rejectSecond(new Error('feed unavailable'));await settle();assert.match(status.textContent,/Prices could not load/);assert.equal(list.children.length,0);assert.equal(select.disabled,false);
});

test('verified history renders yearly chart and prior-year comparison',async()=>{
 const status=new Element(),target=new Element(),panel=new Element();panel.querySelector=s=>s==='[data-market-status]'?status:target;
 const data={months:[{month:'2026-09',value:2559.92,observations:46,days:25},{month:'2026-10',value:2563.67,observations:27,days:18}],reason:null,unit:'Quintal',comparison:{month:'2026-10',prior_month:'2025-10',through_day:28,current:{value:2563.67,observations:27,days:18},previous:{value:2587.81,observations:37,days:18},previous_full_month:{value:2592.24,observations:45,days:21},change_percent:-0.93}};
 const context=createContext({Event,URL,URLSearchParams,document:{createElement:tag=>new Element(tag),createElementNS:(_ns,tag)=>new Element(tag),createTextNode:text=>new Element('text',text)},Option:class extends Element{constructor(text,value){super('option',text);this.value=value;}},fetch:async url=>({ok:true,json:async()=>String(url).includes('market-history')?data:{records:[{market:'Bishalpur APMC',commodity:'Wheat',variety:'Dara',grade:'FAQ',unit:'Quintal',min:2550,modal:2550,max:2550,date:'2026-10-21'}],catalog:[{name:'Wheat'}],next_offset:null}})});
 const module=new SourceTextModule(await readFile(new URL('../../public/js/market-prices.js',import.meta.url),'utf8'),{context});await module.link(()=>{});await module.evaluate();await module.namespace.loadMarkets(panel,{dataset:{language:'en',state:'9',district:'173',marketUrl:'https://pollmedia.org/my-place/markets',historyUrl:'https://pollmedia.org/my-place/market-history'}},en=>en);
 const [controls,list,history]=target.children;
 const find=(node,predicate)=>predicate(node)?node:node.children.map(child=>find(child,predicate)).find(Boolean);
 find(list,node=>node.tagName==='button').dispatchEvent(new Event('click'));await settle();await settle();
 const text=node=>node.textContent+node.children.map(text).join(' ');assert.match(text(history),/2,563.67/);assert.match(text(history),/2,587.81/);assert.match(text(history),/-0.93%/);assert.ok(find(history,node=>node.tagName==='svg'));assert.equal(find(history,node=>node.tagName==='details'),undefined);const point=find(history,node=>node.tagName==='circle');point.dispatchEvent(new Event('pointerenter'));assert.match(text(history),/Sept? 2026: 2,559.92/);point.dispatchEvent(new Event('focus'));assert.match(text(history),/Sept? 2026: 2,559.92/);const svg=find(history,node=>node.tagName==='svg');svg.getBoundingClientRect=()=>({left:0,width:640});const hover=new Event('pointermove');Object.defineProperty(hover,'clientX',{value:154});svg.dispatchEvent(hover);assert.match(text(history),/Oct 2026: 2,563.67/);
});

test('a daily revision replaces a same-date archive row when loading another page',async()=>{
 const status=new Element(),target=new Element(),panel=new Element();panel.querySelector=s=>s==='[data-market-status]'?status:target;let count=0;
 const row={market:'Pilibhit APMC',commodity:'Wheat',variety:'Dara',grade:'FAQ',unit:'Quintal',min:2500,modal:2500,max:2500,date:'2026-10-27',source:'historical'};
 const context=createContext({Event,URL,URLSearchParams,document:{createElement:tag=>new Element(tag),createTextNode:text=>new Element('text',text)},Option:class extends Element{constructor(text,value){super('option',text);this.value=value;}},fetch:async()=>({ok:true,json:async()=>({records:[++count===1?row:{...row,min:2600,modal:2600,max:2600,source:'daily'}],catalog:[{name:'Wheat'}],next_offset:count===1?1000:null,partial_sources:count===2})})});
 const module=new SourceTextModule(await readFile(new URL('../../public/js/market-prices.js',import.meta.url),'utf8'),{context});await module.link(()=>{});await module.evaluate();await module.namespace.loadMarkets(panel,{dataset:{language:'en',state:'9',district:'173',marketUrl:'https://pollmedia.org/my-place/markets',historyUrl:'https://pollmedia.org/my-place/market-history'}},en=>en);
 const [controls,list]=target.children;controls.children[1].dispatchEvent(new Event('click'));await settle();await settle();const text=node=>node.textContent+node.children.map(text).join(' ');
 assert.match(text(list),/2,600/);assert.doesNotMatch(text(list),/2,500/);assert.match(text(status),/Some reports are temporarily unavailable/);
});

test('rate-limited history explains waiting in English and Hindi instead of claiming missing data',async()=>{
 for(const language of ['en','hi']){
  const status=new Element(),target=new Element(),panel=new Element();panel.querySelector=s=>s==='[data-market-status]'?status:target;
  const row={market:'Pilibhit APMC',commodity:'Wheat',variety:'Dara',grade:'FAQ',unit:'Quintal',min:2500,modal:2500,max:2500,date:'2026-10-27'};
  const context=createContext({Event,URL,URLSearchParams,document:{createElement:tag=>new Element(tag),createTextNode:text=>new Element('text',text)},Option:class extends Element{constructor(text,value){super('option',text);this.value=value;}},fetch:async url=>String(url).includes('market-history')?{ok:false,status:429}:{ok:true,json:async()=>({records:[row],catalog:[{name:'Wheat'}],next_offset:null})}});
  const module=new SourceTextModule(await readFile(new URL('../../public/js/market-prices.js',import.meta.url),'utf8'),{context});await module.link(()=>{});await module.evaluate();await module.namespace.loadMarkets(panel,{dataset:{language,state:'9',district:'173',marketUrl:'https://pollmedia.org/my-place/markets',historyUrl:'https://pollmedia.org/my-place/market-history'}},(en,hi)=>language==='hi'?hi:en);
  const [controls,list,history]=target.children;const find=(node,predicate)=>predicate(node)?node:node.children.map(child=>find(child,predicate)).find(Boolean);find(list,node=>node.tagName==='button').dispatchEvent(new Event('click'));await settle();await settle();const text=node=>node.textContent+node.children.map(text).join(' ');assert.match(text(history),language==='hi'?/एक मिनट बाद/:/wait about a minute/);
 }
});
