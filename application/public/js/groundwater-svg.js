import {groundwaterModes} from './groundwater-map.js?v=1';
import {latestReported} from './water-evidence.js?v=1';
export function installGroundwaterSvg({metric,legend,group,project,scope,selectedVillage}){
    for(const [key,label]of Object.entries(groundwaterModes))metric.append(new Option(label,key));
    const ns='http://www.w3.org/2000/svg',points=document.createElementNS(ns,'g');points.setAttribute('aria-label','Groundwater monitoring locations');group.append(points);
    let data=null,signature='',version=0;
    const render=()=>{
        points.replaceChildren();if(!Object.hasOwn(groundwaterModes,metric.value))return;
        const params=new URLSearchParams(scope()).toString();
        if(signature!==params){signature=params;data=null;const token=++version;fetch('/api/water/groundwater?'+params).then(r=>{if(!r.ok)throw Error();return r.json();}).then(d=>{if(token!==version)return;data=d;render();}).catch(()=>{if(token===version){data={stations:[]};render();}});}
        const type=metric.value==='gw_level'?'level':metric.value==='gw_chemistry'?'chemistry':'quality',selection=selectedVillage();
        const stations=(data?.stations||[]).filter(s=>s.kind===type&&(selection===null||(s.location_link?.status==='supported_location'&&s.location_link.lgd===selection)));
        for(const station of stations){const [cx,cy]=project([station.longitude,station.latitude]),point=document.createElementNS(ns,'circle');point.setAttribute('cx',cx);point.setAttribute('cy',cy);point.setAttribute('r','5');point.setAttribute('fill',type==='level'?'#146e86':'#a85e21');point.setAttribute('stroke','white');point.setAttribute('stroke-width','1');point.setAttribute('tabindex','0');point.setAttribute('role','link');point.style.cursor='pointer';
            const key=type==='level'?'Groundwater Level Quarterly Manual (meter)':'Potential of Hydrogen (pH)',value=latestReported(station,key),title=document.createElementNS(ns,'title');title.textContent=`${station.station}: ${value?.conflict?'Conflicting source observations':value?.value??'Not reported'} · ${value?.row?.observed_at?.slice(0,10)||'Unavailable'} · Monitoring site only`;point.append(title);point.setAttribute('aria-label',title.textContent);const open=()=>location.assign(station.explorer_url);point.addEventListener('click',open);point.addEventListener('keydown',e=>{if(e.key==='Enter')open();});points.append(point);
        }
        legend.textContent=!data?'Loading accepted groundwater monitoring locations…':type==='quality'?'No verified drinking-water quality records are imported. Grey does not mean safe or unsafe.':`${stations.length} historical monitoring locations · ${type==='level'?'level values in metres':'chemistry shown as pH'} · hover or select a point for its dated record. Polygons are not assigned monitoring-well measurements.`;
    };
    metric.addEventListener('change',render);
    return {render};
}
