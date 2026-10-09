const colours={Suar:'#547ae5',Tanda:'#16a596',Bilaspur:'#a06ad0',Rampur:'#e4a23e',Shahabad:'#db6c8a',Milak:'#41a8c5'};
export function filterFeatures(features,query='',subdistrict='all') {
    const q=query.trim().toLocaleLowerCase();
    return features.filter(({properties:p})=>(subdistrict==='all'||p.subdistrict===subdistrict)&&`${p.name} ${p.source_code} ${p.lgd_name||''}`.toLocaleLowerCase().includes(q));
}
const el=(tag,text)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;return node;};
async function start() {
    document.getElementById('district-picker')?.addEventListener('change',event=>{location.href=event.target.value;});
    const responses=await Promise.all([fetch(document.body.dataset.map),fetch(document.body.dataset.evidence)]);
    if(responses.some(r=>!r.ok))throw new Error('Map evidence unavailable');
    const [map,data]=await Promise.all(responses.map(r=>r.json()));
    const svg=document.getElementById('village-map'), detail=document.getElementById('place-detail'),paths=new Map();
    const villages=new Map(data.lgd_villages.map(row=>[row.id,row]));
    const rings=geometry=>geometry.type==='Polygon'?geometry.coordinates:geometry.coordinates.flat();
    const bounds=map.features.flatMap(f=>rings(f.geometry)).flat().reduce((b,[x,y])=>[Math.min(b[0],x),Math.min(b[1],y),Math.max(b[2],x),Math.max(b[3],y)],[Infinity,Infinity,-Infinity,-Infinity]);
    const [west,south,east,north]=bounds,cosine=Math.cos((north+south)*Math.PI/360),scale=Math.min(700/((east-west)*cosine),460/(north-south));
    const xOffset=(760-(east-west)*cosine*scale)/2,yOffset=(540-(north-south)*scale)/2;
    const project=([x,y])=>[xOffset+(x-west)*cosine*scale,yOffset+(north-y)*scale];
    const node=(tag,attrs={})=>{const n=document.createElementNS(svg.namespaceURI,tag);for(const [key,value]of Object.entries(attrs))n.setAttribute(key,value);return n;};
    const group=node('g');svg.append(group);
    const byId=new Map(map.features.map(f=>[f.id,f]));
    function select(id) {
        const f=byId.get(id);if(!f)return;const p=f.properties,village=villages.get(p.lgd_id);
        for(const [key,path]of paths){path.classList.toggle('selected',key===id);path.setAttribute('aria-pressed',String(key===id));}
        detail.replaceChildren(el('p','Source place evidence'),el('h3',p.lgd_name||p.name));
        const dl=el('dl');for(const [key,value]of Object.entries({'Map source name':p.name,'Source code':p.source_code,'Subdistrict':p.subdistrict,'Directory link':p.status==='code_linked'?'Unique code match':p.status==='ambiguous'?'Multiple shapes · review':'No imported LGD match'}))dl.append(el('dt',key),el('dd',value));detail.append(dl);
        const towns=data.towns.filter(t=>t.code===p.source_code);
        if(towns.length===1){const town=towns[0];detail.append(el('h3','Imported Census town · 2011'),el('p',`${town.name}: ${new Intl.NumberFormat('en-IN').format(town.population)} people · ${town.literacy}% literacy age 7+.`),el('p','Census town code matches the map source code. Boundary edition correspondence is unverified.'));}
        else detail.append(el('p',data.census_village_rows>0?'Published village Census records are available in the dashboard. The map-to-Census link is pending verification.':'Village Census population: not imported. District totals are unchanged by map selection.'));
        if(village){const a=el('a','Open imported LGD place ↗');a.href=`https://pollmedia.org/explore/places/${village.slug}`;detail.append(a);}
        const source=el('a','Inspect Survey of India source ↗');source.href=map.metadata.source;detail.append(source,el('p','The source boundary edition is unverified. No coordinates or population totals were corrected from name matching.'));
        const url=new URL(location.href);url.hash=`shape-${id}`;history.replaceState(null,'',url);
    }
    for(const f of map.features){const p=f.properties;const path=node('path',{d:rings(f.geometry).map(r=>r.map((point,i)=>`${i?'L':'M'}${project(point).join(',')}`).join(' ')+'Z').join(' '),fill:p.status==='code_linked'?(colours[p.subdistrict]||'#8291a8'):'#b4bacb','fill-rule':'evenodd',tabindex:'0',role:'button','aria-label':`${p.name}, ${p.subdistrict}`});path.classList.add('village-shape');const title=node('title');title.textContent=p.name;path.append(title);path.addEventListener('click',()=>select(f.id));path.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();select(f.id);}});group.append(path);paths.set(f.id,path);}
    const legend=document.getElementById('map-legend');for(const [name,colour]of Object.entries({...colours,'Link needs review':'#b4bacb'})){const span=el('span',name),swatch=el('i');swatch.style.setProperty('--swatch',colour);span.prepend(swatch);legend.append(span);}
    const search=document.getElementById('map-search'),subdistrict=document.getElementById('map-subdistrict');
    function render(){const matches=filterFeatures(map.features,search.value,subdistrict.value),ids=new Set(matches.map(f=>f.id));for(const [id,path]of paths){path.classList.toggle('dimmed',!ids.has(id));path.setAttribute('tabindex',ids.has(id)?'0':'-1');}document.getElementById('map-message').textContent=`${matches.length} matching shapes · ${map.metadata.linked_directory_villages} imported LGD villages code-linked · ${map.metadata.directory_villages_without_shape} directory villages without a unique linked shape.`;}
    search.addEventListener('input',render);subdistrict.addEventListener('change',render);
    let zoom=1;const setZoom=value=>{zoom=Math.max(1,Math.min(8,value));group.setAttribute('transform',`translate(380 270) scale(${zoom}) translate(-380 -270)`);};document.getElementById('zoom-in').addEventListener('click',()=>setZoom(zoom*1.5));document.getElementById('zoom-out').addEventListener('click',()=>setZoom(zoom/1.5));document.getElementById('fit-map').addEventListener('click',()=>setZoom(1));
    render();if(location.hash.startsWith('#shape-'))select(location.hash.slice(7));
}
if(typeof document!=='undefined'&&document.getElementById('village-map'))start().catch(()=>{document.getElementById('map-message').textContent='Map unavailable. Imported Census charts and the searchable village directory remain available.';});
