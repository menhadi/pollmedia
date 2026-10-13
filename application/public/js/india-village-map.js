export function overlaps(a, b) {
    return a[0] <= b[2] && a[2] >= b[0] && a[1] <= b[3] && a[3] >= b[1];
}

export function visibleLayers(layers, bbox, state = '', limit = 4, byteLimit = 12000000) {
    let bytes = 0;
    return layers.filter(layer => (!state || layer.state === state) && overlaps(layer.bbox, bbox))
        .sort((a, b) => a.bytes - b.bytes || a.id.localeCompare(b.id))
        .filter(layer => {
            if (bytes + layer.bytes > byteLimit) return false;
            bytes += layer.bytes;
            return true;
        }).slice(0, limit);
}

export function safeLink(value) {
    try {
        const url = new URL(value, 'https://pollmedia.org');
        return url.protocol === 'https:' || (url.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(url.hostname));
    } catch { return false; }
}

const node = (tag, text) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    return element;
};

async function start(section) {
    const status = document.getElementById('map-status');
    const searchStatus = document.getElementById('search-status');
    const results = document.getElementById('search-results');
    const details = document.getElementById('place-details');
    const query = document.getElementById('village-search');
    const state = document.getElementById('state-filter');
    const district = document.getElementById('district-filter');
    const response = await fetch(section.dataset.catalogue);
    if (!response.ok) throw new Error('Coverage register unavailable.');
    const catalogue = await response.json();
    catalogue.layers = [...catalogue.layers, ...(catalogue.context_layers || [])];
    const byId = new Map(catalogue.layers.map(layer => [layer.id, layer]));
    let map, ready = false, generation = 0, searchGeneration = 0, searchAbort, timer;
    const loaded = new Set(), pending = new Map();
    function populateDistricts() {
        district.replaceChildren(node('option', 'Choose a district group'));
        district.firstChild.value = '';
        for (const layer of catalogue.layers.filter(layer => !state.value || layer.state === state.value).sort((a,b) => a.district.localeCompare(b.district))) {
            const option = node('option', `${layer.district} · ${layer.state}${layer.kind === 'district' ? ' · district outline only' : ''}`);
            option.value = layer.id;
            district.append(option);
        }
    }
    populateDistricts();
    function fit(box, maxZoom = 10) {
        if (ready) map.fitBounds([[box[0],box[1]],[box[2],box[3]]], {padding:45,maxZoom,duration:500});
    }
    function display(place) {
        details.replaceChildren(node('h2',place.name),node('p',`${place.subdistrict} · ${place.district} · ${place.state}`),node('p',`Source code: ${place.code || 'Unavailable'}`));
        const link = node('a',place.kind === 'district' ? 'Open district dashboard →' : 'Open place page →');
        if (safeLink(place.url)) { link.href = place.url; details.append(link); }
        details.append(node('p',place.kind === 'district' ? 'SOI village polygons are not available here. This outline opens the existing district dashboard and its dated records.' : 'Source identity is preserved. Census and current administrative links are shown only where evidenced.'));
    }
    async function ensure(layer) {
        if (!ready || loaded.has(layer.id)) return;
        if (pending.has(layer.id)) return pending.get(layer.id).promise;
        const controller = new AbortController();
        const promise = (async () => {
            const response = await fetch(`${section.dataset.layerBase}/${layer.id}`, {signal:controller.signal});
            if (!response.ok) throw new Error(response.status === 409 ? 'A source layer changed after the audit; it needs a catalogue refresh.' : 'Village geometry unavailable.');
            const geometry = await response.json();
            if (controller.signal.aborted) return;
            geometry.features.forEach((feature,index) => {feature.id=index; feature.properties={...feature.properties,national_index:index};});
            const id = `villages-${layer.id}`;
            map.addSource(id,{type:'geojson',data:geometry});
            map.addLayer({id,type:'fill',source:id,paint:{'fill-color':layer.kind === 'district' ? '#71879d' : '#168b78','fill-opacity':0.22,'fill-outline-color':'#2e7165'}});
            map.addLayer({id:`${id}-selected`,type:'line',source:id,filter:['==',['id'],-1],paint:{'line-color':'#b44f16','line-width':3}});
            map.on('click',id,async event => {
                const version = ++generation;
                const index = event.features[0].properties.national_index;
                try {
                    const response = await fetch(`${section.dataset.layerBase}/${layer.id}/features/${index}?sha=${layer.sha256}`);
                    if (!response.ok) throw new Error(response.status === 409 ? 'The map catalogue changed. Refresh the map before selecting this shape.' : 'This unusable shape has no indexed place page.');
                    const place = await response.json();
                    if (generation !== version) return;
                    await select(place);
                } catch (error) { if (generation === version) status.textContent = error.message; }
            });
            map.on('mouseenter',id,()=>{map.getCanvas().style.cursor='pointer';});
            map.on('mouseleave',id,()=>{map.getCanvas().style.cursor='';});
            loaded.add(layer.id);
        })().finally(()=>pending.delete(layer.id));
        pending.set(layer.id,{promise,controller});
        return promise;
    }
    function prune(wanted) {
        for (const [id, request] of pending) if (!wanted.has(id)) request.controller.abort();
        for (const id of loaded) if (!wanted.has(id)) {
            map.removeLayer(`villages-${id}-selected`); map.removeLayer(`villages-${id}`); map.removeSource(`villages-${id}`); loaded.delete(id);
        }
    }
    function highlight(place) {
        for (const id of loaded) map.setFilter(`villages-${id}-selected`,['==',['id'],id === place.layer ? place.feature_index : -1]);
    }
    function saveSelection(place) {
        const url = new URL(location.href); url.searchParams.set('place',place.id); history.replaceState(null,'',url);
    }
    async function select(place) {
        const version = ++generation;
        const layer = byId.get(place.layer);
        display(place); saveSelection(place);
        if (!layer || !ready) return;
        state.value = layer.state; populateDistricts(); district.value = layer.id;
        prune(new Set([layer.id]));
        status.textContent = `Loading ${layer.district} ${layer.kind === 'district' ? 'district outline' : 'village shapes'}…`;
        try {
            await ensure(layer);
            if (generation !== version) return;
            highlight(place); fit(place.bbox,layer.kind === 'district' ? 9 : 13); status.textContent = `${place.name} selected · ${layer.kind === 'district' ? 'district outline only; village polygons unavailable' : layer.district + ' source group'}`;
        } catch (error) { if (error.name !== 'AbortError') status.textContent=error.message; }
    }
    async function runSearch() {
        const version = ++searchGeneration;
        searchAbort?.abort(); searchAbort = new AbortController(); results.replaceChildren();
        if (query.value.trim().length < 2) {searchStatus.textContent='Type at least two characters to search.'; return;}
        const params = new URLSearchParams({q:query.value.trim(),state:state.value,layer:district.value});
        searchStatus.textContent='Searching village source records…';
        try {
            const response = await fetch(`${section.dataset.search}?${params}`,{signal:searchAbort.signal});
            if (!response.ok) throw new Error('Search unavailable. Try again shortly.');
            const data = await response.json();
            if (version !== searchGeneration) return;
            searchStatus.textContent=data.results.length ? `${data.results.length} results${data.results.length === 25 ? ' · refine the name or district for more' : ''}` : 'No matching indexed shapes. Check another district or the coverage gaps below.';
            for (const place of data.results) {
                const li=node('li'),button=node('button',place.name); button.type='button';
                button.append(node('small',`${place.code} · ${place.district} · ${place.state}`));
                button.addEventListener('click',()=>select(place));li.append(button);results.append(li);
            }
        } catch (error) {if (error.name !== 'AbortError' && version === searchGeneration) searchStatus.textContent=error.message;}
    }
    query.addEventListener('input',()=>{clearTimeout(timer); ++searchGeneration; searchAbort?.abort(); timer=setTimeout(runSearch,250);});
    state.addEventListener('change',()=>{++generation;populateDistricts();runSearch(); if (ready) {prune(new Set()); const layers=catalogue.layers.filter(layer=>!state.value||layer.state===state.value);if(layers.length)fit(layers.reduce((b,l)=>[Math.min(b[0],l.bbox[0]),Math.min(b[1],l.bbox[1]),Math.max(b[2],l.bbox[2]),Math.max(b[3],l.bbox[3])],[180,90,-180,-90]),7);}});
    district.addEventListener('change',async()=>{++generation;const layer=byId.get(district.value);if(layer?.kind==='district'){results.replaceChildren();searchStatus.textContent='Village polygons unavailable for this source district.';await select(layer.context_place);return;}runSearch();if(layer&&ready){prune(new Set([layer.id]));fit(layer.bbox);try{await ensure(layer);status.textContent=`${layer.shapes.toLocaleString('en-IN')} indexed shapes · ${layer.district} · click a village`;}catch(error){if(error.name!=='AbortError')status.textContent=error.message;}}});
    document.getElementById('india-reset').addEventListener('click',()=>{
        ++generation; state.value=''; populateDistricts();
        const url=new URL(location.href);url.searchParams.delete('place');history.replaceState(null,'',url);
        details.replaceChildren(node('h2','Select a place'),node('p','Search for a village or choose a source district group.'));
        if(ready){prune(new Set());map.fitBounds([[67,6],[98,37]],{padding:30});}
        runSearch();
    });
    try {
        if (!globalThis.maplibregl) throw new Error('Map graphics unavailable. Village search and place pages still work.');
        map = new maplibregl.Map({container:'india-map',center:[80,23],zoom:3.5,style:{version:8,sources:{streets:{type:'raster',tiles:['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],tileSize:256,attribution:'© OpenStreetMap contributors'}},layers:[{id:'streets',type:'raster',source:'streets'}]}});
        map.addControl(new maplibregl.NavigationControl(),'top-right');
        map.on('load',async()=>{
            ready=true;
            map.addSource('coverage',{type:'geojson',data:{type:'FeatureCollection',features:catalogue.layers.map(layer=>({type:'Feature',geometry:{type:'Point',coordinates:[(layer.bbox[0]+layer.bbox[2])/2,(layer.bbox[1]+layer.bbox[3])/2]},properties:{layer:layer.id,state:layer.state,kind:layer.kind||'settlement'}}))}});
            map.addLayer({id:'coverage',type:'circle',source:'coverage',maxzoom:8,paint:{'circle-color':['match',['get','kind'],'district','#71879d','#087d6d'],'circle-radius':5,'circle-stroke-width':1,'circle-stroke-color':'#ffffff'}});
            map.on('click','coverage',event=>{const layer=byId.get(event.features[0].properties.layer);state.value=layer.state;populateDistricts();district.value=layer.id;district.dispatchEvent(new Event('change'));});
            map.on('moveend',async()=>{
                if (district.value) return;
                if(map.getZoom()<8){prune(new Set());status.textContent='Click a coverage marker or choose a district group. Zoom in for village shapes.';return;}
                const bounds=map.getBounds(), bbox=[bounds.getWest(),bounds.getSouth(),bounds.getEast(),bounds.getNorth()];
                const layers=visibleLayers(catalogue.layers,bbox,state.value);
                prune(new Set(layers.map(layer=>layer.id)));
                const outcome=await Promise.allSettled(layers.map(ensure));
                const failure=outcome.find(result=>result.status==='rejected'&&result.reason.name!=='AbortError');
                status.textContent=failure ? failure.reason.message : `${layers.length} nearby source layers loaded. Choose a district group to see a layer outside this view or download limit.`;
            });
            status.textContent='Click a coverage marker or choose a district group. Zoom in for village shapes.';
            const id=new URL(location.href).searchParams.get('place');
            if(id&&/^[a-f0-9]{32}$/.test(id)){const response=await fetch(`${section.dataset.layerBase.replace('/layers','/places')}/${id}`);if(response.ok)await select(await response.json());}
        });
    } catch (error) {status.textContent=error.message;}
    if (query.value.trim().length >= 2) await runSearch();
}

if(typeof document!=='undefined') {
    const section=document.querySelector('[data-catalogue]');
    if(section)start(section).catch(error=>{document.getElementById('map-status').textContent=error.message;});
}
