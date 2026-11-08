(() => {
    const atlas = document.querySelector('[data-constituency-atlas]');
    if (!atlas) return;

    const form = atlas.querySelector('[data-atlas-year]');
    form.querySelector('select').addEventListener('change', () => form.requestSubmit());

    for (const picker of atlas.querySelectorAll('[data-atlas-switch]')) {
        const select=picker.querySelector('select');
        if(!select) continue;
        select.addEventListener('change',()=>{
            if(!select.value)return;
            const url=select.selectedOptions[0]?.dataset.url;
            if(url && new URL(url,location.href).origin===location.origin) location.assign(url);
            else picker.requestSubmit();
        });
    }
    const region = atlas.querySelector('[data-atlas-region]');
    const explorer = atlas.querySelector('.atlas-map-explorer');
    const map = explorer.querySelector('[data-election-map]');
    if (!map?.dataset.source || !map.dataset.state || atlas.dataset.ambiguous === '1') return;
    const normalize = value => String(value ?? '').trim().toLowerCase().replace(/\s*\((sc|st|gen)\)$/i, '').trim();
    const ringList = geometry => geometry.type === 'Polygon' ? geometry.coordinates : geometry.coordinates.flat();
    const svgNode = (type, attrs) => {
        const el = document.createElementNS('http://www.w3.org/2000/svg', type);
        for (const [key,value] of Object.entries(attrs)) el.setAttribute(key, value);
        return el;
    };

    async function renderReference() {
        const response = await fetch(map.dataset.source);
        if (!response.ok) return;
        const {features} = await response.json();
        const inState = features.filter(f => normalize(f.properties.state) === normalize(map.dataset.state) && ['Polygon','MultiPolygon'].includes(f.geometry?.type) && !f.properties.geometry_warning);
        const matches = inState.filter(f => f.properties.kind === map.dataset.kind && normalize(f.properties.name) === normalize(map.dataset.selected));
        if (matches.length !== 1) return;
        const seat = matches[0], pcCode = seat.properties.kind === 'pc' ? seat.properties.code : seat.properties.parent_pc_code;
        const pc = pcCode == null ? null : inState.find(f => f.properties.kind === 'pc' && String(f.properties.code) === String(pcCode));
        const children = pc ? inState.filter(f => f.properties.kind === 'ac' && String(f.properties.parent_pc_code) === String(pcCode)) : [];
        const parts = children.length ? children : [seat];
        const outlines = pc && children.length ? [...children,pc] : parts;
        let west=Infinity,south=Infinity,east=-Infinity,north=-Infinity;
        for (const f of outlines) for (const ring of ringList(f.geometry)) for (const [x,y] of ring) {
            west=Math.min(west,x);east=Math.max(east,x);south=Math.min(south,y);north=Math.max(north,y);
        }
        const cosine=Math.cos((south+north)*Math.PI/360),width=(east-west)*cosine,height=north-south;
        if (!(width>0 && height>0)) return;
        const scale=Math.min(336/width,226/height);
        const project=([x,y])=>[(360-width*scale)/2+(x-west)*cosine*scale,(250-height*scale)/2+(north-y)*scale];
        const acRows=JSON.parse(region.querySelector('[data-atlas-ac-pages]').textContent);
        const pageFor = f => {
            const row=acRows.find(row=>Number(row.code)===Number(f.properties.code) && normalize(row.name)===normalize(f.properties.name));
            return row?.url;
        };
        const colours=window.constituencyMapColours(parts);
        const svg=region.querySelector('svg');
        const draw=(f,fill)=>{
            const d=ringList(f.geometry).map(r=>r.map((point,i)=>(i?'L':'M')+project(point).map(v=>v.toFixed(1)).join(' ')).join('')+'Z').join('');
            const path=svgNode('path',{d,fill,stroke:'var(--atlas-ink)','stroke-width':fill==='none'?1.4:.7,'fill-rule':'evenodd'});
            const title=svgNode('title',{});title.textContent=f.properties.name;path.append(title);
            const href=fill!=='none' && f.properties.kind==='ac' && pageFor(f);
            if(href){
                const link=svgNode('a',{href,'aria-label':f.properties.name});
                link.append(path);svg.append(link);
            } else {
                if(fill==='none')path.setAttribute('pointer-events','none');
                svg.append(path);
            }
        };
        parts.forEach(f=>draw(f,colours.get(f.id)));
        if(pc && children.length)draw(pc,'none');
        const listing=region.querySelector('[data-atlas-segments]');
        if(listing) for (const f of children){
            const li=document.createElement('li');li.textContent=f.properties.code+' · '+f.properties.name;listing.append(li);
        }
        const used=[];
        for (const f of parts) {
            const pathRings=ringList(f.geometry).map(r=>r.map(project));
            const ring=pathRings.reduce((best,r)=>r.length>best.length?r:best,[]);
            if(!ring.length)continue;
            const ys=ring.map(p=>p[1]),y=(Math.min(...ys)+Math.max(...ys))/2;
            const xs=[];ring.forEach(([ax,ay],i)=>{const [bx,by]=ring[(i+1)%ring.length];if((ay>y)!==(by>y))xs.push(ax+(bx-ax)*(y-ay)/(by-ay));});xs.sort((a,b)=>a-b);
            let mid=0,span=0;for(let i=0;i+1<xs.length;i+=2){if(xs[i+1]-xs[i]>span){span=xs[i+1]-xs[i];mid=(xs[i+1]+xs[i])/2;}}
            if(span<14 || used.some(p=>Math.abs(p[0]-mid)<23 && Math.abs(p[1]-y)<12))continue;
            used.push([mid,y]);const label=svgNode('text',{x:mid,y,'text-anchor':'middle','dominant-baseline':'central','pointer-events':'none'});label.textContent=f.properties.code;svg.append(label);
        }
        region.querySelector('.atlas-map-frame').hidden=false;
    }
    renderReference().catch(()=>{ /* The state explorer remains visible when the reference outline is unavailable. */ });
})();
