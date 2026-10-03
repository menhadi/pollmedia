(() => {
    const requests = new Map(), ns = 'http://www.w3.org/2000/svg';
    const normalize = value => String(value || '').normalize('NFKD').toLowerCase().replace(/\((sc|st)\)|\b(sc|st)\b/g, '').replace(/[^a-z0-9\u0900-\u097f]/g, '');
    const stateKey = value => ({orissa:'odisha',orrisa:'odisha',madras:'tamilnadu',mysore:'karnataka',kerla:'kerala',gujrat:'gujarat',uttaranchal:'uttarakhand',nctofdelhi:'delhi',nationalcapitalterritoryofdelhi:'delhi',pondicherry:'puducherry'}[normalize(value)] || normalize(value));
    function match(feature, records, context) {
        const p = feature.properties, state = stateKey(context || p.state), name = normalize(p.name);
        if (!name) return [];
        return records.filter(r => normalize(r.name) === name && (stateKey(r.state) === state ||
            (!context && ['andhrapradesh','telangana'].includes(state) && ['andhrapradesh','telangana'].includes(stateKey(r.state))) ||
            (state === 'ladakh' && stateKey(r.state) === 'jammuandkashmir')));
    }
    function get(url) {
        if (!requests.has(url)) requests.set(url, fetch(url).then(r => {if (!r.ok) throw new Error('Map data unavailable'); return r.json();}).catch(e => {requests.delete(url); throw e;}));
        return requests.get(url);
    }
    function svgElement(tag, attributes = {}) {
        const node = document.createElementNS(ns, tag);
        Object.entries(attributes).forEach(([key,value]) => node.setAttribute(key,value));
        return node;
    }
    const fallbacks = ['var(--palette-234da0)','var(--palette-258368)','var(--palette-b88100)','var(--palette-b97815)','var(--palette-4d6e62)','var(--palette-391800)'];
    function partyColor(party, colors) {
        if (!party) return 'var(--palette-d5dfd5)';
        const key = party.toUpperCase(), alias = {'BHARATIYA JANATA PARTY':'BJP','INDIAN NATIONAL CONGRESS':'INC'}[key] || key;
        if (colors[alias]) return colors[alias];
        let hash = 0; for (const c of key) hash = (hash * 31 + c.charCodeAt(0)) >>> 0;
        return fallbacks[hash % fallbacks.length];
    }
    function recordText(record) {return `${record.name} · ${record.state} · ${record.year}${record.party ? ' · '+record.party : ''}`;}
    function safeUrl(value) {try {const url = new URL(value,location.href); return url.origin === location.origin ? url.href : null;} catch {return null;}}
    async function load(root) {
        const svg = root.querySelector('svg'), seats = root.querySelector('[data-map-seats]'), status = root.querySelector('[data-map-status]');
        const selection = root.querySelector('[data-map-selection]'), legend = root.querySelector('[data-map-legend]');
        const tooltip = root.querySelector('[data-map-tooltip]');
        const [source, result] = await Promise.allSettled([get(root.dataset.source),get(root.dataset.results)]);
        const records = result.status === 'fulfilled' ? result.value.records : [], colors = result.status === 'fulfilled' ? result.value.colors : {};
        const focusMode = root.dataset.mode === 'focus';
        const features = source.status === 'fulfilled' ? source.value.features.filter(f => f.properties.kind === root.dataset.kind) : [];
        const matches = new Map(), used = new Set(), paths = new Map();
        // A boundary code is not an extraction row number. Name/state matches are approximate;
        // multiple source shapes with the same name never establish a unique geographic join.
        const names = new Map(), codes = new Map();
        features.forEach(f => {const key = stateKey(f.properties.state)+':'+normalize(f.properties.name); names.set(key,(names.get(key)||0)+1);const codeKey=key+':'+f.properties.code;codes.set(codeKey,(codes.get(codeKey)||0)+1);});
        features.forEach(f => {
            let candidates = match(f,records,root.dataset.state);
            const duplicate = names.get(stateKey(f.properties.state)+':'+normalize(f.properties.name)) > 1;
            if (candidates.length > 1 || duplicate) candidates = candidates.filter(r => r.official_code != null && String(r.official_code) === String(f.properties.code));
            if (candidates.length === 1 && !duplicate) {matches.set(f.id,candidates[0]);used.add(candidates[0].id);}
            else if (candidates.length === 1 && candidates[0].official_code != null && codes.get(stateKey(f.properties.state)+':'+normalize(f.properties.name)+':'+f.properties.code) === 1) {matches.set(f.id,candidates[0]);used.add(candidates[0].id);}
        });
        const rings = feature => feature.geometry.type === 'Polygon' ? feature.geometry.coordinates : feature.geometry.coordinates.flat();
        let minX=Infinity,minY=Infinity,maxX=-Infinity,maxY=-Infinity;
        features.forEach(f => rings(f).forEach(ring => ring.forEach(([x,y]) => {if(Number.isFinite(x)&&Number.isFinite(y)){minX=Math.min(minX,x);maxX=Math.max(maxX,x);minY=Math.min(minY,y);maxY=Math.max(maxY,y);}})));
        const width=(maxX-minX)*Math.cos(25*Math.PI/180),height=maxY-minY;
        const scale=560/Math.max(width,height || 1),offsetX=(600-width*scale)/2,offsetY=(600-height*scale)/2;
        const project=([x,y]) => [offsetX+(x-minX)*Math.cos(25*Math.PI/180)*scale,offsetY+(maxY-y)*scale];
        function describe(feature, record) {
            selection.replaceChildren();
            const title=document.createElement('strong'); title.textContent=record ? recordText(record) : (feature.properties.name || 'Unnamed constituency');selection.append(title);
            if (record) {
                if(record.winner){const winner=document.createElement('p');winner.textContent=record.winner;selection.append(winner);}
                const url=safeUrl(record.url);if(url){const link=document.createElement('a');link.href=url;link.textContent='Open constituency history →';selection.append(link);}
            } else {
                const link=document.createElement('a'),url=new URL(root.dataset.finder,location.href);
                url.searchParams.set('kind',root.dataset.kind);url.searchParams.set('state',root.dataset.state || feature.properties.state);url.searchParams.set('q',feature.properties.name || '');
                link.href=url.href;link.textContent='Find constituency records →';selection.append(link);
            }
        }
        function showTooltip(feature, record, event) {
            if (!tooltip) return;
            const bounds=root.getBoundingClientRect(),anchor=event.currentTarget?.getBoundingClientRect?.() || bounds;
            tooltip.textContent=[record?.name || feature.properties.name || 'Unnamed constituency', `${record?.state || root.dataset.state || feature.properties.state} · ${record?.year || root.dataset.year || ''}`, record?.party ? `${record.party}${record.winner ? ' · '+record.winner : ''}` : 'Result not available for this year'].join('\n');
            tooltip.hidden=false;
            const x=event.clientX ?? ((anchor.left || 0)+anchor.width/2),y=event.clientY ?? ((anchor.top || 0)+(anchor.height || 0)/2);
            tooltip.style.left=Math.max(8,Math.min(bounds.width-(tooltip.offsetWidth || 240)-8,x-(bounds.left || 0)+12))+'px';
            tooltip.style.top=Math.max(8,y-(bounds.top || 0)-(tooltip.offsetHeight || 90)-12)+'px';
        }
        const hideTooltip=()=>{if(tooltip)tooltip.hidden=true;};
        const selected = r => normalize(r.name) === normalize(root.dataset.selected) && (!root.dataset.selectedCode || String(r.code) === root.dataset.selectedCode);
        features.forEach(f => {
            const record=matches.get(f.id), path=svgElement('path',{d:rings(f).map(ring => ring.map((point,i) => (i?'L':'M')+project(point).map(v=>v.toFixed(2)).join(',')).join(' ')+'Z').join(' '),fill:focusMode?'var(--palette-d5dfd5)':partyColor(record?.party,colors),'fill-rule':'evenodd',tabindex:'0',role:'link','aria-label':record?recordText(record):(f.properties.name || 'Unnamed constituency'),class:'election-map-seat'});
            const title=svgElement('title');title.textContent=record?recordText(record):(f.properties.name||'Unnamed constituency');path.append(title);
            path.addEventListener('pointerenter',event=>{describe(f,record);showTooltip(f,record,event);});path.addEventListener('pointermove',event=>showTooltip(f,record,event));
            path.addEventListener('focus',event=>{describe(f,record);showTooltip(f,record,event);});path.addEventListener('pointerleave',hideTooltip);path.addEventListener('blur',hideTooltip);
            const open=()=>{describe(f,record);const url=record&&safeUrl(record.url);if(url) location.assign(url);else {const finder=new URL(root.dataset.finder,location.href);finder.searchParams.set('kind',root.dataset.kind);finder.searchParams.set('state',root.dataset.state || f.properties.state);finder.searchParams.set('q',f.properties.name || '');location.assign(finder.href);}};
            path.addEventListener('click',open);path.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();open();}});
            if((record&&selected(record)) || (!record&&root.dataset.selected&&normalize(f.properties.name)===normalize(root.dataset.selected)&&names.get(stateKey(f.properties.state)+':'+normalize(f.properties.name))===1)){path.classList.add('is-selected');path.setAttribute('aria-current','true');describe(f,record);}
            svg.append(path);paths.set(f.id,path);
        });
        // Raise the selected outline above adjacent shapes without changing source geometry.
        paths.forEach(path=>{if(path.classList.contains('is-selected')) svg.append(path);});
        seats.replaceChildren();const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent='Choose a constituency';seats.append(placeholder);
        const ordered=[...records].sort((a,b)=>a.name.localeCompare(b.name));
        ordered.forEach(r=>{const option=document.createElement('option');option.value=r.id;option.textContent=recordText(r);option.selected=selected(r);seats.append(option);});
        seats.disabled=!records.length;seats.addEventListener('change',()=>{const r=records.find(r=>r.id===seats.value),url=r&&safeUrl(r.url);if(url)location.assign(url);});
        const unplaced=records.filter(r=>!used.has(r.id)),section=root.querySelector('[data-map-unplaced]'),list=section.querySelector('.election-map-unplaced');
        section.hidden=!unplaced.length;
        unplaced.forEach(r=>{const url=safeUrl(r.url);if(!url)return;const link=document.createElement('a');link.href=url;link.textContent=recordText(r);link.style.setProperty('--seat-party',focusMode?'var(--site-border)':partyColor(r.party,colors));if(selected(r)){link.classList.add('is-selected');section.open=true;selection.textContent=recordText(r)+' · historical seat shown in the schematic list.';}list.append(link);});
        if(focusMode){
            [['Selected constituency','var(--site-primary)'],['Other constituencies','var(--palette-d5dfd5)']].forEach(([label,color])=>{const item=document.createElement('span'),swatch=document.createElement('i');swatch.style.backgroundColor=color;item.append(swatch,document.createTextNode(label));legend.append(item);});
        }else{
            const parties=[...new Set(records.map(r=>r.party).filter(Boolean))].sort();
            [...parties,null].forEach(party=>{const item=document.createElement('span'),swatch=document.createElement('i');swatch.style.backgroundColor=partyColor(party,colors);swatch.setAttribute('aria-hidden','true');item.append(swatch,document.createTextNode(party||'Result not available'));legend.append(item);});
            if(root.dataset.selected){const item=document.createElement('span'),swatch=document.createElement('i');swatch.style.backgroundColor='var(--site-accent)';item.append(swatch,document.createTextNode('Selected constituency'));legend.append(item);}
        }
        let zoom=1;root.querySelectorAll('[data-map-zoom]').forEach(button=>button.addEventListener('click',()=>{zoom=button.dataset.mapZoom==='reset'?1:Math.max(1,Math.min(8,zoom*(button.dataset.mapZoom==='in'?1.5:1/1.5)));const size=600/zoom;svg.setAttribute('viewBox',`${(600-size)/2} ${(600-size)/2} ${size} ${size}`);svg.style.touchAction=zoom>1?'none':'pan-y';}));
        // Drag a zoomed map; ordinary clicks still open a constituency.
        let drag=null,moved=false;
        svg.addEventListener('pointerdown',e=>{if(zoom<=1)return;drag={x:e.clientX,y:e.clientY,box:svg.getAttribute('viewBox').split(' ').map(Number)};moved=false;});
        svg.addEventListener('pointermove',e=>{if(!drag)return;const dx=e.clientX-drag.x,dy=e.clientY-drag.y;if(Math.abs(dx)+Math.abs(dy)>5)moved=true;const ratio=drag.box[2]/svg.getBoundingClientRect().width;svg.setAttribute('viewBox',`${drag.box[0]-dx*ratio} ${drag.box[1]-dy*ratio} ${drag.box[2]} ${drag.box[3]}`);});
        svg.addEventListener('click',e=>{if(moved){e.preventDefault();e.stopImmediatePropagation();moved=false;}},true);
        ['pointerup','pointerleave','pointercancel'].forEach(name=>svg.addEventListener(name,()=>{drag=null;}));
        status.textContent=source.status==='rejected'?'Map unavailable. Constituency records remain accessible below.':result.status==='rejected'?'Election results could not be loaded. Shapes remain available with neutral colours.':features.length?'Select a constituency to explore its election records.':'No separate boundary layer is available. Constituency records are listed below.';
    }
    function init(container=document) {
        const roots=[...(container.matches?.('[data-election-map]')?[container]:[]),...container.querySelectorAll('[data-election-map]')];
        roots.forEach(root=>{if(root.dataset.initialized)return;root.dataset.initialized='true';
            const start=()=>load(root).catch(()=>{root.querySelector('[data-map-status]').textContent='Map could not be loaded. Please try again.';});
            if('IntersectionObserver' in window){const observer=new IntersectionObserver(entries=>{if(entries.some(e=>e.isIntersecting)){observer.disconnect();start();}},{rootMargin:'250px'});observer.observe(root);}else start();
        });
    }
    window.pollmediaElectionMaps=init;
    window.pollmediaMapMatching={normalize,stateKey,match,partyColor};
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>init());else init();
})();
