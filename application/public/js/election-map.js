(() => {
    const requests = new Map(), ns = 'http://www.w3.org/2000/svg';
    const normalize = value => String(value || '').normalize('NFKD').toLowerCase().replace(/\((sc|st)\)|\b(sc|st)\b/g, '').replace(/[^a-z0-9\u0900-\u097f]/g, '');
    const stateKey = value => ({orissa:'odisha',orrisa:'odisha',madras:'tamilnadu',mysore:'karnataka',kerla:'kerala',gujrat:'gujarat',uttaranchal:'uttarakhand',nctofdelhi:'delhi',nationalcapitalterritoryofdelhi:'delhi',pondicherry:'puducherry'}[normalize(value)] || normalize(value));
    // ECI Delimitation2008 Table B, p208: Karnataka PC27 Chikkballapur.
    // Preserved locator source spells PC27 Chikballapur; this is a display alias, not a boundary join.
    const locatorName = (value, state, kind) => stateKey(state)==='karnataka' && kind==='pc' && normalize(value)==='chikballapur' ? 'chikkballapur' : normalize(value);
    function match(feature, records, context) {
        const p = feature.properties, state = stateKey(context || p.state), name = locatorName(p.name,state,p.kind);
        if (!name) return [];
        return records.filter(r => locatorName(r.name,r.state,p.kind) === name && (stateKey(r.state) === state ||
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
    function mountSeatFilter(root, entries, paths) {
        const filter=root.querySelector('[data-map-filter]');
        if (!filter || !entries.length) return;
        const input=filter.querySelector('[data-seat-query]');
        const list=filter.querySelector('[data-seat-options]');
        const empty=filter.querySelector('[data-seat-no-match]');
        const hi=document.documentElement.lang==='hi';
        const searchKey=value=>String(value).normalize('NFKC').toLocaleLowerCase().replace(/[^\p{L}\p{N}\p{M}]+/gu,'');
        const ordered=entries.sort((a,b)=>Number(b.linked)-Number(a.linked)||a.name.localeCompare(b.name));
        let visible=[], active=-1;

        function close(){
            list.hidden=true;empty.hidden=true;active=-1;
            input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');
            for(const path of paths.values()) path.classList.remove('seat-filter-muted');
        }
        function render(){
            const query=searchKey(input.value);
            // The native selector already participates in the site's place-name translations.
            for(const option of list.children){
                const entry=visible[Number(option.dataset.index)];
                if(hi && entry && option.firstChild) entry.displayed=option.firstChild.textContent;
            }
            visible=ordered.filter(seat=>[seat.name,seat.nativeName,seat.nativeOption?.textContent,seat.displayed].some(label=>label && searchKey(label).includes(query)));
            active=-1;input.removeAttribute('aria-activedescendant');
            const items=document.createDocumentFragment();
            visible.forEach((seat,index)=>{
                const option=document.createElement('li');
                option.id=list.id+'-'+index;option.dataset.index=index;option.setAttribute('role','option');option.setAttribute('aria-selected','false');
                option.append(document.createTextNode(hi?(seat.nativeName||seat.nativeOption?.textContent||seat.name):seat.name));
                if(seat.linked){
                    const badge=document.createElement('small');badge.textContent=hi?'संबंधित':'Connected';option.append(badge);
                }
                items.append(option);
            });
            list.replaceChildren(items);list.hidden=!visible.length;empty.hidden=Boolean(visible.length);
            input.setAttribute('aria-expanded',String(Boolean(visible.length)));list.scrollTop=0;
            const shown=new Set(visible.flatMap(seat=>seat.shapeIds));
            for(const [id,path] of paths) path.classList.toggle('seat-filter-muted',Boolean(query)&&!shown.has(id));
        }
        function highlight(index){
            if(!visible.length)return;
            if(active>=0)list.children[active].setAttribute('aria-selected','false');
            active=(index+visible.length)%visible.length;
            const option=list.children[active];option.setAttribute('aria-selected','true');input.setAttribute('aria-activedescendant',option.id);
            list.scrollTop=Math.min(option.offsetTop,Math.max(list.scrollTop,option.offsetTop+option.offsetHeight-list.clientHeight));
        }
        filter.addEventListener('focusin',()=>{const tip=root.querySelector('[data-map-tooltip]');if(tip)tip.hidden=true;});
        input.addEventListener('focus',render);input.addEventListener('click',render);input.addEventListener('input',render);
        input.addEventListener('keydown',event=>{
            if(event.key==='Escape'){event.preventDefault();close();return;}
            if(event.key==='ArrowDown'||event.key==='ArrowUp'){
                event.preventDefault();if(list.hidden)render();
                highlight(event.key==='ArrowDown'?active+1:(active<0?visible.length-1:active-1));
            } else if(event.key==='Enter' && !list.hidden && (active>=0||visible.length===1)){
                event.preventDefault();location.assign(visible[Math.max(0,active)].url);
            }
        });
        list.addEventListener('pointerdown',event=>{if(event.pointerType==='mouse')event.preventDefault();});
        list.addEventListener('click',event=>{const option=event.target.closest('[data-index]');if(option)location.assign(visible[Number(option.dataset.index)].url);});
        filter.addEventListener('focusout',()=>setTimeout(()=>{if(!filter.contains(document.activeElement))close();},0));
        document.addEventListener('pointerdown',event=>{if(!filter.contains(event.target))close();});
        root.querySelector('.election-map-search').hidden=true;filter.hidden=false;
    }


    function mountStateFilters(root, features, matches, paths, records, seats) {
        const fields = root.querySelector('[data-state-map-filters]');
        if (!fields) return;
        const query = fields.querySelector('[data-map-query]');
        const party = fields.querySelector('[data-map-party]');
        const count = fields.querySelector('[data-filter-count]');
        const clear = fields.querySelector('[data-clear-filters]');
        const hi = document.documentElement.lang === 'hi';
        const options = new Map(Array.from(seats.options, opt => [opt.value, opt]));
        if (party) {
            const parties = [...new Set(records.map(record => record.party).filter(Boolean))].sort();
            parties.forEach(name => party.add(new Option(name, name)));
            party.disabled = !parties.length;
        }
        function update() {
            const needle = normalize(query.value.trim());
            const winner = party?.value || '';
            const accepts = (name, record) => (!winner || record?.party === winner)
                && (!needle || normalize(name || '').includes(needle) || normalize(record?.name || '').includes(needle));
            let boundaries = 0, results = 0;
            for (const feature of features) {
                const show = accepts(feature.properties.name, matches.get(feature.id));
                const path = paths.get(feature.id);
                path.classList.toggle('is-filtered-out', !show);
                path.setAttribute('tabindex', show ? '0' : '-1');
                if (!show) path.setAttribute('aria-hidden','true'); else path.removeAttribute('aria-hidden');
                if (show) boundaries++;
            }
            for (const record of records) {
                const option = options.get(String(record.id)), show = accepts(record.name, record);
                if (option) { option.hidden = !show; option.disabled = !show; }
                if (show) results++;
            }
            const selection = root.querySelector('[data-map-selection]');
            if (!selection.hidden) {
                selection.textContent = results || boundaries
                    ? (hi ? 'विवरण देखने के लिए निर्वाचन क्षेत्र चुनें।' : 'Choose a constituency to view its election records.')
                    : (hi ? 'कोई निर्वाचन क्षेत्र नहीं मिला।' : 'No constituency matches these filters.');
            }
            seats.value = '';
            seats.disabled = results === 0;
            clear.disabled = !needle && !winner;
            count.textContent = hi ? boundaries+' सीमाएँ · '+results+' रिकॉर्ड' : boundaries+' boundaries · '+results+' records';
        }
        query.addEventListener('input', update);
        party?.addEventListener('change', update);
        clear.addEventListener('click', () => { query.value = ''; if (party) party.value = ''; update(); query.focus(); });
        fields.hidden = false;
        update();
    }
    async function load(root) {
        const svg = root.querySelector('.election-map-surface svg'), seats = root.querySelector('[data-map-seats]'), status = root.querySelector('[data-map-status]');
        const selection = root.querySelector('[data-map-selection]'), legend = root.querySelector('[data-map-legend]');
        const tooltip = root.querySelector('[data-map-tooltip]');
        const [source, result] = await Promise.allSettled([get(root.dataset.source),get(root.dataset.results)]);
        const records = result.status === 'fulfilled' ? result.value.records : [], colors = result.status === 'fulfilled' ? result.value.colors : {};
        const focusMode = root.dataset.mode === 'focus';
        const karnatakaLocator = focusMode && ['karnataka','tamilnadu'].includes(stateKey(root.dataset.state));
        const colourLocator = focusMode && (karnatakaLocator || root.closest('[data-constituency-atlas]'));
        const seatText = (feature, record) => focusMode ? `${record?.name || feature.properties.name} · ${(root.dataset.kind === 'pc' ? 'Lok Sabha' : 'AC')}` : (record ? recordText(record) : feature.properties.name);
        const seatUrl = record => {
            const safe = record && safeUrl(record.url);
            if (!safe || !focusMode) return safe;
            const url = new URL(safe); url.searchParams.delete('edition'); url.searchParams.delete('code');
            if(document.documentElement.lang==='hi') url.searchParams.set('lang','hi');
            return url.href;
        };
        const all = source.status === 'fulfilled' ? source.value.features : [];
        let features = all.filter(f => f.properties.kind === root.dataset.kind);
        const matches = new Map(), paths = new Map();
        const explorer=root.closest('.atlas-map-explorer');
        const family=JSON.parse(root.querySelector('[data-linked-seats]')?.textContent || '[]');
        const linked=f=>family.some(seat=>String(seat.code)===String(f.properties.code) && normalize(seat.name)===normalize(f.properties.name));
        const finderUrl=f=>{
            const url=new URL(root.dataset.finder,location.href);
            url.searchParams.set('kind',root.dataset.kind);url.searchParams.set('state',root.dataset.state||f.properties.state);
            url.searchParams.set('q',f.properties.name||'');url.searchParams.set('lang',document.documentElement.lang==='hi'?'hi':'en');
            return url.href;
        };
        const locatorColors = colourLocator ? window.constituencyMapColours(features) : new Map();
        // A boundary code is not an extraction row number. Name/state matches are approximate;
        // multiple source shapes with the same name never establish a unique geographic join.
        const names = new Map(), codes = new Map();
        features.forEach(f => {const key = stateKey(f.properties.state)+':'+normalize(f.properties.name); names.set(key,(names.get(key)||0)+1);const codeKey=key+':'+f.properties.code;codes.set(codeKey,(codes.get(codeKey)||0)+1);});
        features.forEach(f => {
            let candidates = match(f,records,root.dataset.state);
            const duplicate = names.get(stateKey(f.properties.state)+':'+normalize(f.properties.name)) > 1;
            if (candidates.length > 1 || duplicate) candidates = candidates.filter(r => r.official_code != null && String(r.official_code) === String(f.properties.code));
            if (candidates.length === 1 && !duplicate) {matches.set(f.id,candidates[0]);}
            else if (candidates.length === 1 && candidates[0].official_code != null && codes.get(stateKey(f.properties.state)+':'+normalize(f.properties.name)+':'+f.properties.code) === 1) {matches.set(f.id,candidates[0]);}
        });
        const rings = feature => feature.geometry.type === 'Polygon' ? feature.geometry.coordinates : feature.geometry.coordinates.flat();
        // Combined-source files (for example Andhra Pradesh before 2014) can carry seats whose
        // present-day state is unverified. Keep them only when an election record or a parent
        // constituency shape accounts for them, so maps never draw another state's seats.
        const reviewNotes = f => { const n = f.properties.review_notes; return Array.isArray(n) ? n : (n ? [n] : []); };
        if (features.some(f => reviewNotes(f).some(n => /present-day state assignment unverified/i.test(String(n))))) {
            const parents = all.filter(f => f.properties.kind === 'pc' && f.geometry);
            const centre = f => {
                const ring = rings(f).reduce((best, r) => r.length > best.length ? r : best, []);
                if (!ring.length) return null;
                let west=Infinity,south=Infinity,east=-Infinity,north=-Infinity;
                for (const [x,y] of ring) { west=Math.min(west,x);east=Math.max(east,x);south=Math.min(south,y);north=Math.max(north,y); }
                return [(west+east)/2,(south+north)/2];
            };
            const inside = (point, pc) => { const [x,y]=point; let hit=false; for (const ring of rings(pc)) for (let i=0,j=ring.length-1;i<ring.length;j=i++) { const [xi,yi]=ring[i],[xj,yj]=ring[j]; if (((yi>y)!==(yj>y))&&(x<(xj-xi)*(y-yi)/(yj-yi)+xi)) hit=!hit; } return hit; };
            features = features.filter(f => {
                if (f.properties.kind !== 'ac' || matches.has(f.id)) return true;
                if (!reviewNotes(f).some(n => /present-day state assignment unverified/i.test(String(n)))) return true;
                const point = centre(f);
                return point ? parents.some(pc => inside(point, pc)) : true;
            });
        }
        let minX=Infinity,minY=Infinity,maxX=-Infinity,maxY=-Infinity;
        features.forEach(f => rings(f).forEach(ring => ring.forEach(([x,y]) => {if(Number.isFinite(x)&&Number.isFinite(y)){minX=Math.min(minX,x);maxX=Math.max(maxX,x);minY=Math.min(minY,y);maxY=Math.max(maxY,y);}})));
        const width=(maxX-minX)*Math.cos(25*Math.PI/180),height=maxY-minY;
        const scale=560/Math.max(width,height || 1),offsetX=(600-width*scale)/2,offsetY=(600-height*scale)/2;
        const project=([x,y]) => [offsetX+(x-minX)*Math.cos(25*Math.PI/180)*scale,offsetY+(maxY-y)*scale];
        function describe(feature, record) {
            selection.replaceChildren();
            const villageMap=root.querySelector('[data-map-village-link]');
            if(villageMap && !explorer){const url=new URL(villageMap.href);url.searchParams.set('state',feature.properties.state||root.dataset.state);url.searchParams.set('kind',feature.properties.kind);url.searchParams.set('name',feature.properties.name);villageMap.href=url.href;villageMap.textContent=`Explore ${feature.properties.name} village polygons →`;}
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
        const tooltipText=(feature,record)=>focusMode ? `${seatText(feature,record)}\n${record?.state || root.dataset.state || feature.properties.state}` : [record?.name || feature.properties.name || 'Unnamed constituency', `${record?.state || root.dataset.state || feature.properties.state} · ${record?.year || root.dataset.year || ''}`, record?.party ? `${record.party}${record.winner ? ' · '+record.winner : ''}` : 'Result not available for this year'].join('\n');
        function showTooltip(feature, record, event) {
            if (!tooltip) return;
            const bounds=root.getBoundingClientRect(),anchor=event.currentTarget?.getBoundingClientRect?.() || bounds;
            tooltip.textContent=tooltipText(feature,record);
            tooltip.hidden=false;
            const x=event.clientX ?? ((anchor.left || 0)+anchor.width/2),y=event.clientY ?? ((anchor.top || 0)+(anchor.height || 0)/2);
            tooltip.style.left=Math.max(8,Math.min(bounds.width-(tooltip.offsetWidth || 240)-8,x-(bounds.left || 0)+12))+'px';
            tooltip.style.top=Math.max(8,y-(bounds.top || 0)-(tooltip.offsetHeight || 90)-12)+'px';
        }
        const hideTooltip=()=>{if(tooltip)tooltip.hidden=true;};
        const selected = r => normalize(r.name) === normalize(root.dataset.selected) && (!root.dataset.selectedCode || String(r.code) === root.dataset.selectedCode);
        features.forEach(f => {
            const record=matches.get(f.id), path=svgElement('path',{d:rings(f).map(ring => ring.map((point,i) => (i?'L':'M')+project(point).map(v=>v.toFixed(2)).join(',')).join(' ')+'Z').join(' '),fill:focusMode?(colourLocator?locatorColors.get(f.id):'#ead59e'):partyColor(record?.party,colors),'fill-rule':'evenodd',tabindex:'0',role:'link','aria-label':seatText(f,record),class:'election-map-seat'});
            if(colourLocator) path.style.setProperty('--locator-fill',locatorColors.get(f.id));
            if (!focusMode) { const title=svgElement('title');title.textContent=seatText(f,record);path.append(title); }
            path.addEventListener('pointerenter',event=>{describe(f,record);showTooltip(f,record,event);});path.addEventListener('pointermove',event=>showTooltip(f,record,event));
            path.addEventListener('focus',event=>{describe(f,record);showTooltip(f,record,event);});path.addEventListener('pointerleave',hideTooltip);path.addEventListener('blur',hideTooltip);
            if(explorer && linked(f))path.classList.add('is-connected');
            const open=()=>{describe(f,record);location.assign(seatUrl(record)||finderUrl(f));};
            path.addEventListener('click',open);path.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();open();}});
            if((record&&selected(record)) || (!record&&root.dataset.selected&&locatorName(f.properties.name,root.dataset.state,root.dataset.kind)===locatorName(root.dataset.selected,root.dataset.state,root.dataset.kind)&&names.get(stateKey(f.properties.state)+':'+normalize(f.properties.name))===1)){path.classList.add('is-selected');path.setAttribute('aria-current','true');describe(f,record);}
            svg.append(path);paths.set(f.id,path);
        });
        // Raise the selected outline above adjacent shapes without changing source geometry.
        paths.forEach(path=>{if(path.classList.contains('is-selected')) svg.append(path);});
        if (focusMode) features.forEach(feature => {
            if (!paths.get(feature.id)?.classList.contains('is-selected')) return;
            const points = rings(feature).flat().map(project);
            const xx = (Math.min(...points.map(p=>p[0])) + Math.max(...points.map(p=>p[0]))) / 2;
            const yy = Math.min(...points.map(p=>p[1])) - 10;
            const label = svgElement('text',{x:xx,y:Math.max(18,yy),'text-anchor':'middle',class:'election-map-current-label','pointer-events':'none'});
            label.textContent = seatText(feature,matches.get(feature.id)); svg.append(label);
        });
        if (explorer) {
            const labels=Array.from(svg.querySelectorAll('.election-map-current-label'),node=>({
                node,x:Number(node.getAttribute('x')),y:Number(node.getAttribute('y'))
            }));
            if(labels.length){
                const sizeLabels=()=>{
                    const scale=svg.getScreenCTM()?.a;
                    if(!(scale>0))return;
                    const view=svg.viewBox.baseVal, pad=5/scale;
                    for(const {node,x,y} of labels){
                        node.style.fontSize=(15/scale)+'px';node.style.fontWeight='500';
                        node.style.strokeWidth=(3/scale)+'px';
                        const half=node.getComputedTextLength()/2;
                        const left=view.x+half+pad,right=view.x+view.width-half-pad;
                        node.setAttribute('x',String(Math.min(right,Math.max(left,x))));
                        node.setAttribute('y',String(Math.min(view.y+view.height-pad,Math.max(view.y+17/scale,y))));
                        node.style.visibility=(x<view.x||x>view.x+view.width||y<view.y||y>view.y+view.height)?'hidden':'visible';
                    }
                };
                sizeLabels();new ResizeObserver(sizeLabels).observe(svg);
                new MutationObserver(sizeLabels).observe(svg,{attributes:true,attributeFilter:['viewBox']});
            }
        }
        const nameLayer = svgElement('g',{'pointer-events':'none','aria-hidden':'true',class:'election-map-names'});
        if (root.dataset.labels === 'true') svg.append(nameLayer);
        function drawNames(zoom=1) {
            if (root.dataset.labels !== 'true') return;
            nameLayer.replaceChildren();
            const occupied=[], edge=(600-600/zoom)/2, font=11/Math.sqrt(zoom);
            features.forEach(feature => {
                const polygons=rings(feature).map(ring=>ring.map(project));
                const area=ring=>Math.abs(ring.reduce((sum,p,i)=>{const next=ring[(i+1)%ring.length];return sum+p[0]*next[1]-next[0]*p[1];},0));
                const ring=polygons.sort((a,b)=>area(b)-area(a))[0];
                if (!ring?.length) return;
                const yy=(Math.min(...ring.map(p=>p[1]))+Math.max(...ring.map(p=>p[1])))/2, crossings=[];
                ring.forEach((p,i)=>{const next=ring[(i+1)%ring.length];if((p[1]>yy)!==(next[1]>yy)) crossings.push(p[0]+(yy-p[1])*(next[0]-p[0])/(next[1]-p[1]));});
                crossings.sort((a,b)=>a-b);
                let span=null;
                for(let i=0;i+1<crossings.length;i+=2) if(!span || crossings[i+1]-crossings[i]>span[1]-span[0]) span=[crossings[i],crossings[i+1]];
                if(!span) return;
                const xx=(span[0]+span[1])/2, name=matches.get(feature.id)?.name || feature.properties.name;
                if(!name || xx<edge || xx>600-edge || yy<edge || yy>600-edge) return;
                const width=name.length*font*.52, box={left:xx-width/2-3/zoom,right:xx+width/2+3/zoom,top:yy-font,bottom:yy+font/2};
                if(occupied.some(b=>box.left<b.right && box.right>b.left && box.top<b.bottom && box.bottom>b.top)) return;
                occupied.push(box);
                const label=svgElement('text',{x:xx,y:yy,'text-anchor':'middle','dominant-baseline':'middle',class:'election-map-seat-name','font-size':font,'stroke-width':1.5/Math.sqrt(zoom)});
                label.textContent=name; nameLayer.append(label);
            });
        }
        drawNames();
        root.querySelector('.map-print-details')?.remove();
        const printDetails=document.createElement('div');printDetails.className='map-print-details';
        features.forEach(feature=>{
            const record=matches.get(feature.id),path=paths.get(feature.id);
            const item=document.createElement('p'),swatch=document.createElement('i');
            swatch.style.backgroundColor=path?.getAttribute('fill') || partyColor(record?.party,colors);
            item.append(swatch,document.createTextNode(tooltipText(feature,record)));
            printDetails.append(item);
        });
        root.append(printDetails);
        seats.replaceChildren();const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent='Choose a constituency';seats.append(placeholder);
        const ordered=[...records].sort((a,b)=>a.name.localeCompare(b.name));
        ordered.forEach(r=>{const option=document.createElement('option');option.value=r.id;option.textContent=focusMode?r.name:recordText(r);option.selected=selected(r);seats.append(option);});
        seats.disabled=!records.length;seats.addEventListener('change',()=>{const r=records.find(r=>r.id===seats.value),url=r&&seatUrl(r);if(url)location.assign(url);});
        mountStateFilters(root, features, matches, paths, records, seats);
        if(explorer){
            const entries=new Map();
            const nativeOptions=new Map(Array.from(seats.options,option=>[option.value,option]));
            for(const r of records){
                const url=seatUrl(r);
                if(url)entries.set(url,{name:r.name,url,linked:false,shapeIds:[],nativeOption:nativeOptions.get(String(r.id))});
            }
            for(const f of features){
                const url=seatUrl(matches.get(f.id))||finderUrl(f);
                const entry=entries.get(url)??{name:f.properties.name,url,linked:false,shapeIds:[]};
                entry.nativeName ||= f.properties.source_attributes?.[root.dataset.kind+'_name_hi'];
                entry.linked ||= linked(f);entry.shapeIds.push(f.id);entries.set(url,entry);
            }
            mountSeatFilter(root,Array.from(entries.values()),paths);
        }
        if(colourLocator){
            legend.hidden=false;
            const note=document.createElement('span');note.textContent=document.documentElement.lang==='hi'?'रंग अलग-अलग निर्वाचन क्षेत्र दिखाते हैं। चुने हुए क्षेत्र की सीमा उजली है।':'Colours distinguish constituencies. The selected seat is outlined.';if(!explorer)legend.append(note);
            if(root.dataset.kind==='pc' && normalize(root.dataset.selected)==='chikkballapur'){const aliasNote=document.createElement('span');aliasNote.textContent='PC27: the locator source says Chikballapur; ECI Delimitation2008 (Table B, p208) says Chikkballapur. Spelling correspondence only; boundary equivalence remains unverified.';legend.append(aliasNote);}
            if(!legend.childElementCount)legend.hidden=true;
        }else if(focusMode){
            [['Selected constituency','var(--site-primary)'],['Other constituencies','var(--palette-d5dfd5)']].forEach(([label,color])=>{const item=document.createElement('span'),swatch=document.createElement('i');swatch.style.backgroundColor=color;item.append(swatch,document.createTextNode(label));legend.append(item);});
        }else{
            const parties=[...new Set(records.map(r=>r.party).filter(Boolean))].sort();
            root.closest('.state-election-section')?.querySelectorAll('[data-winning-party]').forEach(bar=>{
                bar.style.setProperty('--seat-bar','color-mix(in srgb,'+partyColor(bar.dataset.winningParty,colors)+' 72%, #214239)');
            });
            [...parties,null].forEach(party=>{const item=document.createElement('span'),swatch=document.createElement('i');swatch.style.backgroundColor=partyColor(party,colors);swatch.setAttribute('aria-hidden','true');item.append(swatch,document.createTextNode(party||'Result not available'));legend.append(item);});
            if(root.dataset.selected){const item=document.createElement('span'),swatch=document.createElement('i');swatch.style.backgroundColor='var(--site-accent)';item.append(swatch,document.createTextNode('Selected constituency'));legend.append(item);}
        }
        let zoom=1;root.querySelectorAll('[data-map-zoom]').forEach(button=>button.addEventListener('click',()=>{zoom=button.dataset.mapZoom==='reset'?1:Math.max(1,Math.min(8,zoom*(button.dataset.mapZoom==='in'?1.5:1/1.5)));const size=600/zoom;svg.setAttribute('viewBox',`${(600-size)/2} ${(600-size)/2} ${size} ${size}`);svg.style.touchAction=zoom>1?'none':'pan-y';drawNames(zoom);}));
        // Drag a zoomed map; ordinary clicks still open a constituency.
        let drag=null,moved=false;
        svg.addEventListener('pointerdown',e=>{if(zoom<=1)return;drag={x:e.clientX,y:e.clientY,box:svg.getAttribute('viewBox').split(' ').map(Number)};moved=false;});
        svg.addEventListener('pointermove',e=>{if(!drag)return;const dx=e.clientX-drag.x,dy=e.clientY-drag.y;if(Math.abs(dx)+Math.abs(dy)>5)moved=true;const ratio=drag.box[2]/svg.getBoundingClientRect().width;svg.setAttribute('viewBox',`${drag.box[0]-dx*ratio} ${drag.box[1]-dy*ratio} ${drag.box[2]} ${drag.box[3]}`);});
        svg.addEventListener('click',e=>{if(moved){e.preventDefault();e.stopImmediatePropagation();moved=false;}},true);
        ['pointerup','pointerleave','pointercancel'].forEach(name=>svg.addEventListener(name,()=>{drag=null;}));
        status.textContent=focusMode && source.status==='fulfilled' && features.length ? (root.closest('[data-constituency-atlas]') ? features.length+(document.documentElement.lang==='hi'?' निर्वाचन क्षेत्र':' constituencies') : 'Hover for a constituency name. Click to open its page.') : source.status==='rejected'?'Map unavailable. Constituency records remain accessible below.':result.status==='rejected'?'Election results could not be loaded. Shapes remain available with neutral colours.':features.length?'Select a constituency to explore its election records.':'No separate boundary layer is available. Constituency records are listed below.';
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
