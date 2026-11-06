import {latestReported} from './water-evidence.js?v=1';
export const groundwaterModes={gw_level:'Water · groundwater monitoring (m)',gw_chemistry:'Water · groundwater chemistry (pH)',gw_quality:'Water · drinking-water quality'};
const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
export class GroundwaterMapLayer{
    constructor({metric,legend,map,scope}){Object.assign(this,{metric,legend,map,scope});for(const [key,label]of Object.entries(groundwaterModes))metric.append(new Option(label,key));this.version=0;}
    active(){return Object.hasOwn(groundwaterModes,this.metric.value);}
    async sync(){
        const map=this.map();
        if(this.active()&&(!map||!map.isStyleLoaded())){
            this.legend.textContent=this.metric.value==='gw_quality'?'No verified drinking-water quality records are imported. Grey does not mean safe or unsafe.':'Loading historical groundwater monitoring locations…';
            if(map&&!this.waiting){this.waiting=true;map.once('idle',()=>{this.waiting=false;this.sync();});}
            return;
        }
        if(!map)return;
        if(!this.active()){if(map.getLayer('groundwater-points'))map.setLayoutProperty('groundwater-points','visibility','none');return;}
        const params=new URLSearchParams(Object.entries(this.scope()).filter(([,v])=>v!==null&&v!==''&&v!==undefined));const signature=params.toString();
        if(this.signature!==signature){this.signature=signature;this.data=null;this.error=false;const version=++this.version;this.abort?.abort();this.abort=new AbortController();
            if(map.getSource('groundwater-points'))map.getSource('groundwater-points').setData({type:'FeatureCollection',features:[]});
            this.fetching=true;fetch('/api/water/groundwater?'+signature,{signal:this.abort.signal}).then(r=>{if(!r.ok)throw Error();return r.json();}).then(d=>{if(version!==this.version)return;this.data=d;this.fetching=false;this.sync();}).catch(e=>{if(e.name!=='AbortError'&&version===this.version){this.error=true;this.fetching=false;this.sync();}});
        }
        const kind=this.metric.value==='gw_level'?'level':this.metric.value==='gw_chemistry'?'chemistry':'quality';
        const parameter=kind==='level'?'Groundwater Level Quarterly Manual (meter)':'Potential of Hydrogen (pH)';
        const features=(this.data?.stations||[]).filter(s=>s.kind===kind).map(station=>{const sample=latestReported(station,parameter);return {type:'Feature',properties:{id:station.id,station:station.station,locality:station.village,measurement:sample?.conflict?'Conflicting observations':sample?String(sample.value):'Not reported',date:sample?.row?.observed_at?.slice(0,10)||sample?.date||'Unavailable',parameter,url:station.explorer_url},geometry:{type:'Point',coordinates:[station.longitude,station.latitude]}};});
        if(!map.getSource('groundwater-points')){map.addSource('groundwater-points',{type:'geojson',data:{type:'FeatureCollection',features:[]}});map.addLayer({id:'groundwater-points',type:'circle',source:'groundwater-points',paint:{'circle-radius':7,'circle-color':'#146e86','circle-stroke-width':2,'circle-stroke-color':'white'}});
            map.on('click','groundwater-points',e=>{const p=e.features[0].properties,n=el('div');n.append(el('strong',p.station),el('p',`Source locality: ${p.locality}`),el('p',`${p.parameter}: ${p.measurement}`),el('p',`Observed: ${p.date}`),el('small','Monitoring location only; not a village average or drinking-water safety result.'));const a=el('a','History and source →');a.href=p.url;n.append(a);new maplibregl.Popup().setLngLat(e.lngLat).setDOMContent(n).addTo(map);});
        }
        map.getSource('groundwater-points').setData({type:'FeatureCollection',features});map.setLayoutProperty('groundwater-points','visibility','visible');
        const note=el('div');note.className='water-map-note';
        const message=this.error?'Water monitoring evidence is temporarily unavailable.':this.fetching?'Loading accepted water monitoring locations…':kind==='quality'?'No verified drinking-water quality records are imported. Grey does not mean safe or unsafe.':`${features.length} monitoring locations in the selected area. Points show latest reported ${kind==='level'?'level values (m)':'pH values'} at each site, with dates. Village and district polygons are not assigned well measurements.`;
        note.append(el('p',message));if(kind!=='quality')note.append(el('small','Historical records only. Levels and chemistry can have different observation years. Source convention and aquifer need verification before interpreting groundwater change.'));
        this.legend.replaceChildren(note);
    }
}
