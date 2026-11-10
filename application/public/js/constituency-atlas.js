(() => {
    const atlas = document.querySelector('[data-constituency-atlas]');
    if (!atlas) return;

    for (const scroll of atlas.querySelectorAll('.atlas-table-scroll')) {
        const hint=document.createElement('p');
        hint.className='atlas-scroll-hint';
        hint.textContent=document.documentElement.lang==='hi'?'और कॉलम देखने के लिए तालिका को बाएँ या दाएँ स्क्रॉल करें।':'Scroll sideways for more columns';
        hint.hidden=true;scroll.before(hint);
        const measure=()=>{hint.hidden=scroll.scrollWidth<=scroll.clientWidth+1;};
        measure();
        const observer=new ResizeObserver(measure);
        observer.observe(scroll);
        const table=scroll.querySelector('table');
        if(table)observer.observe(table);
    }

    const form = atlas.querySelector('[data-atlas-year]');
    form.querySelector('select').addEventListener('change', event => {
        const url = event.target.selectedOptions[0]?.dataset.url;
        if (url && new URL(url, location.href).origin === location.origin) location.assign(url);
        else form.requestSubmit();
    });

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
    const directory=atlas.querySelector('[data-village-directory]');
    if(directory) {
        const form=directory.querySelector('[data-village-search]');
        const field=form.elements.village_q;
        const rows=JSON.parse(directory.querySelector('[data-village-rows]').textContent);
        const body=directory.querySelector('tbody');
        const status=directory.querySelector('[data-village-status]');
        const back=directory.querySelector('[data-village-prev]');
        const next=directory.querySelector('[data-village-next]');
        const size=Number(directory.dataset.size);
        let page=Number(directory.dataset.page);
        form.querySelector('button').hidden=true;
        const queryUrl=(number)=>{
            const url=new URL(location.href);
            if(field.value.trim())url.searchParams.set('village_q',field.value.trim());else url.searchParams.delete('village_q');
            if(number>1)url.searchParams.set('village_page',number);else url.searchParams.delete('village_page');
            url.hash='linked-villages';
            return url;
        };
        const render=()=>{
            const query=field.value.trim().normalize('NFC').toLocaleLowerCase();
            const matches=rows.filter(row=>[row.name,row.label].some(value=>value && value.normalize('NFC').toLocaleLowerCase().includes(query)));
            const last=Math.max(1,Math.ceil(matches.length/size));
            page=Math.min(last,Math.max(1,page));
            const first=(page-1)*size;
            const batch=document.createDocumentFragment();
            let tr;
            matches.slice(first,first+size).forEach((v,i)=>{
                if(i%4===0){tr=document.createElement('tr');batch.append(tr);}
                const cell=document.createElement('td'),link=document.createElement('a');
                link.href=directory.dataset.urlPattern.replace('VILLAGECODE',encodeURIComponent(v.code));
                link.textContent=v.label??v.name;cell.append(link);tr.append(cell);
            });
            body.replaceChildren(batch);
            directory.querySelector('[data-village-empty]').hidden=Boolean(matches.length);
            status.textContent=matches.length?(first+1)+'–'+Math.min(first+size,matches.length)+' / '+matches.length.toLocaleString('en-IN'):'0 / 0';
            back.href=queryUrl(Math.max(1,page-1));next.href=queryUrl(Math.min(last,page+1));
            if(document.activeElement===next && page===last && page>1)back.focus({preventScroll:true});
            if(document.activeElement===back && page===1 && page<last)next.focus({preventScroll:true});
            back.hidden=page<=1;next.hidden=page>=last;
        };
        let timer;
        const save=()=>history.replaceState(history.state,'',queryUrl(page));
        field.addEventListener('input',()=>{page=1;render();clearTimeout(timer);timer=setTimeout(save,200);});
        form.addEventListener('submit',e=>{e.preventDefault();page=1;render();save();});
        for(const [control,step] of [[back,-1],[next,1]]) control.addEventListener('click',e=>{
            if(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;
            e.preventDefault();page+=step;render();save();
        });
    }


    const region = atlas.querySelector('[data-atlas-region]');
    const explorer = atlas.querySelector('.atlas-map-explorer[data-current-layer]');
    const map = explorer?.querySelector('[data-election-map]');
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
        if (!matches.length) return;
        const bboxOf = feature => {
            let west=Infinity,south=Infinity,east=-Infinity,north=-Infinity;
            for (const ring of ringList(feature.geometry)) for (const [x,y] of ring) {
                west=Math.min(west,x);east=Math.max(east,x);south=Math.min(south,y);north=Math.max(north,y);
            }
            return [west,south,east,north];
        };
        const centreOf = feature => { const [west,south,east,north]=bboxOf(feature); return [(west+east)/2,(south+north)/2]; };
        const holds = (outer,inner) => {
            const [x,y]=centreOf(inner);
            let inside=false;
            for (const ring of ringList(outer.geometry)) {
                for (let i=0,j=ring.length-1;i<ring.length;j=i++) {
                    const [xi,yi]=ring[i],[xj,yj]=ring[j];
                    if (((yi>y)!==(yj>y)) && (x<(xj-xi)*(y-yi)/(yj-yi)+xi)) inside=!inside;
                }
            }
            return inside;
        };
        const areaOf = feature => { const [west,south,east,north]=bboxOf(feature); return (east-west)*(north-south); };
        const acRows = JSON.parse(region.querySelector('[data-atlas-ac-pages]').textContent);
        const acs = inState.filter(f => f.properties.kind === 'ac');
        // Some state files carry two vintages of the same seat name. Prefer the shape that
        // actually contains the listed assembly segments instead of giving up on the map.
        const seat = matches.length === 1 ? matches[0] : matches
            .map(candidate => ({ candidate, inside: acRows.reduce((count, row) => {
                const name = normalize(row.name);
                return count + (acs.some(ac => normalize(ac.properties.name) === name && holds(candidate, ac)) ? 1 : 0);
            }, 0) }))
            .sort((a, b) => b.inside - a.inside || areaOf(a.candidate) - areaOf(b.candidate))[0].candidate;
        const pc = seat.properties.kind === 'pc'
            ? seat
            : (inState.filter(f => f.properties.kind === 'pc' && holds(f,seat)).sort((a,b)=>areaOf(a)-areaOf(b))[0]
                ?? inState.find(f => f.properties.kind === 'pc' && String(f.properties.code) === String(seat.properties.parent_pc_code))
                ?? null);
        const children = [];
        if (pc) {
            for (const row of acRows) {
                const name = normalize(row.name), candidates = acs.filter(f => normalize(f.properties.name) === name);
                if (!candidates.length) continue;
                const chosen = candidates.length === 1 ? candidates[0] : (candidates.find(f => holds(pc,f)) ?? candidates[0]);
                if (!children.includes(chosen)) children.push(chosen);
            }
            if (!children.length) children.push(...acs.filter(f => holds(pc,f)));
        }
        const parts = children.length ? children : [seat];
        const outlines = pc && children.length ? [...children,pc] : parts;
        const boxes = outlines.map(bboxOf);
        const west = Math.min(...boxes.map(box=>box[0])), south = Math.min(...boxes.map(box=>box[1]));
        const east = Math.max(...boxes.map(box=>box[2])), north = Math.max(...boxes.map(box=>box[3]));
        const cosine=Math.cos((south+north)*Math.PI/360),width=(east-west)*cosine,height=north-south;
        if (!(width>0 && height>0)) return;
        const scale=Math.min(336/width,226/height);
        const project=([x,y])=>[(360-width*scale)/2+(x-west)*cosine*scale,(250-height*scale)/2+(north-y)*scale];
        const pageFor = f => {
            const name=normalize(f.properties.name);
            const row=acRows.find(row=>normalize(row.name)===name) ?? acRows.find(row=>Number(row.code)===Number(f.properties.code));
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
            // Nudge the number until it clears the previous labels so every seat stays numbered.
            const offsets=[[0,0],[0,-14],[0,14],[-22,0],[22,0],[-22,-14],[22,-14],[-22,14],[22,14],[0,-26],[0,26]];
            let spot=null;
            for(const [dx,dy] of offsets){
                const x=Math.max(12,Math.min(348,mid+dx)),yy=Math.max(12,Math.min(238,y+dy));
                if(!used.some(p=>Math.abs(p[0]-x)<17 && Math.abs(p[1]-yy)<11)){spot=[x,yy];break;}
            }
            if(!spot||span<3)continue;
            used.push(spot);const label=svgNode('text',{x:spot[0],y:spot[1],'text-anchor':'middle','dominant-baseline':'central','pointer-events':'none'});label.textContent=f.properties.code;svg.append(label);
        }
        region.querySelector('.atlas-map-frame').hidden=false;
    }
    renderReference().catch(()=>{ /* The state explorer remains visible when the reference outline is unavailable. */ });
})();
