import {filterFeatures} from './rampur-district-map.js';
const section=document.querySelector('[data-source-map]');
const textNode=(tag,text)=>{const node=document.createElement(tag);node.textContent=text;return node;};
async function start(){
    const response=await fetch(section.dataset.sourceMap);if(!response.ok)throw new Error('Source map unavailable');
    const map=await response.json(),svg=document.getElementById('village-map'),detail=document.getElementById('place-detail'),select=document.getElementById('map-subdistrict'),search=document.getElementById('map-search');
    const colours=['#547ae5','#16a596','#a06ad0','#e4a23e','#db6c8a','#41a8c5'];
    const names=[...new Set(map.features.map(f=>f.properties.subdistrict))].sort(),palette=new Map(names.map((name,index)=>[name,colours[index%colours.length]]));
    for(const name of names){const option=textNode('option',name);option.value=name;select.append(option);const label=textNode('span',name),swatch=textNode('i','');swatch.style.setProperty('--swatch',palette.get(name));label.prepend(swatch);document.getElementById('map-legend').append(label);}
    const rings=g=>g.type==='Polygon'?g.coordinates:g.coordinates.flat();
    const [west,south,east,north]=map.features.flatMap(f=>rings(f.geometry)).flat().reduce((b,[x,y])=>[Math.min(b[0],x),Math.min(b[1],y),Math.max(b[2],x),Math.max(b[3],y)],[Infinity,Infinity,-Infinity,-Infinity]);
    const cosine=Math.cos((north+south)*Math.PI/360),scale=Math.min(700/((east-west)*cosine),460/(north-south));
    const project=([x,y])=>[(760-(east-west)*cosine*scale)/2+(x-west)*cosine*scale,(540-(north-south)*scale)/2+(north-y)*scale];
    const node=(tag,attrs={})=>{const n=document.createElementNS(svg.namespaceURI,tag);for(const [k,v]of Object.entries(attrs))n.setAttribute(k,v);return n;};
    const group=node('g'),paths=new Map();svg.append(group);
    const choose=f=>{for(const [id,path]of paths)path.classList.toggle('selected',id===f.id);detail.replaceChildren(textNode('h3',f.properties.name),textNode('p',`Source code: ${f.properties.source_code||'Missing'} · Source subdistrict: ${f.properties.subdistrict}`),textNode('p',f.properties.status==='ambiguous'?'Duplicate source code: multiple shapes need review.':'Map-to-Census code link pending.'),textNode('p','Census population: link pending. Boundary edition is unverified.'));const link=textNode('a','Survey of India source ↗');link.href=map.metadata.source;detail.append(link);};
    for(const f of map.features){const path=node('path',{d:rings(f.geometry).map(r=>r.map((p,i)=>`${i?'L':'M'}${project(p).join(',')}`).join(' ')+'Z').join(' '),fill:palette.get(f.properties.subdistrict),'fill-rule':'evenodd',tabindex:'0',role:'button','aria-label':f.properties.name});path.classList.add('village-shape');path.append(node('title'));path.firstChild.textContent=f.properties.name;path.addEventListener('click',()=>choose(f));path.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();choose(f);}});group.append(path);paths.set(f.id,path);}
    const render=()=>{const ids=new Set(filterFeatures(map.features,search.value,select.value).map(f=>f.id));for(const [id,path]of paths){path.classList.toggle('dimmed',!ids.has(id));path.setAttribute('tabindex',ids.has(id)?'0':'-1');}document.getElementById('map-message').textContent=`${ids.size} matching source shapes · ${map.metadata.rejected.length} rejected source shapes · Census links pending`;};
    search.addEventListener('input',render);select.addEventListener('change',render);render();
    let zoom=1;const zoomTo=value=>{zoom=Math.max(1,Math.min(8,value));group.setAttribute('transform',`translate(380 270) scale(${zoom}) translate(-380 -270)`);};document.getElementById('zoom-in').addEventListener('click',()=>zoomTo(zoom*1.5));document.getElementById('zoom-out').addEventListener('click',()=>zoomTo(zoom/1.5));document.getElementById('fit-map').addEventListener('click',()=>zoomTo(1));
}
if(section)start().catch(()=>{document.getElementById('map-message').textContent='Source map unavailable. Published Census figures and historical graphs remain available.';});
