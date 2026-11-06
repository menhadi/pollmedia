import {satelliteRegistry} from './satellite-data.js?v=20261014-storage-1';
import {mountSatelliteComparison} from './satellite-comparison-20261018-highlight.js?v=20261024-all-districts';

const element=(tag,text)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;return node;};
const register=await satelliteRegistry();
const index=document.querySelector('[data-satellite-index]');
if(index){
    const state=index.querySelector('[data-satellite-state]'),district=index.querySelector('[data-satellite-district]'),status=index.querySelector('[role="status"]');
    const names=[...new Set(register.entries.map(entry=>entry.state))].sort((a,b)=>a.localeCompare(b));
    for(const name of names){const option=element('option',name);option.value=name;state.append(option);}
    let version=0;
    async function show(){
        const current=++version;
        const old=document.getElementById('satellite-comparison');
        if(old){old.dispatchEvent(new Event('satellite-dispose'));old.remove();}
        document.getElementById('satellite-disclaimer')?.remove();
        const entry=register.entries.find(row=>row.layer_id===district.value);
        if(!entry){const url=new URL(location.href);url.searchParams.delete('satellite');history.replaceState(null,'',url);status.textContent=`${register.entries.length} stored district image comparisons available. Choose a state and district.`;return;}
        status.textContent=`${entry.state} · ${entry.district} · ${entry.dates.join(' and ')}. Showing the preserved source polygon footprint.`;
        const url=new URL(location.href);url.searchParams.set('satellite',entry.layer_id);history.replaceState(null,'',url);
        state.disabled=district.disabled=true;
        try{await mountSatelliteComparison({entry,home:true,anchor:index});}
        catch(error){if(current===version)status.textContent=error.message;}
        finally{if(current===version)state.disabled=district.disabled=false;}
    }
    function districts(selected=''){
        district.replaceChildren(element('option','Choose a district'));district.firstChild.value='';
        for(const entry of register.entries.filter(row=>row.state===state.value).sort((a,b)=>a.district.localeCompare(b.district))){const option=element('option',entry.district);option.value=entry.layer_id;district.append(option);}
        district.value=selected;district.disabled=!state.value;
    }
    state.addEventListener('change',()=>{districts();show();});district.addEventListener('change',show);
    const selected=register.entries.find(row=>row.layer_id===new URL(location.href).searchParams.get('satellite'));
    if(selected){state.value=selected.state;districts(selected.layer_id);}else districts();
    await show();
}

const source=document.querySelector('[data-source-map]');
if(source&&!document.getElementById('satellite-comparison')){
    const file=new URL(source.dataset.sourceMap,location.origin).pathname.replace(/^\//,'');
    const matches=register.entries.filter(entry=>entry.file===file);
    if(matches.length===1)await mountSatelliteComparison({entry:matches[0],home:true,anchor:source});
}
