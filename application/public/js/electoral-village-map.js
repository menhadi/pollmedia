(() => {
    const root=document.getElementById('electoral-village-explorer');
    if(!root)return;
    const catalogue=JSON.parse(document.getElementById('electoral-village-catalogue').textContent);
    const state=document.getElementById('electoral-village-state'),kind=document.getElementById('electoral-village-kind'),seat=document.getElementById('electoral-village-seat');
    const status=document.getElementById('electoral-village-status'),details=document.getElementById('electoral-village-detail');
    const normalize=value=>String(value||'').normalize('NFKD').toLowerCase().replace(/&/g,'and').replace(/^the /,'').replace(/\((sc|st)\)/g,'').replace(/[^a-z0-9]/g,'');
    const params=new URL(location.href).searchParams;
    const stateMatch=catalogue.states.filter(s=>s.code===params.get('state')||normalize(s.name)===normalize(params.get('state')));
    if(stateMatch.length===1)state.value=stateMatch[0].code;
    if(['pc','ac'].includes(params.get('kind')))kind.value=params.get('kind');
    function populate(){seat.replaceChildren();for(const record of catalogue.seats.filter(s=>s.state===state.value&&s.kind===kind.value)){const option=document.createElement('option');option.value=record.code;option.textContent=record.name;seat.append(option);}if(!seat.options.length){const option=document.createElement('option');option.textContent='No official membership in this export';option.value='';seat.append(option);} }
    populate();
    const candidates=catalogue.seats.filter(s=>s.state===state.value&&s.kind===kind.value&&normalize(s.name)===normalize(params.get('name')));
    if(params.get('code')&&[...seat.options].some(o=>o.value===params.get('code')))seat.value=params.get('code');
    else if(candidates.length===1)seat.value=candidates[0].code;
    else if(params.has('name')){status.textContent='No unique official LGD constituency matches that map name. Choose the constituency below.';seat.value='';}
    state.addEventListener('change',populate);kind.addEventListener('change',populate);
    let map,ready=false,generation=0,controller;
    const handlers=new Map();
    try{map=new maplibregl.Map({container:'india-map',style:{version:8,sources:{},layers:[{id:'background',type:'background',paint:{'background-color':'#edf3ee'}}]},center:[80,23],zoom:3});map.addControl(new maplibregl.NavigationControl());map.on('load',()=>{ready=true;if(seat.value&&(params.has('code')||candidates.length===1))show();});}catch(error){status.textContent='Interactive map unavailable. Official membership remains accessible.';}
    function paragraph(text){const node=document.createElement('p');node.textContent=text;details.append(node);}
    async function show(){
        if(!seat.value){status.textContent='This selection has no membership in the downloaded export.';return;}
        const version=++generation;controller?.abort();controller=new AbortController();const signal=controller.signal;
        status.textContent='Loading official membership…';details.replaceChildren();
        if(ready){for(const [id,handler] of handlers)map.off('click',id,handler);handlers.clear();for(const layer of [...map.getStyle().layers])if(layer.id!=='background')map.removeLayer(layer.id);for(const id of Object.keys(map.getStyle().sources))map.removeSource(id);}
        try{
            const query=new URLSearchParams({state:state.value,kind:kind.value,code:seat.value});const response=await fetch(`${root.dataset.membership}?${query}`,{signal});if(!response.ok)throw new Error('Official membership unavailable.');
            const data=await response.json();if(version!==generation)return;
            const url=new URL(location.href);url.search=query.toString();history.replaceState(null,'',url);
            const heading=document.createElement('h2');heading.textContent=data.name;details.append(heading);
            paragraph(`${data.counts.villages} villages linked through official membership and hierarchy · ${data.counts.linked_villages} with checked shapes · ${data.counts.villages_without_displayed_shape} without a displayed shape.`);
            paragraph(`${data.counts.partly_covered_villages} partly covered villages; their whole polygons are withheld.`);paragraph(data.note);
            const disclosure=document.createElement('details'),summary=document.createElement('summary');summary.textContent='Official membership and coverage';disclosure.append(summary);const list=document.createElement('ul');
            for(const entity of data.entities){const item=document.createElement('li');item.textContent=`${entity.entity_name} · ${entity.entity_type} ${entity.entity_code} · ${Number(entity.partial)?'Partly covered':'Fully covered'} · source row ${entity.source_row}`;list.append(item);}disclosure.append(list);details.append(disclosure);
            if(!ready)return;
            const bounds=new maplibregl.LngLatBounds();let displayed=0,failed=0;
            for(const layer of data.layers){
                if(version!==generation)return;
                try{
                    const response=await fetch(`${root.dataset.layerBase}/${layer.id}`,{signal});if(!response.ok)throw new Error('District layer unavailable or changed since linking.');const source=await response.json();if(version!==generation)return;
                    const features=layer.indices.map(index=>{const feature=source.features[index];if(!feature)throw new Error('Feature index changed');return {...feature,properties:{...feature.properties,lgd_code:layer.codes[String(index)]}};});
                    for(const f of features){const visit=value=>{if(typeof value[0]==='number'){bounds.extend(value.slice(0,2));}else value.forEach(visit);};visit(f.geometry.coordinates);}
                    const id=`district-${layer.id}`;map.addSource(id,{type:'geojson',data:{type:'FeatureCollection',features}});map.addLayer({id,type:'fill',source:id,paint:{'fill-color':'#168b78','fill-opacity':0.35,'fill-outline-color':'#2e7165'}});
                    const handler=event=>{const p=event.features[0].properties;const content=document.createElement('div');content.textContent=`${p.name} · LGD ${p.lgd_code} · source boundary edition unverified`;const link=document.createElement('a');link.href=`${root.dataset.villageBase}/${encodeURIComponent(p.lgd_code)}`;link.textContent='Open village records →';content.append(document.createElement('br'),link);new maplibregl.Popup().setLngLat(event.lngLat).setDOMContent(content).addTo(map);};map.on('click',id,handler);handlers.set(id,handler);displayed+=features.length;
                }catch(error){if(error.name==='AbortError')throw error;failed++;}
            }
            if(!bounds.isEmpty())map.fitBounds(bounds,{padding:40,maxZoom:11});
            status.textContent=displayed?`${displayed} village shapes displayed${failed?` · ${failed} district layers unavailable`:''}.`:'No checked village polygons are available for this constituency yet. Membership is listed alongside the map.';
        }catch(error){if(error.name!=='AbortError'&&version===generation)status.textContent=error.message;}
    }
    document.getElementById('electoral-village-form').addEventListener('submit',event=>{event.preventDefault();show();});
})();
