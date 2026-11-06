import {satelliteRegistry, vegetationMeaning, satelliteAssetUrl} from './satellite-data.js?v=20261014-storage-1';
const node=(tag,text)=>{const element=document.createElement(tag);if(text!==undefined)element.textContent=text;return element;};
const json=async url=>{const response=await fetch(satelliteAssetUrl(url));if(!response.ok)throw Error('Satellite comparison temporarily unavailable');return response.json();};
export function matchedIndices(index,lgd,census){
    const byLgd=[...new Set(lgd.flatMap(code=>index.lgd[code]||[]))];
    const byCensus=census?index.census[census]||[]:[];
    if(byLgd.length&&byCensus.length&&!byLgd.every(i=>byCensus.includes(i)))return [];
    return byLgd.length?byLgd:byCensus;
}
export function safeProfile(url){if(typeof url!=='string'||!url)return null;try{const parsed=new URL(url,location.origin);return parsed.origin===location.origin?parsed.pathname+parsed.search:null;}catch{return null;}}
export function formatNdvi(value){return Number.isFinite(value)?value.toFixed(3):'Unavailable';}

export function ndviBarScale(before,after){const values=[before,after].map(value=>typeof value==='number'&&Number.isFinite(value)&&value>=-1&&value<=1?value:null);return {values,min:values.some(value=>value!==null&&value<0)?-1:0,max:1};}
function ndviChart(dates,before,after){
 const {values,min,max}=ndviBarScale(before,after),ns='http://www.w3.org/2000/svg',svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox','0 0 400 240');svg.setAttribute('role','img');svg.setAttribute('aria-label',`Vegetation index: ${dates[0]} ${formatNdvi(values[0])}; ${dates[1]} ${formatNdvi(values[1])}`);svg.classList.add('ndvi-bars');
 const add=(tag,attrs,label)=>{const el=document.createElementNS(ns,tag);for(const [key,value]of Object.entries(attrs))el.setAttribute(key,value);if(label!==undefined)el.textContent=label;svg.append(el);return el;},y=value=>190-(value-min)/(max-min)*150,zero=y(0);
 for(const value of (min<0?[-1,-.5,0,.5,1]:[0,.25,.5,.75,1])){add('line',{x1:42,x2:385,y1:y(value),y2:y(value),stroke:value===0?'#71837c':'#e4ebe7','stroke-width':value===0?1.5:1});add('text',{x:35,y:y(value)+4,'text-anchor':'end',class:'ndvi-tick'},String(value));}
 values.forEach((value,index)=>{const x=95+index*170;if(value!==null){const top=Math.min(y(value),zero),height=Math.abs(y(value)-zero);add('rect',{x,y:top,width:76,height:Math.max(height,1),rx:8,fill:index===0?'#a78a64':'#176b5c'});add('text',{x:x+38,y:value>=0?top-10:y(value)+20,'text-anchor':'middle',class:'ndvi-value'},formatNdvi(value));}else add('text',{x:x+38,y:100,'text-anchor':'middle',class:'ndvi-tick'},'Unavailable');add('text',{x:x+38,y:220,'text-anchor':'middle',class:'ndvi-date'},dates[index]);});return svg;
}

async function mapLibrary(){
    if(window.maplibregl)return window.maplibregl;
    if(!document.querySelector('link[href*="maplibre-gl.css"]')){const style=node('link');style.rel='stylesheet';style.href='/vendor/maplibre/maplibre-gl.css';document.head.append(style);}
    await new Promise((resolve,reject)=>{const script=node('script');script.src='/vendor/maplibre/maplibre-gl.js';script.onload=resolve;script.onerror=()=>reject(Error('Satellite map library unavailable'));document.head.append(script);});
    return window.maplibregl;
}

