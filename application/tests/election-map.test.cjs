const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
class Element {
    constructor(){this.children=[];this.dataset={};this.attributes={};this.events={};this.classes=new Set();this.classList={add:x=>this.classes.add(x),contains:x=>this.classes.has(x)};this.style={setProperty:(k,v)=>{this.style[k]=v;}};}
    append(...nodes){nodes.forEach(n=>{this.children=this.children.filter(c=>c!==n);this.children.push(n);});}
    replaceChildren(...nodes){this.children=[...nodes];}
    setAttribute(k,v){this.attributes[k]=v;}
    getAttribute(k){return this.attributes[k];}
    addEventListener(k,v){this.events[k]=v;}
    getBoundingClientRect(){return {width:600};}
}
const shape=(id,name,code,state='Uttar Pradesh')=>({id,properties:{kind:'pc',name,code,state},geometry:{type:'Polygon',coordinates:[[[80,27],[81,27],[81,28],[80,27]]]}});
const record=(id,name,code,party='BJP',state='Uttar Pradesh')=>({id,name,code,party,state,year:2024,url:`https://pollmedia.example/india/constituency?edition=${'a'.repeat(24)}&code=${code}&kind=pc&state=${encodeURIComponent(state)}&name=${encodeURIComponent(name)}`,winner:'Recorded winner'});
async function setup(features,records,options={}){
    const selectors=['svg','[data-map-seats]','[data-map-status]','[data-map-selection]','[data-map-legend]','[data-map-tooltip]'];
    const nodes=Object.fromEntries(selectors.map(s=>[s,new Element()])),list=new Element();
    const root=new Element();root.dataset={kind:'pc',mode:options.mode||'results',labels:options.labels?'true':'false',source:'/source',results:'/results',finder:'/india/elections/constituencies',state:options.state||'Uttar Pradesh',selected:options.selected||'',selectedCode:options.code||'',year:'1957'};root.querySelector=s=>nodes[s];root.querySelectorAll=()=>[];
    const window={},location={href:'https://pollmedia.example/',origin:'https://pollmedia.example',assign:url=>{location.assigned=url;}};
    vm.runInNewContext(readFileSync(path.join(__dirname,'../public/js/election-map.js'),'utf8'),{window,location,URL,console,document:{readyState:'complete',querySelectorAll:()=>[root],createElement:()=>new Element(),createElementNS:()=>new Element(),createTextNode:text=>({textContent:text})},fetch:async url=>({ok:!(options.failure===url),json:async()=>url==='/source'?{features}:{records,colors:{BJP:'#ff750f',INC:'#234da0'}}})});
    await new Promise(resolve=>setImmediate(resolve));
    return {nodes,list,root,window,location};
}
test('source seat code never replaces official extraction row; selected seat keeps party colour and opens exact year',async()=>{
    const n=await setup([shape('pc-1','Pilibhit',26)],[record('a:371','Pilibhit',371)],{selected:'Pilibhit',code:'371'});
    const seat=n.nodes.svg.children[0];assert.equal(seat.attributes.fill,'#ff750f');assert(seat.classList.contains('is-selected'));seat.events.click();assert.match(n.location.assigned,/code=371/);assert.match(n.location.assigned,/edition=aaaa/);assert.equal(n.list.children.length,0);
});
test('historical unmatched seats remain available in the constituency dropdown without a duplicate list',async()=>{
    const n=await setup([shape('pc-1','Modern name',26)],[record('a:10','Old name',10,'INC')],{selected:'Old name',code:'10'});
    assert.equal(n.nodes.svg.children[0].attributes.fill,'var(--palette-d5dfd5)');assert.equal(n.nodes['[data-map-seats]'].children[1].selected,true);assert.match(n.nodes['[data-map-seats]'].children[1].textContent,/Old name/);n.nodes['[data-map-seats]'].value='a:10';n.nodes['[data-map-seats]'].events.change();assert.match(n.location.assigned,/code=10/);
});
test('same name in another state and duplicate extraction names never receive guessed winners',async()=>{
    const n=await setup([shape('pc-1','Same name',1)],[record('a:1','Same name',1,'BJP','Bihar'),record('a:2','Same name',2),record('a:3','Same name',3,'INC')]);
    assert.equal(n.nodes.svg.children[0].attributes.fill,'var(--palette-d5dfd5)');n.nodes.svg.children[0].events.click();assert.match(n.location.assigned,/constituencies\?/);assert.doesNotMatch(n.location.assigned,/edition=/);assert.equal(n.nodes['[data-map-seats]'].children.length,4);
});
test('duplicate source code and name cannot establish two geographic joins to one result',async()=>{
    const r=record('a:1','Same name',10);r.official_code=1;
    const n=await setup([shape('pc-1','Same name',1),shape('pc-2','Same name',1)],[r]);
    assert(n.nodes.svg.children.every(p=>p.attributes.fill==='var(--palette-d5dfd5)'));assert.equal(n.nodes['[data-map-seats]'].children.length,2);
});
test('failed geometry fetch retains the constituency dropdown',async()=>{
    const n=await setup([], [record('a:1','Pilibhit',371)],{failure:'/source'});
    assert.equal(n.nodes['[data-map-seats]'].disabled,false);assert.equal(n.nodes['[data-map-seats]'].children.length,2);assert.match(n.nodes['[data-map-status]'].textContent,/Map unavailable/);
});
test('failed results API does not hide geometry or invent party colours',async()=>{
    const n=await setup([shape('pc-1','Pilibhit',26)],[],{failure:'/results'});
    assert.equal(n.nodes.svg.children.length,1);assert.equal(n.nodes.svg.children[0].attributes.fill,'var(--palette-d5dfd5)');assert.match(n.nodes['[data-map-status]'].textContent,/neutral colours/);
});
test('missing names cannot match data; historical SC/ST suffixes are documented approximate name matches',async()=>{
    const n=await setup([shape('pc-1','',1),shape('pc-2','Example (SC)',2)],[record('a:2','Example',99)]);
    assert.equal(n.nodes.svg.children[0].attributes.fill,'var(--palette-d5dfd5)');assert.equal(n.nodes.svg.children[1].attributes.fill,'#ff750f');
});
test('reported party labels use configured colours, unknown parties use stable theme colours',async()=>{
    const n=await setup([],[]),color=n.window.pollmediaMapMatching.partyColor;
    assert.equal(color('Indian National Congress',{INC:'#123456'}),'#123456');assert.equal(color('New party',{}),color('New party',{}));assert.notEqual(color('INC',{}),undefined);
});
test('constituency locator uses one muted colour and opens profiles without election years',async()=>{
    const n=await setup([shape('pc-1','Pilibhit',26),shape('pc-2','Bareilly',25)],[record('a:371','Pilibhit',371),record('a:370','Bareilly',370,'INC')],{mode:'focus',selected:'Pilibhit',code:'371'});
    const selected=n.nodes.svg.children.find(seat=>seat.classList.contains('is-selected'));
    const other=n.nodes.svg.children.find(seat=>!seat.classList.contains('is-selected'));
    assert.equal(selected.attributes.fill,'#e4ebed');
    assert(n.nodes.svg.children.some(node=>node.attributes.class==='election-map-current-label' && /Pilibhit.*Lok Sabha/.test(node.textContent)));
    assert.equal(other.attributes.fill,'#e4ebed');
    assert.match(n.nodes['[data-map-legend]'].children[0].children[1].textContent,/Selected constituency/);
    other.events.pointerenter({clientX:100,clientY:200,currentTarget:other});
    assert.match(n.nodes['[data-map-tooltip]'].textContent,/Bareilly/);
    assert.doesNotMatch(n.nodes['[data-map-tooltip]'].textContent,/2024|INC|Recorded winner/);
    other.events.click();assert.doesNotMatch(n.location.assigned,/edition=|code=/);assert.match(n.location.assigned,/name=Bareilly/);
    selected.events.click();assert.doesNotMatch(n.location.assigned,/edition=|code=/);assert.match(n.location.assigned,/name=Pilibhit/);
});
test('hover and keyboard focus show a visible seat tooltip; missing results are explicit',async()=>{
    const n=await setup([shape('pc-1','Pilibhit',26)],[record('a:1','Pilibhit',371)]),seat=n.nodes.svg.children[0],tooltip=n.nodes['[data-map-tooltip]'];
    seat.events.pointerenter({clientX:100,clientY:200,currentTarget:seat});assert.equal(tooltip.hidden,false);assert.match(tooltip.textContent,/Pilibhit.*\n.*2024.*\n.*BJP.*Recorded winner/);seat.events.pointerleave();assert.equal(tooltip.hidden,true);
    const missing=await setup([shape('pc-2','Historical seat',27)],[],{selected:'Historical seat'}),path=missing.nodes.svg.children[0];
    path.events.focus({currentTarget:path});assert.match(missing.nodes['[data-map-tooltip]'].textContent,/1957.*\nResult not available/);assert(path.classList.contains('is-selected'));assert.equal(path.attributes['aria-current'],'true');
});
test('historical state name aliases preserve approximate high-level map matching',async()=>{
    const n=await setup([shape('pc-1','Historical seat',1,'Tamil Nadu')],[record('a:1','Historical seat',16,'INC','Madras')],{state:'Tamil Nadu'});
    assert.equal(n.nodes.svg.children[0].attributes.fill,'#234da0');
});


test('state maps show non-overlapping seat names without blocking clickable shapes',async()=>{
    const n=await setup([shape('pc-1','Pilibhit',26),shape('pc-2','Bareilly',25)],[record('a:371','Pilibhit',371),record('a:370','Bareilly',370)],{mode:'focus',labels:true});
    const names=n.nodes.svg.children.find(node=>node.attributes.class==='election-map-names');
    assert.equal(names.attributes['pointer-events'],'none');
    assert.equal(names.children.length,1);
    assert.equal(names.children[0].textContent,'Pilibhit');
    const seat=n.nodes.svg.children.find(node=>node.attributes.class==='election-map-seat');
    seat.events.click();assert.match(n.location.assigned,/name=Pilibhit/);
});
