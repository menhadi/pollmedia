for (const root of document.querySelectorAll('[data-board-picker]')) {
    const state = root.querySelector('[data-board-state]');
    const district = root.querySelector('[data-board-district]');
    const kind = root.querySelector('[data-board-kind]');
    const search = root.querySelector('[data-board-search]');
    const place = root.querySelector('[data-board-place]');
    const area = root.querySelector('[data-board-area]');
    const status = root.querySelector('[data-board-location-status]');
    const more = root.querySelector('[data-board-more]');
    let page = 1, version = 0;
    const option = (value, name) => { const node=document.createElement('option');node.value=value;node.textContent=name;return node; };
    const request = async params => {
        const url=new URL(root.dataset.endpoint);Object.entries(params).forEach(([key,value])=>url.searchParams.set(key,value));
        const response=await fetch(url,{headers:{Accept:'application/json'}});
        if(!response.ok)throw new Error('The place directory is unavailable. Try again.');
        return response.json();
    };
    const clear = () => {area.value='';place.replaceChildren(option('','Choose a place'));more.hidden=true;};
    const loadPlaces = async append => {
        const ticket=++version;
        if(!state.value || !district.value){clear();status.textContent='Choose a state and district first.';return;}
        if(!append){page=1;clear();}
        if(kind.value==='district'){area.value='district:'+state.value+':'+district.value;place.replaceChildren(option(area.value,district.selectedOptions[0].textContent));status.textContent='Selected: '+district.selectedOptions[0].textContent;return;}
        status.textContent='Finding places…';
        try{
            const result=await request({kind:kind.value,state:state.value,district:district.value,q:search.value,page});
            if(ticket!==version)return;
            if(!append)place.replaceChildren(option('','Choose a place'));
            result.items.forEach(item=>place.append(option(item.key,item.name)));
            more.hidden=!result.more;
            status.textContent=result.items.length?'Choose a place from the list.':'No matching places. Try another name or place type.';
        }catch(error){if(ticket===version){if(append)page=Math.max(1,page-1);status.textContent=error.message;}}
    };
    const loadDistricts = async initial => {
        ++version;district.replaceChildren(option('','Choose a district'));
        if(!state.value)return;
        const selectedState=state.value;
        try{
            const result=await request({kind:'districts',state:selectedState});
            if(state.value!==selectedState)return;
            result.items.forEach(item=>district.append(option(item.code,item.name)));
            if(initial && root.dataset.initialDistrict)district.value=root.dataset.initialDistrict;
        }catch(error){status.textContent=error.message;}
    };
    state.addEventListener('change',()=>{clear();loadDistricts(false);status.textContent='Choose a district.';});
    district.addEventListener('change',()=>loadPlaces(false));
    kind.addEventListener('change',()=>loadPlaces(false));
    root.querySelector('[data-board-find]').addEventListener('click',()=>loadPlaces(false));
    search.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();loadPlaces(false);}});
    more.addEventListener('click',()=>{page++;loadPlaces(true);});
    place.addEventListener('change',()=>{area.value=place.value;status.textContent=area.value?'Selected: '+place.selectedOptions[0].textContent:'Choose a place.';});
    if(state.value)loadDistricts(true);
}

for (const pay of document.querySelectorAll('[data-board-pay]')) {
    const kind=pay.form.querySelector('[name=kind]');
    const update=()=>{pay.required=kind.value==='job';};
    kind.addEventListener('change',update);update();
}