export async function mountSatelliteComparison(options={}){
    if(document.getElementById('satellite-comparison'))return;
    const polygon=options.home?null:document.querySelector('[data-polygon-map]'),anchor=options.anchor||document.querySelector('[data-rainfall-panel]');
    const registry=await satelliteRegistry();
    let entry=options.entry||registry.entries.find(row=>[row.file,row.dashboard_file].includes(polygon?.dataset.file));
    const district=!!entry&&!options.home;
    if(!entry){
        if(!anchor||anchor.dataset.district==='1')return;
        const ids=[...new Set((anchor.dataset.lgd||'').split(',').flatMap(code=>registry.lgd[code]||[]).concat(registry.census[anchor.dataset.census]||[]))];
        if(ids.length!==1)return;entry=registry.entries.find(row=>row.layer_id===ids[0]);
    }
    if(!entry)return;const base=entry.base;
    let initial=options.indices||[];const index=await json(base+'index.json');
    if(!district&&!options.home){initial=matchedIndices(index,(anchor.dataset.lgd||'').split(',').filter(Boolean),anchor.dataset.census);if(!initial.length)return;}
    const convert=indices=>polygon?.dataset.file===index.dashboard?.file?indices.map(i=>index.dashboard.indices[i]).filter(Number.isInteger):indices;
    if(document.getElementById('satellite-comparison'))return;
    const style=node('link');style.rel='stylesheet';style.href='/css/satellite-comparison-20261017-filters.css?v=20261018-cascade';document.head.append(style);
    const section=node('section');section.id='satellite-comparison';section.className='panel card satellite-comparison';
    section.append(node('h2',`Satellite comparison · ${entry.district}`),node('p',`${entry.dates.join(' ↔ ')} · Sentinel-2 · Matched seasonal dates`),node('p','Use this to see where vegetation looked greener or less green on the two dates. Choose a village, then drag or zoom either image: both views stay on the same place.'));
    const open=node('button','Open satellite comparison');open.type='button';open.id='satellite-open';section.append(open);
    (polygon||anchor).after(section);
    const addShortcut=()=>{const nav=document.querySelector('.place-primary-nav');if(!nav||nav.querySelector('[href="#satellite-comparison"]'))return;const link=node('a','Satellite');link.href='#satellite-comparison';nav.insertBefore(link,nav.querySelector('.place-edit-link'));};
    addShortcut();document.addEventListener('DOMContentLoaded',addShortcut,{once:true});
    let loaded=false,selection=initial;
    if(district)polygon.addEventListener('place-map-selection',event=>{selection=convert(event.detail.indices);});
    const load=async()=>{
        if(loaded)return;open.disabled=true;open.textContent='Loading imagery and village outlines…';
        try{
            const data=await json(base+'comparison.json');
            if(data.geometry_sha256!==entry.geometry_sha256||data.layer_id!==entry.layer_id)throw Error('Stored imagery does not match this source geometry. Comparison unavailable.');
            const [response,lib]=await Promise.all([fetch(data.geometry_url),mapLibrary()]);
            if(!response.ok)throw Error('Existing village polygons unavailable');
            const raw=await response.arrayBuffer();
            const digest=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',raw)),b=>b.toString(16).padStart(2,'0')).join('');
            if(digest!==data.geometry_sha256)throw Error('Village boundary edition changed. Comparison withheld until reviewed.');
            if(district&&polygon.dataset.file===index.dashboard?.file){
                const source=await fetch(polygon.dataset.polygonMap);if(!source.ok)throw Error('District polygon edition unavailable');
                const sourceDigest=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await source.arrayBuffer())),b=>b.toString(16).padStart(2,'0')).join('');
                if(sourceDigest!==index.dashboard.sha256)throw Error('District boundary edition changed. Comparison withheld until reviewed.');
            }
            const original=JSON.parse(new TextDecoder().decode(raw));
            const geo={...original,features:original.features.map((feature,index)=>({...feature,id:index}))};
            const controls=node('div');controls.className='satellite-controls';
            const subLabel=node('label','Subdistrict '),subdistrict=node('select');subdistrict.id='satellite-subdistrict';subLabel.htmlFor=subdistrict.id;
            const allSubdistricts=node('option','All subdistricts');allSubdistricts.value='';subdistrict.append(allSubdistricts);
            for(const name of [...new Set(data.villages.map(row=>row.original_properties.subdistrict).filter(Boolean))].sort((a,b)=>a.localeCompare(b))){const option=node('option',name);option.value=name;subdistrict.append(option);}
            subLabel.append(subdistrict);controls.append(subLabel);
            const label=node('label','Village '),select=node('select');select.id='satellite-village';label.htmlFor=select.id;
            const villageRows=()=>data.villages.filter(row=>!subdistrict.value||row.original_properties.subdistrict===subdistrict.value);
            function populateVillages(){
                const all=node('option',subdistrict.value?`All villages in ${subdistrict.value}`:'All villages');all.value='';select.replaceChildren(all);
                for(const row of [...villageRows()].sort((a,b)=>a.original_properties.name.localeCompare(b.original_properties.name))){const option=node('option',subdistrict.value?row.original_properties.name:`${row.original_properties.name} · ${row.original_properties.subdistrict||'Subdistrict unavailable'}`);option.value=row.feature_index;select.append(option);}
            }
            populateVillages();label.append(select);controls.append(label);
            const reset=node('button','Back to district'),fit=node('button','Fit selection');reset.type=fit.type='button';controls.append(reset,fit);
            section.append(controls);const stats=node('div');stats.id='satellite-statistics';stats.className='satellite-statistics';stats.setAttribute('aria-live','polite');section.append(stats);
            const pair=node('div');pair.className='satellite-pair';section.append(pair);
            const maps=[];let syncing=false;
            const [west,south,east,north]=data.bounds;
            for(const [index,date] of data.dates.entries()){
                const frame=node('div');frame.className='satellite-frame';frame.append(node('h3',date));
                const canvas=node('div');canvas.className='satellite-map';canvas.id='satellite-map-'+index;canvas.setAttribute('aria-label',`Satellite image ${date}`);frame.append(canvas);pair.append(frame);
                const map=new lib.Map({container:canvas,style:{version:8,sources:{},layers:[{id:'background',type:'background',paint:{'background-color':'#e8edf2'}}]},bounds:[[west,south],[east,north]],fitBoundsOptions:{padding:16},maxZoom:16,attributionControl:false});
                map.addControl(new lib.AttributionControl({customAttribution:data.attribution}));
                map.addControl(new lib.NavigationControl({showCompass:false}),'top-right');maps.push(map);
                await new Promise((resolve,reject)=>{map.once('load',resolve);map.once('error',event=>reject(event.error));});
                map.addSource('imagery',{type:'image',url:satelliteAssetUrl(base+data.images[date]),coordinates:[[west,north],[east,north],[east,south],[west,south]]});
                map.addLayer({id:'imagery',type:'raster',source:'imagery',paint:{'raster-fade-duration':0}});
                map.addSource('villages',{type:'geojson',data:geo});
                map.addLayer({id:'village-hit',type:'fill',source:'villages',paint:{'fill-opacity':0}});
                map.addLayer({id:'village-lines',type:'line',source:'villages',paint:{'line-color':'#ffffff','line-width':.6,'line-opacity':.55}});
                map.addLayer({id:'selected-fill',type:'fill',source:'villages',filter:['in',['id'],['literal',[]]],paint:{'fill-color':'#ffbf3f','fill-opacity':.12}});
                map.addLayer({id:'selected-line',type:'line',source:'villages',filter:['in',['id'],['literal',[]]],paint:{'line-color':'#ffbf3f','line-width':3}});
                map.on('click','village-hit',event=>choose([Number(event.features[0].id)]));
                map.on('mouseenter','village-hit',()=>map.getCanvas().style.cursor='pointer');map.on('mouseleave','village-hit',()=>map.getCanvas().style.cursor='');
                map.on('move',()=>{if(syncing)return;syncing=true;for(const other of maps)if(other!==map)other.jumpTo({center:map.getCenter(),zoom:map.getZoom(),bearing:map.getBearing(),pitch:map.getPitch()});syncing=false;});
            }
            function fitSelection(){
                const bounds=new lib.LngLatBounds();const visit=coordinates=>{if(typeof coordinates[0]==='number')bounds.extend(coordinates.slice(0,2));else coordinates.forEach(visit);};
                if(selection.length)selection.forEach(index=>visit(geo.features[index].geometry.coordinates));else{bounds.extend([west,south]);bounds.extend([east,north]);}
                maps[0].fitBounds(bounds,{padding:35,maxZoom:15,duration:0});
            }
            function choose(indices,preserveGroup=false){
                if(!preserveGroup){const row=indices.length===1?data.villages.find(row=>row.feature_index===indices[0]):null;subdistrict.value=row?.original_properties.subdistrict||'';populateVillages();}
                selection=indices;select.value=indices.length===1?String(indices[0]):'';
                for(const map of maps)for(const layer of ['selected-fill','selected-line'])map.setFilter(layer,['in',['id'],['literal',indices]]);
                stats.replaceChildren();const rows=indices.length?data.villages.filter(row=>indices.includes(row.feature_index)):[{...data.summary,original_properties:{name:`${entry.district} source polygon footprint`}}];
                for(const row of rows.length===1?rows:[]){
                    const card=node('div');card.append(node('strong',row.original_properties.name),node('p',vegetationMeaning(row.ndvi_change)));
                    card.append(ndviChart(entry.dates,row.before_ndvi,row.after_ndvi));const change=node('p',`NDVI change: ${row.ndvi_change==null?'Unavailable':(row.ndvi_change>0?'+':'')+formatNdvi(row.ndvi_change)}`);change.className='ndvi-change';card.append(change);
                    card.append(node('small',`${row.paired_coverage_pct}% paired clear coverage · ${row.paired_pixels?.toLocaleString()??'Unavailable'} shared 20 m pixels`));
                    const url=safeProfile(row.identity?.url);if(url){const link=node('a','Open existing village profile →');link.href=url;card.append(link);}stats.append(card);
                }
                if(rows.length>1)stats.append(node('p',`${rows.length} source polygons selected. Choose one village to see its comparison.`));
                fitSelection();
            }
            select.addEventListener('change',()=>{choose(select.value===''?(subdistrict.value?villageRows().map(row=>row.feature_index):[]):[Number(select.value)],true);});subdistrict.addEventListener('change',()=>{populateVillages();choose(subdistrict.value?villageRows().map(row=>row.feature_index):[],true);});reset.addEventListener('click',()=>choose([]));fit.addEventListener('click',fitSelection);
            if(district){
                polygon.addEventListener('place-map-selection',event=>choose(convert(event.detail.indices)));
                const selected=document.getElementById('polygon-village')?.value;if(!selection.length&&selected)selection=convert([Number(selected)]);
            }
            if(options.home){const listener=event=>{if(event.detail.sha===data.geometry_sha256)choose(event.detail.indices);};anchor.addEventListener('satellite-place-selection',listener);section.addEventListener('satellite-dispose',()=>anchor.removeEventListener('satellite-place-selection',listener),{once:true});}
            section.addEventListener('satellite-dispose',()=>maps.forEach(map=>map.remove()),{once:true});
            choose(selection);
            section.append(node('p','Drag or zoom either image to compare the same location. Click a village to highlight it in both years.'));
            const source=node('a','Official Copernicus Sentinel‑2 source ↗');source.href=data.source;source.target='_blank';source.rel='noopener';
            const disclaimer=node('details');disclaimer.id='satellite-disclaimer';disclaimer.append(node('summary','Satellite sources, dates and limitations'),node('p',data.attribution),node('p',data.download_provider),node('p',data.method),node('p',data.limitations),node('p','A greener image can reflect crop growth, irrigation or vegetation cover. A less green image can reflect harvest, bare ground or seasonal timing. Check the images, rainfall and local records before deciding why. These images do not measure soil fertility, crop yield or drinking-water quality.'),node('p',`Downloaded ${data.retrieved_at.slice(0,10)}. Original village geometry SHA-256: ${data.geometry_sha256}. Original LGD, Census and SOI identifiers remain unchanged.`));
            disclaimer.append(source);for(const scene of data.scenes){const paragraph=node('p');const link=node('a',`${scene.product_id} · official acquisition record ↗`);link.href=scene.official_catalogue_url;link.target='_blank';link.rel='noopener';paragraph.append(link);disclaimer.append(paragraph);}
            (document.querySelector('#methodology,#area-methodology,#local-methodology')||document.querySelector('main')).append(disclaimer);open.remove();loaded=true;
        }catch(error){open.disabled=false;open.textContent='Retry satellite comparison';let errorLine=section.querySelector('.satellite-error');if(!errorLine){errorLine=node('p');errorLine.className='satellite-error';section.append(errorLine);}errorLine.textContent=error.message;}
    };
    open.addEventListener('click',load);
    await load();
}
