import {orderedPlaceNavigation,statePageForMode} from './place-navigation-order.js?v=3';
import {GroundwaterMapLayer} from './groundwater-map.js?v=1';
import {isJjm,jjmValues,jjmRecords,addJjmMeasures,jjmNote,jjmMissingNote} from './jjm-map-values-20261016-v2.js';
import {publishedMapFetch} from './community-map-data.js?v=1';
import {isNativeCensusField,nativeSummary,nativeOverviewValue,nativeSummaryText,nativeBreaks,nativeVillageColours} from './census-native-overview-both.js';
import {facilityDistribution,facilityDistributionText,schoolOverviewTotal} from './census-facility-overview.js?v=20261015-2';
import {satelliteForLayer,mergeSatellite,vegetationMeaning,satelliteDisclaimer} from './satellite-data.js?v=20261014-storage-1';
import {soilMetrics,isSoil,loadSoil,loadSoilConstituencies,linkSoil,soilDisclaimer,soilCycleControl,soilOverview,soilValues,soilCode,soilSampleCount} from './soil-map.js?v=20261014-electoral-1';
import {RainfallClient} from './rainfall-client.js?v=20261014-1';
import {mergeRainfallRecords} from './rainfall-grid.js?v=20261014-1';
import {yearControl,loadHistory,datedRecords,censusValues,censusMetric,renderDirectory,rawCensusValue} from './census-map-year-20261017-zoom-v24.js?v=home-click-20261114';
export function overlaps(a, b) {
    return a[0] <= b[2] && a[2] >= b[0] && a[1] <= b[3] && a[3] >= b[1];
}

function mapWarning(message) {
    const diagnostics=document.getElementById('map-diagnostics');
    if(diagnostics&&!Array.from(diagnostics.children).some(item=>item.textContent===message)){
        const report=document.createElement('p');report.textContent=message;diagnostics.append(report);
    }
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
    if (typeof value !== 'string' || !value.trim()) return false;
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

export function overviewValues(properties, groups) {
    const parents=properties.lgd_districts||[];
    if(properties.subdistrict_code)return groups[`${properties.state_code}:${properties.district_code}:${properties.subdistrict_code}`]||null;
    if(parents.length!==1)return null;
    return groups[`${parents[0].state}:${parents[0].district}:`]||null;
}

export function datedOverviewValues(properties, current, historical, year) {
    if (String(year) !== '2001') return current;
    const values = overviewValues(properties, historical?.groups || {});
    return {...current, population: values?.population ?? null, households: values?.households ?? null,
        literacy: values?.literacy ?? null, counts: {...current?.counts, ...values?.counts}};
}

export function electoralName(value) {
    return String(value || '').normalize('NFKD').toLowerCase().replace(/\((sc|st)\)/g,'').replace(/&/g,'and').replace(/^the /,'').replace(/[^a-z0-9]/g,'');
}

export function electoralChildren(features, pc) {
    const sameState=features.filter(feature=>feature.properties.kind==='ac'&&electoralName(feature.properties.state)===electoralName(pc.properties.state));
    const byParent=sameState.filter(feature=>String(feature.properties.parent_pc_code)===String(pc.properties.code));
    if(byParent.length&&byParent.every(feature=>geometryHoldsCentre(pc.geometry,feature.geometry)))return byParent;
    const spatial=sameState.filter(feature=>geometryHoldsCentre(pc.geometry,feature.geometry));
    return spatial.length?spatial:byParent;
}

function geometryRings(geometry){return geometry.type==='Polygon'?geometry.coordinates:geometry.coordinates.flat();}

function geometryCentre(geometry){let west=Infinity,south=Infinity,east=-Infinity,north=-Infinity;for(const ring of geometryRings(geometry))for(const [x,y] of ring){west=Math.min(west,x);east=Math.max(east,x);south=Math.min(south,y);north=Math.max(north,y);}return [(west+east)/2,(south+north)/2];}

function geometryHoldsCentre(outer,inner){
    const [x,y]=geometryCentre(inner);let inside=false;
    for(const ring of geometryRings(outer))for(let i=0,j=ring.length-1;i<ring.length;j=i++){const [xi,yi]=ring[i],[xj,yj]=ring[j];if(((yi>y)!==(yj>y))&&(x<(xj-xi)*(y-yi)/(yj-yi)+xi))inside=!inside;}
    return inside;
}

export function metricIndices(count, records, metric, min, max, missing, membership) {
 const values=new Map(records.map(r=>[r.feature_index,r.values[metric]]));
 return (membership||Array.from({length:count},(_,i)=>i)).filter(i=>{const v=values.get(i);return !metric||(v==null?missing:(min===''||v>=Number(min))&&(max===''||v<=Number(max)));});
}

export const mapMetricColours=['#ea4335','#fbbc05','#4285f4','#a142f4','#34a853'];

export function constituencyValues(feature,official,groups) {
    const cache=constituencyValues.keys??=new WeakMap();
    if(!cache.has(official))cache.set(official,new Map());
    const keys=cache.get(official),p=feature.properties,identity=JSON.stringify([p.state,p.kind,p.name]);
    if(!keys.has(identity)){
        const states=official.states.filter(s=>electoralName(s.name)===electoralName(p.state));
        const seats=states.length===1?official.seats.filter(s=>s.state===states[0].code&&s.kind===p.kind&&electoralName(s.name)===electoralName(p.name)):[];
        keys.set(identity,seats.length===1?`${seats[0].state}:${seats[0].kind}:${seats[0].code}`:null);
    }
    const key=keys.get(identity);return key?groups[key]||null:null;
}

export function settlementDisplayLinks(links,catalogue) {
    const verified=link=>catalogue.some(layer=>layer.file===link.file&&layer.sha256===link.sha256);
    const canonical=links.filter(verified),codes=new Set(canonical.flatMap(link=>link.indices.map(i=>link.codes[String(i)]).filter(Boolean)));
    return [...canonical,...links.filter(link=>!verified(link)).map(link=>({...link,indices:link.indices.filter(i=>!codes.has(link.codes[String(i)]))})).filter(link=>link.indices.length)];
}

async function start(section) {
    const status = document.getElementById('map-status');
    const searchStatus = document.getElementById('search-status');
    const results = document.getElementById('search-results');
    const details = document.getElementById('place-details');
    const query = document.getElementById('village-search');
    const state = document.getElementById('state-filter');
    const district = document.getElementById('district-filter');
    const stateOnly=section.dataset.stateOnly==='true';
    const inlineCatalogue=stateOnly?document.getElementById('state-map-catalogue'):null;
    let catalogue={layers:[],context_layers:[]};const byId=new Map();
    const cataloguePromise=inlineCatalogue?Promise.resolve(JSON.parse(inlineCatalogue.textContent)):fetch(section.dataset.catalogue).then(response=>{if(!response.ok)throw new Error('Coverage register unavailable. Refresh to retry.');return response.json();});
    let fitSelection=()=>{}, map, ready = false, generation = 0, searchGeneration = 0, searchAbort, timer, electoral;
    const loaded = new Set(), pending = new Map(), metricLayers=new Map();
    const metric=document.getElementById('colour-metric'),minimum=document.getElementById('metric-min'),maximum=document.getElementById('metric-max'),missing=document.getElementById('metric-missing'),legend=document.getElementById('metric-legend');
    addJjmMeasures(metric);
    let selectedCensusPlace=null;
    const groundwater=new GroundwaterMapLayer({metric,legend,map:()=>map,scope:()=>({state:officialDirectory.states.find(s=>electoralName(s.name)===electoralName(state.value))?.code||'',district:adminDistrict.value||'',village:villageFilter.value||''})});
    const censusYear=yearControl(metric,()=>{for(const e of metricLayers.values()){e.censusVersion=(e.censusVersion||0)+1;applyCensusYear(e);}if(selectedCensusPlace)display(selectedCensusPlace);if(ready&&map.getSource('admin-boundaries'))map.getSource('admin-boundaries').setData(overviewCollection());refreshElectoralSummary();updateMetrics();});
    async function applyCensusYear(e){
        if(!e.baseline2011)return;const version=++e.censusVersion,year=censusYear.value;
        e.censusLoading=year==='2001';e.records=datedRecords(e.baseline2011,{},year);writeCensusValues(e);
        if(year==='2001'){
            try{const history=await loadHistory(e.baseline2011.filter(row=>row.level!=='TOWN').map(row=>row.lgd_code));if(version!==e.censusVersion||metricLayers.get(e.layer.id)!==e)return;e.records=datedRecords(e.baseline2011,history,year);e.error='';}
            catch(error){if(version===e.censusVersion)e.error=error.message;}
        }
        if(version===e.censusVersion){e.censusLoading=false;writeCensusValues(e);if(isNativeCensusField(metric.value))updateMetrics();else metricCoverage();}
    }
    function writeCensusValues(e){if(!ready||!map.getSource(`villages-${e.layer.id}`))return;for(let i=0;i<e.count;i++)map.setFeatureState({source:`villages-${e.layer.id}`,id:i},{population:null,households:null,literacy:null});for(const row of e.records)map.setFeatureState({source:`villages-${e.layer.id}`,id:row.feature_index},row.values);paintMetrics(e.layer);}

    const breaks={population:[500,2000,5000,10000],households:[100,250,500,1000],literacy:[50,65,80,90],water:[25,50,75,100],rainfall:[500,1000,1500,2000]},overviewBreaks={population:[50000,200000,500000,1000000],households:[10000,50000,100000,250000],literacy:[50,65,80,90],water:[25,50,75,100],rainfall:[500,1000,1500,2000]},colours=mapMetricColours;
    for(const [key,[label]] of Object.entries(soilMetrics)){breaks[key]=overviewBreaks[key]=[25,50,75,100];const option=node('option',label);option.value=key;metric.append(option);}
    for(const key of ['water_households','water_taps']){breaks[key]=[100,250,500,1000];overviewBreaks[key]=[10000,50000,100000,250000];}
    breaks.satellite=overviewBreaks.satellite=[-.1,-.02,.02,.1];
    {const option=node('option','Satellite \u00b7 vegetation change (NDVI)');option.value='satellite';metric.append(option);}
    const satelliteActions=node('div');satelliteActions.id='satellite-actions';satelliteActions.hidden=true;legend.after(satelliteActions);
    let satelliteError='';
    function scheduleSatellite(){
        satelliteActions.hidden=metric.value!=='satellite';if(metric.value!=='satellite'||!ready)return;
        satelliteDisclaimer();satelliteActions.replaceChildren();const comparisonButtons=new Set();
        for(const e of metricLayers.values()){
            if(e.layer.kind==='district')continue;
            if(e.satelliteResult){
                e.records=mergeSatellite(e.records,e.satelliteResult.data,e.layer.sha256);
                for(const row of e.records)map.setFeatureState({source:`villages-${e.layer.id}`,id:row.feature_index},{satellite:row.values.satellite??null});
                if(comparisonButtons.has(e.satelliteResult.entry.base)){paintMetrics(e.layer);continue;}comparisonButtons.add(e.satelliteResult.entry.base);
                const button=node('button',`Compare ${e.satelliteResult.entry.district} images \u2192`);button.type='button';
                button.addEventListener('click',async()=>{
                    const old=document.getElementById('satellite-comparison');if(old){old.dispatchEvent(new Event('satellite-dispose'));old.remove();document.getElementById('satellite-disclaimer')?.remove();}
                    const module=await import('./satellite-comparison-20261018-highlight.js?v=20261024-all-districts');
                    await module.mountSatelliteComparison({home:true,anchor:section,entry:e.satelliteResult.entry,indices:e.satelliteSelection||e.layer.indices||[]});
                    document.getElementById('satellite-comparison')?.scrollIntoView({behavior:'smooth',block:'start'});
                });satelliteActions.append(button);paintMetrics(e.layer);
            }else if(!e.satelliteRequested){
                e.satelliteRequested=true;satelliteForLayer(e.layer).then(result=>{if(metricLayers.get(e.layer.id)!==e)return;e.satelliteResult=result;e.satelliteComplete=true;scheduleSatellite();metricCoverage();}).catch(error=>{e.satelliteComplete=true;satelliteError=error.message;metricCoverage();});
            }
        }
    }
    let soilData=null,soilLoading=null,soilError='',soilRequest=0,soilSignature='';
    let soilElectoralData=null,soilElectoralCycle='',soilElectoralError='';
    const soilCycle=soilCycleControl(metric,()=>{soilSignature='';updateMetrics();});
    function scheduleSoilElectoral(){
        if(!isSoil(metric.value)||!ready||!map.getSource('electoral-boundaries')||soilElectoralCycle===soilCycle.value)return;
        const cycle=soilCycle.value;soilElectoralCycle=cycle;soilElectoralData=null;soilElectoralError='';refreshElectoralSummary();
        loadSoilConstituencies(cycle).then(data=>{if(soilElectoralCycle!==cycle)return;soilElectoralData=data;refreshElectoralSummary();}).catch(error=>{if(soilElectoralCycle!==cycle)return;soilElectoralError=error.message;metricCoverage();});
    }
    function scheduleSoil(){
        if(!isSoil(metric.value)||!ready)return;soilDisclaimer();
        scheduleSoilElectoral();
        const codes=[...new Set([...metricLayers.values()].flatMap(e=>e.records.map(soilCode)).filter(Boolean))].sort();
        const signature=soilCycle.value+':'+codes.join(',');
        if(signature!==soilSignature){soilSignature=signature;soilRequest++;soilData=null;soilLoading=null;soilError='';
            for(const e of metricLayers.values()){e.records=linkSoil(e.records,{records:[]});for(const row of e.records)if(map.getSource(`villages-${e.layer.id}`))map.setFeatureState({source:`villages-${e.layer.id}`,id:row.feature_index},row.values);}
        }
        if(!soilData){if(!soilLoading){const version=soilRequest;soilLoading=loadSoil(soilCycle.value,codes).then(data=>{if(version!==soilRequest)return;soilData=data;soilError='';if(map.getSource('admin-boundaries'))map.getSource('admin-boundaries').setData(overviewCollection());scheduleSoil();paintOverview();}).catch(error=>{if(version!==soilRequest)return;soilError=error.message;soilLoading=null;metricCoverage();});}return;}
        for(const e of metricLayers.values()){if(e.layer.kind==='district'||!e.complete)continue;e.records=linkSoil(e.records,soilData);for(const row of e.records)map.setFeatureState({source:`villages-${e.layer.id}`,id:row.feature_index},row.values);paintMetrics(e.layer);}
        metricCoverage();
    }
    const rainfallClient=new RainfallClient(),rainfallYear=node('select'),rainfallLabel=node('label','Rainfall year ');
    rainfallYear.id='home-rainfall-year';rainfallLabel.htmlFor=rainfallYear.id;rainfallLabel.hidden=true;rainfallLabel.style.display='none';
    for(const year of ['2024','2023']){const option=node('option',year);option.value=year;rainfallYear.append(option);}rainfallYear.style.width='5.5rem';rainfallYear.style.minWidth='0';rainfallLabel.append(rainfallYear);missing.parentElement.after(rainfallLabel);
    {const option=node('option','Rainfall \u00b7 IMD grid estimate (mm)');option.value='rainfall';metric.append(option);}
    let rainAdminRequest=null,rainElectoralRequest=null,rainError='';
    const sameRainGeometry=(a,b)=>a?.length===b?.length&&a.every((f,i)=>f.geometry===b[i].geometry);
    function scheduleRainfall(){
        rainfallLabel.hidden=metric.value!=='rainfall';rainfallLabel.style.display=rainfallLabel.hidden?'none':'';if(!ready||metric.value!=='rainfall')return;
        const year=rainfallYear.value;
        if(adminFeatures.length&&(!rainAdminRequest||rainAdminRequest.year!==year||!sameRainGeometry(rainAdminRequest.features,adminFeatures))){
            const job={features:adminFeatures,year};rainAdminRequest=job;
            rainfallClient.values(job.features).then(()=>{if(rainAdminRequest!==job||!sameRainGeometry(job.features,adminFeatures))return;job.complete=true;if(map.getSource('admin-boundaries'))map.getSource('admin-boundaries').setData(overviewCollection());paintOverview();metricCoverage();}).catch(error=>{rainError=error.message;metricCoverage();});
        }
        if(electoralSummaryFeatures.length&&(!rainElectoralRequest||rainElectoralRequest.year!==year||!sameRainGeometry(rainElectoralRequest.features,electoralSummaryFeatures))){
            const job={features:electoralSummaryFeatures,year};rainElectoralRequest=job;
            rainfallClient.values(job.features).then(()=>{if(rainElectoralRequest!==job||!sameRainGeometry(job.features,electoralSummaryFeatures))return;job.complete=true;refreshElectoralSummary();metricCoverage();}).catch(error=>{rainError=error.message;metricCoverage();});
        }
        for(const e of metricLayers.values()){
            if(e.layer.kind==='district'||e.rainYear===year)continue;e.rainYear=year;e.rainComplete=false;
            rainfallClient.values(e.features).then(values=>{if(metricLayers.get(e.layer.id)!==e||e.rainYear!==year)return;e.rainfallRows=values;e.rainComplete=true;e.records=mergeRainfallRecords(e.records,values,year);for(const row of e.records)map.setFeatureState({source:`villages-${e.layer.id}`,id:row.feature_index},{rainfall:row.values.rainfall});paintMetrics(e.layer);metricCoverage();}).catch(error=>{e.rainComplete=true;rainError=error.message;metricCoverage();});
        }
    }
    rainfallYear.addEventListener('change',()=>{minimum.value='';maximum.value='';updateMetrics();});
    let overviewData=null,overviewPromise=null,overviewError='',overview2001Data=null,overview2001Promise=null;
    let electoralSummaryData=null,electoralSummaryPromise=null,electoralSummaryError='',electoralSummaryFeatures=[],refreshElectoralSummary=()=>{};
    const villageMetricCount=()=>[...metricLayers.values()].filter(e=>e.layer.kind!=='district').reduce((count,e)=>count+(e.layer.indices?.length??e.count),0);
    function paintOverview(){
        if(!ready||!map.getLayer('admin-boundaries'))return;
        const active=metric.value&&!villageMetricCount();
        if(active){
            const b=overviewBreaks[metric.value]||[1,5,10,50];
            map.setPaintProperty('admin-boundaries','fill-color',['case',['==',['get',`overview_${metric.value}`],null],'#9ca3af',['step',['coalesce',['get',`overview_${metric.value}`],0],(isSoil(metric.value)?[...colours].reverse():colours)[0],...b.flatMap((value,index)=>[value,(isSoil(metric.value)?[...colours].reverse():colours)[index+1]])]]);
            map.setPaintProperty('admin-boundaries','fill-opacity',0.75);
            map.setFilter('admin-boundaries',['case',['==',['get',`overview_${metric.value}`],null],missing.checked,['all',minimum.value===''?true:['>=',['get',`overview_${metric.value}`],Number(minimum.value)],maximum.value===''?true:['<=',['get',`overview_${metric.value}`],Number(maximum.value)]]]);
        }else{
            map.setPaintProperty('admin-boundaries','fill-color','#b3d6c8');map.setPaintProperty('admin-boundaries','fill-opacity',['case',['boolean',['get','context'],false],0.13,0.5]);map.setFilter('admin-boundaries',null);
        }
    }
    function paintElectoralOverview(){
        if(!ready||!map.getLayer('electoral-boundaries'))return;
        const active=metric.value&&!villageMetricCount();
        if(active){
            const b=overviewBreaks[metric.value]||[1,5,10,50];
            map.setPaintProperty('electoral-boundaries','fill-color',['case',['==',['get',`overview_${metric.value}`],null],'#9ca3af',['step',['coalesce',['get',`overview_${metric.value}`],0],(isSoil(metric.value)?[...colours].reverse():colours)[0],...b.flatMap((value,index)=>[value,(isSoil(metric.value)?[...colours].reverse():colours)[index+1]])]]);
            map.setPaintProperty('electoral-boundaries','fill-opacity',0.75);
            map.setFilter('electoral-boundaries',['case',['==',['get',`overview_${metric.value}`],null],missing.checked,['all',minimum.value===''?true:['>=',['get',`overview_${metric.value}`],Number(minimum.value)],maximum.value===''?true:['<=',['get',`overview_${metric.value}`],Number(maximum.value)]]]);
        }else{
            map.setPaintProperty('electoral-boundaries','fill-color','#b3d6c8');map.setPaintProperty('electoral-boundaries','fill-opacity',villageMetricCount()?0.12:0.35);map.setFilter('electoral-boundaries',null);
        }
    }
    let electoral2001=null;const electoral2001Fields=new Map(),electoral2001Pending=new Map();
    function loadElectoral2001(){
        if(censusYear.value!=='2001'||!ready||!map.getLayer('electoral-boundaries')||!censusMetric(metric.value))return;
        const field=isNativeCensusField(metric.value)?metric.value:'overview';
        if((field==='overview'?electoral2001:electoral2001Fields.has(field))||electoral2001Pending.has(field))return;
        electoral2001Pending.set(field,publishedMapFetch('/maps/constituency-fields-2001/'+encodeURIComponent(field)+'.json').then(r=>{if(!r.ok)throw Error('Census 2001 constituency values unavailable');return r.json();}).then(data=>{if(data.year!==2001)throw Error('Unexpected Census year');if(field==='overview')electoral2001=data;else electoral2001Fields.set(field,data);refreshElectoralSummary();updateMetrics();}).catch(error=>{electoralSummaryError=error.message;metricCoverage();}));
    }
    function loadElectoralOverview(){
        loadElectoral2001();
        if(isSoil(metric.value)||['rainfall','satellite'].includes(metric.value)||!metric.value||electoralSummaryData||electoralSummaryPromise||!ready||!map.getLayer('electoral-boundaries'))return;
        electoralSummaryPromise=publishedMapFetch(new URL('constituency-map-metrics.json?v=water-20261014',section.dataset.adminOutlines),{cache:'no-cache'}).then(r=>{if(!r.ok)throw new Error('Constituency village summaries are temporarily unavailable.');return r.json();}).then(data=>{electoralSummaryData=data;refreshElectoralSummary();}).catch(error=>{electoralSummaryError=error.message;metricCoverage();});
    }
    const datedOverview=(values,properties)=>datedOverviewValues(properties,values,overview2001Data,censusYear.value);
    function overviewCollection(){return {type:'FeatureCollection',features:adminFeatures.map(feature=>{const values=datedOverview(overviewValues(feature.properties,overviewData?.groups||{}),feature.properties);return {...feature,properties:{...feature.properties,display_id:feature.id,...Object.fromEntries(Object.entries(soilValues(soilOverview(feature.properties,soilData))).map(([key,value])=>['overview_'+key,value])),...Object.fromEntries(['P_SCH','M_SCH','S_SCH','S_S_SCH','COLLEGE'].map(field=>['overview_vd_'+field,censusYear.value==='2001'?schoolOverviewTotal(facilityDistribution(feature.properties,facilityData,field),field):null])),...(isNativeCensusField(metric.value)?{['overview_'+metric.value]:nativeOverviewValue(nativeSummary(feature.properties,nativeData.get(metric.value)),nativeManifest?.fields?.[metric.value]?.kind)}:{}),overview_population:(values?.population??null),overview_households:(values?.households??null),overview_literacy:(values?.literacy??null),overview_rainfall:rainfallClient.value(feature,rainfallYear.value),overview_water_households:values?.water_households??null,overview_water_taps:values?.water_taps??null,overview_water:values?.water??null,overview_water_records:values?.water_records??0}};})};}
    let facilityData=null,facilityPromise=null;

    let nativeManifest=null,nativeManifestPromise=null,nativeError='';const nativeData=new Map(),nativePending=new Map();

    function scheduleNativeFields(){if(metric.value.endsWith('_UNAVAILABLE')){nativeData.set(metric.value,{groups:{}});return;}

        if(censusYear.value==='2011'){
            const field=metric.value;if(!field.startsWith('c11_')||nativeData.has(field)||nativePending.has(field))return;
            nativePending.set(field,publishedMapFetch('/maps/census-2011-fields/'+encodeURIComponent(field)+'.json').then(r=>{if(!r.ok)throw Error('Published 2011 summary unavailable');return r.json();}).then(data=>{if(data.year!==2011||data.field!==field)throw Error('Unexpected Census summary');nativeManifest??={fields:{}};nativeManifest.fields[field]={kind:'sum'};nativeData.set(field,data);if(ready&&map.getSource('admin-boundaries'))map.getSource('admin-boundaries').setData(overviewCollection());updateMetrics();}).catch(error=>{nativeError=error.message;metricCoverage();}));return;
        }

        if(!nativeManifest?.year){if(!nativeManifestPromise)nativeManifestPromise=publishedMapFetch('/maps/census-2001-fields/manifest.json?v=all-1').then(r=>{if(!r.ok)throw Error('Census field catalogue unavailable');return r.json();}).then(data=>{nativeManifest={...data,fields:{...nativeManifest?.fields,...data.fields}};for(const prefix of ['vd_','pca_'])window.dispatchEvent(new CustomEvent(prefix==='vd_'?'census-directory-fields':'census-pca-fields',{detail:Object.fromEntries(Object.entries(data.fields).filter(([k])=>k.startsWith(prefix)).map(([k,v])=>[k.slice(prefix.length),v.label]))}));scheduleNativeFields();}).catch(error=>{nativeError=error.message;metricCoverage();});return;}

        const field=metric.value;if(!isNativeCensusField(field)||nativeData.has(field)||nativePending.has(field))return;

        nativePending.set(field,publishedMapFetch('/maps/census-2001-fields/'+encodeURIComponent(nativeManifest.fields[field]?.file||'unavailable')+'?v=all-1').then(r=>{if(!r.ok)throw Error('Published field summary unavailable');return r.json();}).then(data=>{if(data.year!==2001||data.field!==field)throw Error('Unexpected field summary');nativeData.set(field,data);nativeError='';if(ready&&map.getSource('admin-boundaries'))map.getSource('admin-boundaries').setData(overviewCollection());updateMetrics();}).catch(error=>{nativeError=error.message;metricCoverage();}));

    }


    function loadOverview(){
        scheduleNativeFields();
            if(censusYear.value==='2001'&&censusMetric(metric.value)&&!overview2001Data&&!overview2001Promise){
                overview2001Promise=publishedMapFetch(new URL('india-overview-2001-metrics.json?v=20261015-1',section.dataset.adminOutlines),{cache:'no-cache'})
                    .then(response=>{if(!response.ok)throw new Error('Census 2001 district summaries are temporarily unavailable.');return response.json();})
                    .then(data=>{if(data.year!==2001)throw new Error('Unexpected Census overview year.');overview2001Data=data;overviewError='';if(ready&&map.getSource('admin-boundaries'))map.getSource('admin-boundaries').setData(overviewCollection());updateMetrics();})
                    .catch(error=>{overviewError=error.message;overview2001Promise=null;metricCoverage();});
            }
    
        if(isSoil(metric.value)||['rainfall','satellite'].includes(metric.value)||!metric.value||overviewData||overviewPromise)return;
        overviewPromise=publishedMapFetch(new URL('india-overview-metrics.json?v=water-20261014',section.dataset.adminOutlines),{cache:'no-cache'}).then(response=>{if(!response.ok)throw new Error('Overview statistics are temporarily unavailable.');return response.json();}).then(data=>{overviewData=data;overviewError='';if(ready&&map.getSource('admin-boundaries'))map.getSource('admin-boundaries').setData(overviewCollection());updateMetrics();}).catch(error=>{overviewError=error.message;overviewPromise=null;metricCoverage();});
    }
    loadOverview();
    function paintMetrics(layer){
        const e=metricLayers.get(layer.id),id=`villages-${layer.id}`;if(!e||!map.getLayer(id))return;
        const enabled=metric.value&&layer.kind!=='district';
        map.setPaintProperty(id,'fill-color',enabled?['case',['==',['feature-state',metric.value],null],'#9ca3af',['step',['coalesce',['feature-state',metric.value],0],(isSoil(metric.value)?[...colours].reverse():colours)[0],...(breaks[metric.value]||[1,5,10,50]).flatMap((v,i)=>[v,(isSoil(metric.value)?[...colours].reverse():colours)[i+1]])]]:layer.kind==='district'?'#71879d':'#168b78');
        if(enabled&&isNativeCensusField(metric.value))map.setPaintProperty(id,'fill-color',nativeVillageColours(metric.value,breaks[metric.value]||[1,5,10,50],colours));map.setPaintProperty(id,'fill-opacity',enabled?0.72:0.22);
        map.setFilter(id,['in',['id'],['literal',metricIndices(e.count,e.records,enabled?metric.value:'',minimum.value,maximum.value,missing.checked,layer.indices)]]);
    }
    async function loadMetrics(layer){
        const e=metricLayers.get(layer.id);if(!e||['rainfall','satellite'].includes(metric.value)||!metric.value||layer.kind==='district'||e.promise)return;
        e.promise=(async()=>{try{
            const r=await fetch(`${section.dataset.metricsBase}/${layer.metricId||layer.id}?sha=${layer.sha256}&year=2011`,{signal:e.controller.signal});
            if(!r.ok)throw new Error('Some statistics are unavailable. Grey means no verified value.');
            const data=await r.json();data.records=jjmRecords(data.records);if(metricLayers.get(layer.id)!==e)return;e.baseline2011=data.records;e.censusVersion=0;e.records=e.rainfallRows?mergeRainfallRecords(data.records,e.rainfallRows,rainfallYear.value):data.records;e.complete=true;if(selectedCensusPlace?.layer===layer.id){const row=e.baseline2011.find(r=>Number(r.feature_index)===Number(selectedCensusPlace.feature_index));display({...selectedCensusPlace,water:row?.water||null,water_note:row?.water?null:jjmMissingNote(row||{lgd_code:selectedCensusPlace.lgd_code})});}await applyCensusYear(e);
            for(const row of e.records)map.setFeatureState({source:`villages-${layer.id}`,id:row.feature_index},row.values);
            paintMetrics(layer);scheduleSoil();scheduleSatellite();metricCoverage();
        }catch(error){if(error.name!=='AbortError'){e.error=error.message;e.complete=true;metricCoverage();}}})();
    }
    function metricCoverage(){
        if(groundwater.active()){minimum.disabled=maximum.disabled=missing.disabled=true;const note=document.querySelector('.metric-controls>p:not(#census-year-note)');if(note)note.textContent='Historical monitoring locations with observation dates; no village-wide estimates or drinking-water safety inference.';groundwater.sync();return;}
        const yearNote=document.querySelector('.metric-controls>p:not(#census-year-note)');if(yearNote)yearNote.textContent=`Census ${censusYear.value} | Village values only. Literacy uses population aged 7+. Select a village for linked details.`;
        if(censusYear.value==='2001'&&censusMetric(metric.value)){
            const note=document.querySelector('.metric-controls>p:not(#census-year-note)');if(note)note.textContent=metric.value.startsWith('vd_')?(villageMetricCount()?'Census 2001 | Original village facility values and codes; missing cells remain unavailable.':'Census 2001 | School colours show sums of original numeric counts across linked villages. Water codes are shown as distributions on hover, not summed. Coverage varies; current boundaries may differ.'):'Census 2001 | Available linked values. Literacy uses population aged 7+. Current boundaries; historical comparability unverified.';
            let line=legend.querySelector('.metric-coverage');if(!line){line=node('span');line.className='metric-coverage';legend.append(line);}
            let count=0,total=0;for(const e of metricLayers.values()){total+=e.count;count+=e.records.filter(row=>row.values[metric.value]!=null).length;}
            if(!total&&document.getElementById('path-electoral')?.getAttribute('aria-pressed')==='true'){
                const available=electoralSummaryFeatures.filter(f=>f.properties['overview_'+metric.value]!=null).length;
                line.textContent=electoralSummaryError||`${available} of ${electoralSummaryFeatures.length} constituency outlines have Census 2001 values. Existing full village memberships only; partial coverage, not historical boundary totals.`;
                return;
            }
            const historyError=[...metricLayers.values()].find(e=>e.error)?.error;
            line.textContent=historyError?historyError:[...metricLayers.values()].some(e=>e.censusLoading||!e.complete)?'Loading verified Census 2001 village values...':total?`${count} of ${total} source shapes have Census 2001 ${metric.value} values. Grey: unavailable.`:'Census 2001: overview colours use available linked village records grouped by current districts. Coverage varies; historical boundaries are not verified. Select a district for village values.';return;
        }
        const diagnostics=document.getElementById('map-diagnostics');
        let guidance=document.getElementById('metric-guidance');if(!guidance){guidance=node('p');guidance.id='metric-guidance';diagnostics.append(guidance);}
        const villageCount=villageMetricCount();
        const electoralOverview=document.getElementById('path-electoral').getAttribute('aria-pressed')==='true'&&!villageCount;
        guidance.hidden=!metric.value||!electoralOverview;
        if(metric.value==='satellite'){
            guidance.hidden=true;const note=document.querySelector('.metric-controls>p');if(note)note.textContent='Vegetation change between matched seasonal dates. Brown: less green; green: greener. Values are NDVI differences, not percentages. Select a district or AC to see available village observations.';
            let line=legend.querySelector('.metric-coverage');if(!line){line=node('span');line.className='metric-coverage';legend.append(line);}
            let available=0;const dates=new Set();for(const e of metricLayers.values()){const included=e.layer.indices?new Set(e.layer.indices):null;available+=e.records.filter(r=>(!included||included.has(r.feature_index))&&r.values.satellite!=null).length;if(e.satelliteResult)dates.add(e.satelliteResult.data.dates.join(' \u2192 '));}
            line.textContent=satelliteError||`${available} displayed village shapes have verified comparisons${dates.size?' \u00b7 '+[...dates].join('; '):''}. Grey: not yet available. District and constituency totals are not inferred from partial coverage.`;return;
        }
        if(isSoil(metric.value)){
            guidance.hidden=true;const note=document.querySelector('.metric-controls>p');if(note)note.textContent=`Soil Health Card - ${soilCycle.value} - Percentage of tested samples. ${electoralOverview?'PC/AC colours summarize linked village samples through official LGD membership.':'District colours show official district sample summaries; village colours show only linked village samples.'}`;
            let line=legend.querySelector('.metric-coverage');if(!line){line=node('span');line.className='metric-coverage';legend.append(line);}
            let available=0;for(const e of metricLayers.values()){const included=e.layer.indices?new Set(e.layer.indices):null;available+=e.records.filter(r=>(!included||included.has(r.feature_index))&&r.values[metric.value]!=null).length;}
            const coverage=villageCount?`${available} displayed village shapes have soil sample results`:electoralOverview?`${electoralSummaryFeatures.filter(feature=>feature.properties['overview_'+metric.value]!=null).length} constituency outlines have linked village sample results`:`${adminFeatures.filter(feature=>soilValues(soilOverview(feature.properties,soilData))[metric.value]!=null).length} district outlines have official sample summaries`;line.textContent=soilError||(electoralOverview&&soilElectoralError)||(!soilData||(electoralOverview&&!soilElectoralData)?'Loading verified soil records\u2026':`${coverage}. Grey: unavailable. National snapshot: ${soilData.linked_villages.toLocaleString()} linked villages in ${soilCycle.value}.${soilData.complete?'':' Collection in progress.'}`);return;
        }
        if(metric.value==='rainfall'){
            guidance.hidden=true;const note=document.querySelector('.metric-controls>p');if(note)note.textContent=`IMD ${rainfallYear.value} \u00b7 Annual rainfall (mm) \u00b7 0.25\u00b0 grid estimate, not a village measurement. Hover for values; select an area to explore.`;
            let line=legend.querySelector('.metric-coverage');if(!line){line=node('span');line.className='metric-coverage';legend.append(line);}
            let count=0,available=0,pending=false;
            if(villageCount){for(const e of metricLayers.values()){if(e.layer.kind==='district')continue;const included=e.layer.indices?new Set(e.layer.indices):null;count+=included?.size??e.count;available+=e.records.filter(r=>(!included||included.has(r.feature_index))&&r.values.rainfall!=null).length;pending||=!e.rainComplete;}}
            else{const features=electoralOverview?electoralSummaryFeatures:adminFeatures;count=features.length;available=features.filter(f=>rainfallClient.value(f,rainfallYear.value)!=null).length;pending=!(electoralOverview?rainElectoralRequest:rainAdminRequest)?.complete;}
            line.textContent=rainError|| (pending?'Calculating rainfall for displayed areas\u2026':`${available.toLocaleString('en-IN')} of ${count.toLocaleString('en-IN')} displayed areas have complete IMD rainfall coverage.`);return;
        }
        guidance.textContent='Colours summarize fully covered villages linked through official LGD constituency membership. Towns, wards and partial/conflicting membership are excluded. These are linked-record summaries, not official constituency totals.';
        const note=document.querySelector('.metric-controls>p');if(note)note.textContent=isJjm(metric.value)?jjmNote:villageCount?'Census 2011 \u00b7 Village and town values. Literacy uses population aged 7+. Hover for values; click for linked details.':electoralOverview?'Census 2011 \u00b7 Linked village summaries by official LGD PC/AC membership. Literacy uses population aged 7+. Hover for values; choose an AC to explore places.':'Census 2011 \u00b7 Linked village summaries by district and subdistrict. Literacy uses population aged 7+. Hover for values; click to explore places.';
        let line=legend.querySelector('.metric-coverage');if(!line){line=node('span');line.className='metric-coverage';legend.append(line);}
        if(!metric.value){line.remove();return;}
        let total=0,available=0,loading=false;
        for(const e of metricLayers.values()){if(e.layer.kind==='district')continue;const indices=e.layer.indices?new Set(e.layer.indices):null;total+=indices?.size??e.count;available+=e.records.filter(r=>(!indices||indices.has(r.feature_index))&&r.values[metric.value]!=null).length;loading||=!e.complete;}
        line.textContent=total?(loading?'Loading place statistics\u2026':`${available.toLocaleString('en-IN')} places with ${isJjm(metric.value)?'tap connection coverage':metric.value} values \u00b7 ${isJjm(metric.value)?'JJM':'Census 2011'}.`):electoralOverview?(!electoralSummaryData&&!electoralSummaryError?'Loading constituency summaries\u2026':'Constituency colours show linked village summaries.') : !overviewData&&!overviewError?'Loading district summaries\u2026':'District colours show linked village summaries.';
        let report=document.getElementById('map-data-diagnostics');if(!report){report=node('p');report.id='map-data-diagnostics';diagnostics.append(report);}
        const errors=[overviewError,electoralSummaryError,...Array.from(metricLayers.values(),e=>e.error)].filter(Boolean);
        report.textContent=[...new Set(errors),total&&!loading?`${available.toLocaleString('en-IN')} of ${total.toLocaleString('en-IN')} settlement shapes have ${metric.value} values.`:electoralOverview&&electoralSummaryData?`${electoralSummaryFeatures.filter(f=>f.properties[`overview_${metric.value}`]!=null).length} of ${electoralSummaryFeatures.length} constituency boundaries have linked village ${metric.value} summaries.`:'', 'Grey means unavailable or no unique verified link.'].filter(Boolean).join(' ');
    }
    function updateMetrics(){
        groundwater.sync();

        if(isNativeCensusField(metric.value)){

            const data=nativeData.get(metric.value),kind=nativeManifest?.fields?.[metric.value]?.kind;

            overviewBreaks[metric.value]=kind==='category'?[25,50,75,100]:nativeBreaks(adminFeatures.map(feature=>nativeOverviewValue(nativeSummary(feature.properties,data),kind)));

            if(censusYear.value==='2001'&&document.getElementById('path-electoral')?.getAttribute('aria-pressed')==='true'&&electoral2001Fields.has(metric.value)){const d=electoral2001Fields.get(metric.value);overviewBreaks[metric.value]=d.kind==='category'?[25,50,75,100]:nativeBreaks(Object.values(d.groups).map(g=>nativeOverviewValue(g,d.kind)));}
            breaks[metric.value]=nativeBreaks([...metricLayers.values()].flatMap(e=>e.records.map(r=>r.values[metric.value])));

            if(ready&&map.getSource('admin-boundaries'))map.getSource('admin-boundaries').setData(overviewCollection());

        }
        scheduleRainfall();scheduleSoil();scheduleSatellite();minimum.disabled=maximum.disabled=missing.disabled=!metric.value;legend.replaceChildren();
        if(metric.value){const b=(villageMetricCount()?breaks:overviewBreaks)[metric.value]||[1,5,10,50];for(let i=0;i<5;i++){const item=node('span',i===0?`< ${b[0]}`:i===4?`>= ${b[3]}`:`${b[i-1]} - < ${b[i]}`),swatch=node('i');swatch.style.background=(isSoil(metric.value)?[...colours].reverse():colours)[i];item.prepend(swatch);legend.append(item);}const item=node('span','Unavailable'),swatch=node('i');swatch.style.background='#9ca3af';item.prepend(swatch);legend.append(item);}else legend.textContent='Choose a measure to colour village and town polygons.';

        for(const e of metricLayers.values()){paintMetrics(e.layer);loadMetrics(e.layer);}
        paintOverview();loadOverview();paintElectoralOverview();loadElectoralOverview();metricCoverage();

        if(isNativeCensusField(metric.value)){

            const descriptor=nativeManifest?.fields?.[metric.value],note=node('span');note.className='metric-coverage';

            note.textContent=metric.value.endsWith('_UNAVAILABLE')?'Unavailable for this Census year.':nativeError||(villageMetricCount()?'Original village values. Blue indicates reported text; popups show the original text.':!nativeData.has(metric.value)?'Loading published Census field summaries...':descriptor?.kind==='category'?'Colours: percentage of linked records with this field reported. This is data coverage, not facility availability. Hover for original code/text distributions.':descriptor?.kind==='mean'?'Colours: mean of reported original numeric values.':'Colours: sum of reported original numeric values.');legend.append(note);

        }
    }
    for(const control of [metric,minimum,maximum,missing])control.addEventListener(control===minimum||control===maximum?'input':'change',()=>{if(control===metric){minimum.value='';maximum.value='';refreshElectoralSummary();}updateMetrics();if(control===metric&&isJjm(metric.value)&&selectedCensusPlace?.bbox)fit(selectedCensusPlace.bbox,13,0);});updateMetrics();
    let finderPath='admin',hierarchyVersion=0,villageVersion=0,areaVersion=0,outlinePromise,adminFeatures=[],subdistrictSources=new Map();
    let dashboardIndex={},electoralPromise,urbanLinksPromise;
    const hierarchyCache=new Map();
    function stateNavigation(){const st=officialDirectory.states.find(row=>electoralName(row.name)===electoralName(state.value));if(!st)return [];return [{label:`${st.name} state dashboard`,url:`${finderPath==='electoral'?'/india/state/':'/india/census/states/'}${st.name.toLowerCase().replace(/^the /,'').replace(/&/g,'and').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')}`}];}
    let selectionNavigation=[],selectedPlaceNavigation=[],navigationVersion=0;
    const navigationCache=new Map();
    function adminNavigation(){if(finderPath!=='admin')return;
        const st=officialDirectory.states.find(row=>electoralName(row.name)===electoralName(state.value));
        const hasVillage=selectedPlaceNavigation.some(d=>/village dashboard/i.test(d.label));
        const parents=selectionNavigation.length?selectionNavigation:(st&&adminDistrict.value?dashboardIndex[`${st.code}:${adminDistrict.value}`]?.navigation||[]:[]);
        showNavigation([...stateNavigation(),...parents.filter(d=>!hasVillage||!(/^(PC:|AC:|Lok Sabha \(PC\)|Assembly \(AC\))/i.test(d.label))),...selectedPlaceNavigation]);
    }
    async function refreshSelectionNavigation(administration=null){if(finderPath!=='admin'){++navigationVersion;selectionNavigation=[];return;}
        const st=officialDirectory.states.find(row=>electoralName(row.name)===electoralName(state.value));
        const params={state:administration?.state||st?.code,district:administration?.district_code||adminDistrict.value,subdistrict:administration?.subdistrict_code||adminSubdistrict.value};
        const token=++navigationVersion;selectionNavigation=[];adminNavigation();
        if(!params.state){adminNavigation();return;}
        const query=new URLSearchParams(Object.entries(params).filter(([,v])=>v));
        const url='/api/india-map/navigation?'+query;
        try{if(!navigationCache.has(url))navigationCache.set(url,fetch(url).then(r=>{if(!r.ok)throw Error('Selection links unavailable');return r.json();}).catch(e=>{navigationCache.delete(url);throw e;}));const result=await navigationCache.get(url);if(token!==navigationVersion)return;selectionNavigation=result.navigation||[];adminNavigation();}catch{if(token===navigationVersion)adminNavigation();}
    }
    async function enableElectoral(){electoralPromise??=startElectoral().catch(error=>{electoralPromise=null;throw error;});electoral=await electoralPromise;return electoral;}
    function showNavigation(destinations){const container=document.getElementById('area-navigation');container.replaceChildren();const accepted=destinations.filter(d=>!/^(?:Census \d{4} village|Village Directory|.*original facility)/i.test(d.label)&&safeLink(d.url)&&(!section.dataset.dashboardUrl||new URL(d.url,location.origin).pathname!==new URL(section.dataset.dashboardUrl).pathname));for(const destination of orderedPlaceNavigation(accepted,{mode:finderPath})){const a=node('a',destination.label+' →');a.href=statePageForMode(destination.url,finderPath);container.append(a);}}

    const villageFilter=document.getElementById('village-filter'),adminDistrict=document.getElementById('admin-district'),adminSubdistrict=document.getElementById('admin-subdistrict'),finderStatus=document.getElementById('finder-status');
    const officialDirectory=JSON.parse(document.getElementById('india-electoral-catalogue').textContent);
    if(stateOnly){const selected=officialDirectory.states.find(row=>String(row.code).replace(/^0+/, '')===String(section.dataset.initialState).replace(/^0+/, ''));if(selected){state.value=selected.name;for(const option of [...state.options])if(option.value!==state.value)option.remove();}}
    function choices(select,rows,label){select.replaceChildren(node('option',label));select.firstChild.value='';for(const row of rows){const option=node('option',`${row.name}${row.subdistrict?' \u00b7 '+row.subdistrict:''}`);option.value=row.code||row.lgd;select.append(option);}select.disabled=rows.length===0;}
    function pathUI(path){++areaVersion;finderPath=path;++navigationVersion;selectionNavigation=[];selectedPlaceNavigation=[];showNavigation(stateNavigation());if(ready&&path==='electoral'&&map.getLayer('admin-boundaries')){map.setLayoutProperty('admin-boundaries','visibility','none');map.setLayoutProperty('admin-lines','visibility','none');}document.getElementById('electoral-path').hidden=path!=='electoral';document.getElementById('admin-path').hidden=path!=='admin';document.getElementById('path-electoral').setAttribute('aria-pressed',String(path==='electoral'));document.getElementById('path-admin').setAttribute('aria-pressed',String(path==='admin'));document.getElementById('path-help').textContent=path==='electoral'?'State \u2192 PC \u2192 AC \u2192 Village':'State \u2192 District \u2192 Subdistrict \u2192 Village';}
    async function hierarchy(target,params,label){
        const version=++hierarchyVersion;choices(target,[],'Loading\u2026');finderStatus.textContent='';
        const st=officialDirectory.states.find(row=>electoralName(row.name)===electoralName(state.value));
        if(!st){choices(target,[],'Choose a state first');return;}
        try{const url=`${section.dataset.hierarchy}?${new URLSearchParams({state:st.code,...params})}`;if(!hierarchyCache.has(url))hierarchyCache.set(url,fetch(url).then(response=>{if(!response.ok)throw new Error('The official place list is temporarily unavailable.');return response.json();}).catch(error=>{hierarchyCache.delete(url);throw error;}));const data=structuredClone(await hierarchyCache.get(url));if(target===adminDistrict&&outlinePromise){const outlines=await outlinePromise;const codes=new Set(data.options.map(row=>row.code));for(const feature of outlines.features){const p=feature.properties;if(p.state_code===st.code&&p.district_code&&!codes.has(p.district_code)){data.options.push({code:p.district_code,name:p.name+' \u00b7 SOI district'});codes.add(p.district_code);}}data.options.sort((a,b)=>a.name.localeCompare(b.name));}if(version!==hierarchyVersion)return;choices(target,data.options,label);if(!data.options.length)finderStatus.textContent='No linked official records are available for this selection.';}catch(error){if(version===hierarchyVersion){choices(target,[],'Unavailable');mapWarning(error.message);}}
    }
    async function syncHierarchy(){++navigationVersion;selectionNavigation=[];selectedPlaceNavigation=[];selectedCensusPlace=null;showNavigation(stateNavigation());++hierarchyVersion;++villageVersion;choices(villageFilter,[],finderPath==='admin'?'Choose a subdistrict first':'Choose an AC first');choices(adminDistrict,[],'Choose a state first');choices(adminSubdistrict,[],'Choose a district first');finderStatus.textContent='';if(finderPath==='admin'){details.replaceChildren(node('h2','Explore by district'),node('p','Choose a state, then a district and subdistrict to find your village.'));electoral?.explore();drawAdmin();if(state.value)await hierarchy(adminDistrict,{},'Choose a district');}else{++areaVersion;if(ready&&map.getLayer('admin-boundaries')){map.setLayoutProperty('admin-boundaries','visibility','none');map.setLayoutProperty('admin-lines','visibility','none');}}}
    for(const path of ['admin','electoral'])document.getElementById(`path-${path}`).addEventListener('click',async()=>{pathUI(path);try{if(path==='electoral')await enableElectoral();if(finderPath!==path)return;electoral?.reset();prune(new Set());syncHierarchy();}catch(error){mapWarning(error.message);}});
    async function districtChanged(){++villageVersion;choices(villageFilter,[],'Choose a subdistrict first');choices(adminSubdistrict,[],'Choose a district first');drawAdmin();if(adminDistrict.value)await hierarchy(adminSubdistrict,{district:adminDistrict.value},'Choose a subdistrict');else ++hierarchyVersion;}
    async function subdistrictChanged(){++villageVersion;choices(villageFilter,[],'Choose a subdistrict first');drawAdmin();if(adminSubdistrict.value)await hierarchy(villageFilter,{district:adminDistrict.value,subdistrict:adminSubdistrict.value},'Choose a village');else ++hierarchyVersion;}
    adminDistrict.addEventListener('change',districtChanged);adminSubdistrict.addEventListener('change',subdistrictChanged);
    for(const control of [state,adminDistrict,adminSubdistrict])control.addEventListener('change',()=>queueMicrotask(()=>{selectedPlaceNavigation=[];refreshSelectionNavigation();}));
    for(const control of [state,adminDistrict,adminSubdistrict,villageFilter])control.addEventListener('change',()=>queueMicrotask(()=>groundwater.sync()));
    function fitFeatures(features,maxZoom=10){const bounds=new maplibregl.LngLatBounds();const visit=c=>{if(typeof c[0]==='number')bounds.extend(c.slice(0,2));else c.forEach(visit);};for(const f of features)if(f.geometry)visit(f.geometry.coordinates);if(!bounds.isEmpty()){fitSelection=()=>map.fitBounds(bounds,{padding:35,maxZoom,duration:450});fitSelection();}}
    async function drawAdmin(){
        if(!ready||finderPath!=='admin')return;const version=++areaVersion;adminNavigation();prune(new Set());electoral?.explore();status.textContent='Loading district boundaries\u2026';
        try{
            outlinePromise??=fetch(section.dataset.adminOutlines).then(r=>{if(!r.ok)throw new Error('District display boundaries are unavailable.');return r.json();}).catch(e=>{outlinePromise=null;throw e;});
            const data=await outlinePromise;dashboardIndex=data.dashboards||{};urbanLinksPromise??=fetch(new URL('urban-map-links.json',section.dataset.adminOutlines),{cache:'no-cache'}).then(r=>r.ok?r.json():null).catch(()=>null);let urbanLinks=null;if(version!==areaVersion||finderPath!=='admin')return;
            const st=officialDirectory.states.find(row=>electoralName(row.name)===electoralName(state.value));
            const districtCode=adminDistrict.value,subdistrictCode=adminSubdistrict.value;
            adminNavigation();
            let subdistricts={features:[]};if(districtCode&&st&&(data.subdistrict_by_district ? data.subdistrict_by_district[`${st.code}:${districtCode}`] : data.subdistrict_files?.[st.code])){const file=data.subdistrict_by_district?.[`${st.code}:${districtCode}`]||data.subdistrict_files[st.code];if(!subdistrictSources.has(file))subdistrictSources.set(file,fetch(new URL(file,section.dataset.adminOutlines)).then(r=>{if(!r.ok)throw new Error('Subdistrict outlines are temporarily unavailable.');return r.json();}).catch(e=>{subdistrictSources.delete(file);throw e;}));subdistricts=await subdistrictSources.get(file);if(version!==areaVersion)return;}
            let features=data.features.filter(f=>!state.value||electoralName(f.properties.state)===electoralName(state.value));
            if(districtCode){
                const children=subdistricts.features.filter(f=>f.properties.state_code===st?.code&&f.properties.district_code===districtCode&&(!subdistrictCode||f.properties.subdistrict_code===subdistrictCode));
                const context=features.filter(f=>f.properties.lgd_districts.some(d=>d.state===st?.code&&d.district===districtCode));features=children.length?(subdistrictCode?children:[...context.map(f=>({...f,properties:{...f.properties,context:true}})),...children]):context;
            }
            adminFeatures=features;scheduleRainfall();
            const collection=overviewCollection();
            if(!map.getSource('admin-boundaries')){
                map.addSource('admin-boundaries',{type:'geojson',data:collection});
                map.addLayer({id:'admin-boundaries',type:'fill',source:'admin-boundaries',paint:{'fill-color':['match',['get','state_code'],'9','#8ec5e8','23','#bba8ec','8','#f4c37b','27','#91d3bd','19','#e6adcb','#97c9df'],'fill-opacity':['case',['boolean',['get','context'],false],0.13,0.5]}});
                map.addLayer({id:'admin-lines',type:'line',source:'admin-boundaries',paint:{'line-color':'#404040','line-opacity':0.65,'line-width':0.6}});
                map.on('click','admin-boundaries',async event=>{
                    if(finderPath!=='admin')return;const settlementLayers=[...loaded].map(id=>`villages-${id}`).filter(id=>map.getLayer(id));if(settlementLayers.length&&map.queryRenderedFeatures(event.point,{layers:settlementLayers}).length)return;const selected=adminFeatures.find(f=>String(f.id)===String(event.features[0].properties.display_id));if(!selected)return;
                    const p=selected.properties;if(p.context){status.textContent='Choose a subdistrict from the filter or click a linked subdistrict area.';return;}
                    if(p.subdistrict_code){if(![...adminSubdistrict.options].some(o=>o.value===p.subdistrict_code))await hierarchy(adminSubdistrict,{district:adminDistrict.value},'Choose a subdistrict');adminSubdistrict.value=p.subdistrict_code;await subdistrictChanged();return;}
                    const option=[...state.options].find(o=>electoralName(o.value)===electoralName(p.state));if(!option)return;
                    state.value=option.value;populateDistricts();await syncHierarchy();
                    if(p.lgd_districts.length===1&&[...adminDistrict.options].some(o=>o.value===p.lgd_districts[0].district)){adminDistrict.value=p.lgd_districts[0].district;await districtChanged();}
                    else{fitFeatures([selected],9);details.replaceChildren(node('h2',p.name),node('p','SOI district outline. Choose a current district in the filter for verified village links.'));showNavigation(p.navigation||[]);status.textContent=`${p.name} source outline \u00b7 choose the current district in the filter.`;}
                });
                const overviewHover=new maplibregl.Popup({closeButton:false,closeOnClick:false,maxWidth:'320px'});
                map.on('mousemove','admin-boundaries',event=>{if(!metric.value||villageMetricCount())return;const feature=adminFeatures.find(f=>String(f.id)===String(event.features[0].properties.display_id));if(!feature)return;const values=datedOverview(overviewValues(feature.properties,overviewData?.groups||{}),feature.properties),value=isSoil(metric.value)?soilValues(soilOverview(feature.properties,soilData))[metric.value]:metric.value==='rainfall'?rainfallClient.value(feature,rainfallYear.value):values?.[metric.value],content=node('div');content.append(node('strong',feature.properties.name),node('p',`${isSoil(metric.value)?metric.selectedOptions[0].textContent:isJjm(metric.value)?metric.selectedOptions[0].textContent:metric.value==='satellite'?'Vegetation change':metric.value==='literacy'?'Literacy (age 7+)':metric.value==='population'?'Population':isJjm(metric.value)?'Tap connection coverage':metric.value==='rainfall'?`Rainfall ${rainfallYear.value}`:'Households'}: ${value==null?'Unavailable':(typeof value==='number'?value.toLocaleString('en-IN'):String(value))+(isSoil(metric.value)||['literacy','water'].includes(metric.value)?'%':metric.value==='rainfall'?' mm':'')}`),node('small',isSoil(metric.value)?`SHC ${soilCycle.value} - ${soilSampleCount(soilOverview(feature.properties,soilData),metric.value)??'Unavailable'} tested samples`:metric.value==='rainfall'?'IMD 0.25 degree grid \u00b7 Area-weighted estimate. Click to explore.':`${(isJjm(metric.value)?values?.water_records:values?.counts?.[metric.value])?.toLocaleString('en-IN')||0} linked village records \u00b7 ${isJjm(metric.value)?'JJM - household-weighted coverage':`Census ${censusYear.value} - towns excluded; current boundaries, coverage varies`}. Click to explore this area.`));if(isNativeCensusField(metric.value)){content.replaceChildren(node('strong',feature.properties.name),node('p',metric.selectedOptions[0].textContent),node('p',nativeError||nativeSummaryText(nativeSummary(feature.properties,nativeData.get(metric.value)),nativeManifest?.fields?.[metric.value]||{})));}overviewHover.setLngLat(event.lngLat).setDOMContent(content).addTo(map);});
                map.on('mouseleave','admin-boundaries',()=>overviewHover.remove());
                map.on('mouseenter','admin-boundaries',()=>{map.getCanvas().style.cursor='pointer';});map.on('mouseleave','admin-boundaries',()=>{map.getCanvas().style.cursor='';});
            }else map.getSource('admin-boundaries').setData(collection);
            map.setLayoutProperty('admin-boundaries','visibility','visible');map.setLayoutProperty('admin-lines','visibility','visible');updateMetrics();
            if(features.length)fitFeatures(features,districtCode?11:state.value?8:5);
            else if(state.value){const layers=catalogue.layers.filter(l=>electoralName(l.state)===electoralName(state.value));if(layers.length)fit(layers.reduce((b,l)=>[Math.min(b[0],l.bbox[0]),Math.min(b[1],l.bbox[1]),Math.max(b[2],l.bbox[2]),Math.max(b[3],l.bbox[3])],[180,90,-180,-90]),7);}
            status.textContent=features.length?(districtCode?'Choose a subdistrict, or click an area.':'Choose a state, or click a district outline.'):'No verified display boundary is available for this selection. Village records remain accessible.';
            if(districtCode&&st){
                urbanLinks=await urbanLinksPromise;if(version!==areaVersion)return;
                if(subdistrictCode&&features.some(f=>f.properties.subdistrict_code))map.setLayoutProperty('admin-boundaries','visibility','none');
                const response=await fetch(`${section.dataset.adminArea}?${new URLSearchParams({state:st.code,district:districtCode,subdistrict:subdistrictCode})}`);if(!response.ok)throw new Error('Village shapes are temporarily unavailable.');const area=await response.json();if(version!==areaVersion)return;
                details.replaceChildren(node('h2',adminDistrict.selectedOptions[0]?.textContent||'District'));
                adminNavigation();
                let shown=0,failed=0;for(const link of settlementDisplayLinks(area.layers,catalogue.layers)){if(version!==areaVersion)return;const existing=catalogue.layers.find(l=>l.file===link.file&&l.sha256===link.sha256);const towns=existing?urbanLinks?.layers?.[existing.id]:null;const allowed=section.dataset.censusVillageCodes?new Set(section.dataset.censusVillageCodes.split(',')):null;const indices=(towns?.sha256===link.sha256?link.indices.filter(index=>!towns.records[String(index)]):link.indices).filter(index=>!allowed||allowed.has(String(link.codes[String(index)])));if(!indices.length)continue;const layer={...(existing||{}),id:`admin-${link.id}`,geometry_url:existing?`${section.dataset.layerBase}/${existing.id}`:`${section.dataset.linkedLayerBase}/${link.id}`,electoralCodes:link.codes,indices,metricId:existing?.id||link.id,nationalLayerId:existing?.id,sha256:link.sha256};try{await ensure(layer);if(version!==areaVersion)return;shown+=indices.length;}catch(error){if(error.name!=='AbortError')failed++;}}
                let urbanShown=0;if(!subdistrictCode&&!section.dataset.censusVillageCodes){for(const entry of urbanLinks?.district_layers?.[`${st.code}:${districtCode}`]||[]){if(version!==areaVersion)return;const layer=byId.get(entry.id);if(!layer||urbanLinks.layers[entry.id]?.sha256!==layer.sha256)continue;try{await ensure({...layer,id:`urban-${entry.id}`,geometry_url:`${section.dataset.layerBase}/${entry.id}`,nationalLayerId:entry.id,metricId:entry.id,electoralCodes:{},indices:entry.indices});urbanShown+=entry.indices.length;}catch(error){if(error.name!=='AbortError')failed++;}}}
                if(!shown&&!urbanShown&&!subdistrictCode&&!section.dataset.censusVillageCodes){for(const id of dashboardIndex[`${st.code}:${districtCode}`]?.source_layers||[]){if(version!==areaVersion)return;const layer=byId.get(id);if(layer){try{await ensure(layer);}catch(error){if(error.name!=='AbortError')failed++;}}}}
                const bounds=new maplibregl.LngLatBounds();for(const entry of metricLayers.values())if(!entry.bounds.isEmpty()){bounds.extend(entry.bounds.getSouthWest());bounds.extend(entry.bounds.getNorthEast());}if(!bounds.isEmpty()&&(!features.length||subdistrictCode))fit([bounds.getWest(),bounds.getSouth(),bounds.getEast(),bounds.getNorth()],12);
                status.textContent=shown||urbanShown?`${shown} linked village shapes \u00b7 ${urbanShown} verified urban shapes \u00b7 click an area to open its details.`:(metricLayers.size?'Existing SOI settlement polygons shown. Verified Census town links open city details; current LGD village polygon links are unavailable.':'SOI district outline shown. Verified settlement polygon links are unavailable for this selection.');
            }
        }catch(error){if(version===areaVersion&&error.name!=='AbortError')mapWarning(error.message);}
    }
    villageFilter.addEventListener('change',()=>{selectedPlaceNavigation=[];adminNavigation();});
    villageFilter.addEventListener('change',async()=>{const code=villageFilter.value,version=++villageVersion;if(!code)return;finderStatus.textContent='Opening village\u2026';try{const response=await fetch(`${section.dataset.villageApi}/${encodeURIComponent(code)}`);if(!response.ok)throw new Error('Village details are temporarily unavailable.');const place=await response.json();if(version!==villageVersion)return;if(place.layer)await select(place,true);else{display(place);prune(new Set());}finderStatus.textContent=place.layer?'':'Village records are available; a verified polygon link is not yet available.';}catch(error){if(version===villageVersion)mapWarning(error.message);}});
    function populateDistricts() {
        district.replaceChildren(node('option', 'Choose a district group'));
        district.firstChild.value = '';
        for (const layer of catalogue.layers.filter(layer => !state.value || layer.state === state.value).sort((a,b) => a.district.localeCompare(b.district))) {
            const option = node('option', `${layer.district} \u00b7 ${layer.state}${layer.kind === 'district' ? ' \u00b7 district outline only' : ''}`);
            option.value = layer.id;
            district.append(option);
        }
    }
    catalogue=await cataloguePromise;catalogue.layers=[...catalogue.layers,...(catalogue.context_layers||[])];for(const layer of catalogue.layers)byId.set(layer.id,layer);
    populateDistricts();
    function fit(box, maxZoom = 10, duration = 500) {
        if (ready) {fitSelection=()=>map.fitBounds([[box[0],box[1]],[box[2],box[3]]], {padding:45,maxZoom,duration});fitSelection();}
    }
    function display(place) {
        const selectedState=officialDirectory.states.find(row=>electoralName(row.name)===electoralName(state.value)),placeParent=place.administration;
        if(placeParent&&selectedState&&(String(placeParent.state)!==String(selectedState.code)||(adminDistrict.value&&String(placeParent.district_code)!==adminDistrict.value)||(adminSubdistrict.value&&String(placeParent.subdistrict_code)!==adminSubdistrict.value)))return;

        selectedCensusPlace=place;
        if(censusYear.value==='2001'){
            const record=(place.census_history||[]).find(row=>Number(row.year)===2001);
            place={...place,census:{...record,...censusValues(record),literacy_rate:censusValues(record).literacy},census_url:record?.url};
        }
        const admin=place.administration;if(admin?.lgd||place.lgd_code){place={...place,url:section.dataset.villageBase+'/'+encodeURIComponent(admin?.lgd||place.lgd_code)};}const linked=admin?dashboardIndex[`${admin.state}:${admin.district_code}`]?.navigation||[]:[];
        selectedPlaceNavigation=[...(place.url?[{label:`Open ${place.name} village dashboard`,url:place.url}]:[]),...(place.constituencies||[]).map(seat=>({url:seat.url,label:`${seat.kind==='ac'?'Assembly (AC)':'Lok Sabha (PC)'}: ${seat.name}${seat.partial?' · partly covered':''}`}))];
        refreshSelectionNavigation(admin);
        if(finderPath==='electoral')showNavigation([...stateNavigation(),...selectedPlaceNavigation]);else adminNavigation();
        details.replaceChildren(node('h2',place.census_name||place.name),node('p',`${place.subdistrict} \u00b7 ${place.district} \u00b7 ${place.state}`));
        const references=node('details');references.className='reference-notes';references.append(node('summary','Official IDs, links & sources'),node('p',`Source code: ${place.code || 'Unavailable'}`));
        if(place.census_name&&place.census_name!==place.name)references.append(node('p',`Original SOI name: ${place.name}`));
        const link = node('a',place.kind === 'district' ? 'Open district dashboard \u2192' : place.kind === 'town' ? 'Open town / city profile \u2192' : 'Open village profile \u2192');
        if (place.url && safeLink(place.url)) { link.href = place.url; details.append(link); }
        if (place.lgd_code) references.append(node('p',`LGD village code: ${place.lgd_code}`));
        if (place.original_lgd_village_code) references.append(node('p',`Existing LGD village link: ${place.original_lgd_village_code} \u00b7 Census town identity shown separately.`));
        if (place.relationship_source && safeLink(place.relationship_source.url)) {
            const source=node('a',`Official LGD source \u00b7 checked ${place.relationship_source.checked_on}`);source.href=place.relationship_source.url;references.append(source);
        }
        if (place.census) {
            details.append(node('h3',`Census ${censusYear.value}`));
            const value = number => number === null || number === undefined ? 'Unavailable' : Number(number).toLocaleString('en-IN');
            details.append(node('p',`Population: ${value(place.census.population)} \u00b7 Households: ${value(place.census.households)} \u00b7 Literate people: ${value(place.census.literate)}`));
            if(place.census.literacy_rate != null)details.append(node('p',`Literacy (age 7+): ${place.census.literacy_rate}%`));
            if (safeLink(place.census_url)) { const census = node('a','Open Census profile and data \u2192'); census.href=place.census_url; details.append(census); }
        }
        for (const record of place.census_history || []) {
            if (!safeLink(record.url)) continue;
            const historical = node('a',`Open Census ${record.year} village record \u2192`);
            historical.href = record.url;
            details.append(node('h3',`Historical Census \u00b7 ${record.year}`), historical,
                node('p','Dated village record linked by official identity. Displayed map boundaries do not establish historical boundaries or growth comparability.'));
        }
        references.append(node('p',place.kind === 'district' ? 'SOI village polygons are not available here. This outline opens the existing district dashboard and its dated records.' : place.url ? 'Connected through the existing verified village mapping. Census figures describe the dated record.' : 'This shape has no verified connection to an existing village profile yet.'));
        if (place.water || place.water_note) {
            details.append(node('h3','Water \u00b7 Jal Jeevan Mission'));
            if (place.water) {
                const water=place.water,value=n=>n==null?'Unavailable':Number(n).toLocaleString('en-IN');
                details.append(node('p',`JJM households: ${value(water.households)} | Household tap connections: ${value(water.tap_connections)} | Tap connection coverage: ${value(water.coverage_pct)}${water.coverage_pct==null?'':'%'}`));
                details.append(node('p',`JJM source retrieved: ${water.retrieved_at || 'Unavailable'}. Independent of the selected Census year.`));
                if(safeLink(water.source_url)){const source=node('a','Official JJM source');source.href=water.source_url;details.append(source);}
            } else details.append(node('p',place.water_note));
        }

        const rainFeature=metricLayers.get(place.layer)?.features?.[Number(place.feature_index)];
        if(rainFeature?.geometry){const panel=node('section');panel.append(node('h3','Rainfall - annual IMD area estimates'));const status=node('p','Loading rainfall...');panel.append(status);details.append(panel);const calculation=typeof rainfallClient!=='undefined'?rainfallClient.values([rainFeature]):import('./rainfall-client.js?v=20261014-1').then(({RainfallClient})=>new RainfallClient().values([rainFeature]));calculation.then(([values])=>{if(!details.contains(panel))return;const table=node('table'),head=node('thead'),tr=node('tr');tr.append(node('th','Year'),node('th','Rainfall (mm)'));head.append(tr);table.append(head);const body=node('tbody');for(const year of Object.keys(values).sort()){const row=node('tr');row.append(node('td',year),node('td',values[year]==null?'Unavailable':Number(values[year]).toLocaleString('en-IN',{maximumFractionDigits:2})));body.append(row);}table.append(body);panel.append(table);status.textContent='Existing IMD 0.25 degree grid area estimates, independent of Census year.';}).catch(()=>status.textContent='Rainfall unavailable for this shape.');}
        details.append(references);
    }
    async function ensure(layer) {
        if (!ready || loaded.has(layer.id)) return;
        if (pending.has(layer.id)) return pending.get(layer.id).promise;
        const controller = new AbortController();
        const promise = (async () => {
            const response = await fetch(layer.geometry_url || `${section.dataset.layerBase}/${layer.id}`, {signal:controller.signal});
            if (!response.ok) throw new Error(response.status === 409 ? 'A source layer changed after the audit; it needs a catalogue refresh.' : 'Village geometry unavailable.');
            const geometry = await response.json();
            if (controller.signal.aborted) return;
            const id = `villages-${layer.id}`;
            map.addSource(id,{type:'geojson',data:geometry,generateId:true});
            map.addLayer({id,type:'fill',source:id,paint:{'fill-color':layer.kind === 'district' ? '#71879d' : '#168b78','fill-opacity':0.22,'fill-outline-color':'rgba(64,64,64,0.65)'}});
            map.addLayer({id:`${id}-selected`,type:'line',source:id,filter:['==',['id'],-1],paint:{'line-color':'#475569','line-width':1.5}});
            map.on('click',id,async event => {
                const version = ++generation;
                const index = event.features[0].id;
                const satelliteEntry=metricLayers.get(layer.id);if(satelliteEntry)satelliteEntry.satelliteSelection=[Number(index)];section.dispatchEvent(new CustomEvent('satellite-place-selection',{detail:{sha:layer.sha256,indices:[Number(index)]}}));
                try {
                    if (layer.electoralCodes && !layer.nationalLayerId) {
                        const properties = event.features[0].properties;
                        const lgd = layer.electoralCodes[String(index)];
                        const response=await fetch(`${section.dataset.villageApi}/${encodeURIComponent(lgd)}`);if(!response.ok)throw new Error('Village details unavailable.');const place=await response.json();if(version!==generation)return;display(place);
                        const link=node('a','Open village records \u2192');link.href=`${section.dataset.villageBase}/${encodeURIComponent(lgd)}`;details.append(link);
                        map.setFilter(`${id}-selected`,['==',['id'],Number(index)]);
                        return;
                    }
                    const response = await fetch(`${section.dataset.layerBase}/${layer.nationalLayerId||layer.id}/features/${index}?sha=${layer.sha256}`);
                    if (!response.ok) throw new Error(response.status === 409 ? 'The map catalogue changed. Refresh the map before selecting this shape.' : 'This shape is not available in the map index.');
                    const place = await response.json();
                    if (generation !== version) return;
                    if(layer.electoralCodes){display(place);map.setFilter(`${id}-selected`,['==',['id'],Number(index)]);}else await select(place);
                } catch (error) { if (generation === version) status.textContent = error.message; }
            });
            const hover=new maplibregl.Popup({closeButton:false,closeOnClick:false,maxWidth:'280px'});
            map.on('mousemove',id,event=>{
                const feature=event.features?.[0];if(!feature)return;
                const properties=feature.properties||{}, content=node('div');
                const name=properties.name||properties.NAME||properties.VILLAGE||properties.VILL_NAME||properties.village_name||properties.village||'Village';
                const row=metricLayers.get(layer.id)?.records.find(record=>record.feature_index===Number(feature.id));
                content.append(node('strong',row?.level==='TOWN'&&row.census_name?row.census_name:String(name)));
                if(isJjm(metric.value)&&!row?.water)content.append(node('p',jjmMissingNote(row)));if(metric.value){const value=censusYear.value==='2001'&&isNativeCensusField(metric.value)?rawCensusValue(row?.history,metric.value):row?.values?.[metric.value];content.append(node('p',`${metric.options[metric.selectedIndex].text}: ${value==null?'Unavailable':(typeof value==='number'?value.toLocaleString('en-IN'):String(value))+(isSoil(metric.value)||['literacy','water'].includes(metric.value)?'%':metric.value==='rainfall'?' mm':'')}`),node('small',isSoil(metric.value)?`SHC ${soilCycle.value} - ${soilSampleCount(row?.soil,metric.value)??'Unavailable'} tested samples`:metric.value==='rainfall'?`IMD ${rainfallYear.value} \u00b7 0.25 degree grid area estimate`:isJjm(metric.value)?jjmNote:row?.level==='TOWN'?`Census ${censusYear.value} | linked town record`:`Census ${censusYear.value} | linked village record`));}
                if(censusYear.value==='2001')renderDirectory(content,row?.history);
                else content.append(node('p','Choose Map data above to show population, literacy or households.'));
                hover.setLngLat(event.lngLat).setDOMContent(content).addTo(map);
            });
            map.on('mouseleave',id,()=>hover.remove());
            map.on('mouseenter',id,()=>{map.getCanvas().style.cursor='pointer';});
            map.on('mouseleave',id,()=>{map.getCanvas().style.cursor='';});
            loaded.add(layer.id);
            const bounds=new maplibregl.LngLatBounds();const visit=c=>{if(typeof c[0]==='number')bounds.extend(c.slice(0,2));else c.forEach(visit);};const indices=layer.indices?new Set(layer.indices):null;geometry.features.forEach((f,i)=>{if(f.geometry&&(!indices||indices.has(i)))visit(f.geometry.coordinates);});
            metricLayers.set(layer.id,{layer,bounds,features:geometry.features,count:geometry.features.length,records:[],controller:new AbortController()});updateMetrics();
        })().finally(()=>pending.delete(layer.id));
        pending.set(layer.id,{promise,controller});
        return promise;
    }
    function prune(wanted) {
        for (const [id, request] of pending) if (!wanted.has(id)) request.controller.abort();
        for (const id of loaded) if (!wanted.has(id)) {
            metricLayers.get(id)?.controller.abort();metricLayers.delete(id);
            map.removeLayer(`villages-${id}-selected`); map.removeLayer(`villages-${id}`); map.removeSource(`villages-${id}`); loaded.delete(id);
        }
        updateMetrics();
    }

    async function startElectoral() {
        const mode=document.getElementById('map-level'),pcSelect=document.getElementById('pc-filter'),acSelect=document.getElementById('ac-filter'),message=document.getElementById('electoral-status');
        const official=JSON.parse(document.getElementById('india-electoral-catalogue').textContent);
        const fetchJson=async url=>{const response=await fetch(url);if(!response.ok)throw new Error('Existing electoral map unavailable.');return response.json();};
        const [boundaryCatalogue,pcs]=await Promise.all([fetchJson(section.dataset.electoralCatalogue),fetchJson(section.dataset.pcSource)]);
        const stateSources=new Map();let currentPC=null,currentAC=null,assemblies=[],revision=0;
        const matchingState=name=>official.states.find(s=>electoralName(s.name)===electoralName(name));
        const sourceState=name=>Object.values(boundaryCatalogue.states).find(s=>electoralName(s.name)===electoralName(name));
        const officialSeat=feature=>{const st=matchingState(feature.properties.state);const found=official.seats.filter(s=>s.state===st?.code&&s.kind===feature.properties.kind&&electoralName(s.name)===electoralName(feature.properties.name));return found.length===1?found[0]:null;};
        const sourceFor=async name=>{const st=sourceState(name);if(!st)return {features:[]};if(!stateSources.has(st.slug))stateSources.set(st.slug,fetchJson(`${section.dataset.electoralBase}/${st.file}`).catch(error=>{stateSources.delete(st.slug);throw error;}));return stateSources.get(st.slug);};
        const fitFeature=feature=>{const bounds=new maplibregl.LngLatBounds();const visit=coords=>{if(typeof coords[0]==='number')bounds.extend(coords.slice(0,2));else coords.forEach(visit);};visit(feature.geometry.coordinates);if(!bounds.isEmpty()){fitSelection=()=>map.fitBounds(bounds,{padding:45,maxZoom:11,duration:400});fitSelection();}};
        const options=(select,features,label)=>{select.replaceChildren(node('option',label));select.firstChild.value='';for(const f of [...features].sort((a,b)=>a.properties.name.localeCompare(b.properties.name))){const option=node('option',f.properties.name);option.value=f.id;select.append(option);}};
        refreshElectoralSummary=()=>{electoralSummaryFeatures=electoralSummaryFeatures.map(feature=>{const currentValues=constituencyValues(feature,official,electoralSummaryData?.groups||{}),values=censusYear.value==='2001'?constituencyValues(feature,official,electoral2001?.groups||{}):currentValues,native=censusYear.value==='2001'?constituencyValues(feature,official,electoral2001Fields.get(metric.value)?.groups||{}):null,soil=constituencyValues(feature,official,soilElectoralData?.groups||{});return {...feature,properties:{...feature.properties,electoral_id:feature.id,...(isNativeCensusField(metric.value)?{['overview_'+metric.value]:nativeOverviewValue(native,electoral2001Fields.get(metric.value)?.kind),overview_native_note:nativeSummaryText(native,electoral2001Fields.get(metric.value)||{})}:{}),...Object.fromEntries(Object.entries(soilValues(soil)).map(([key,value])=>['overview_'+key,value])),...Object.fromEntries(Object.entries(soilMetrics).flatMap(([key,[,prefix]])=>[['overview_'+key+'_samples',soilSampleCount(soil,key)],['overview_'+key+'_villages',soil?.indicator_records?.[prefix]??0]])),overview_population:values?.population??null,overview_households:values?.households??null,overview_literacy:values?.literacy??null,overview_rainfall:rainfallClient.value(feature,rainfallYear.value),overview_water_households:currentValues?.water_households??null,overview_water_taps:currentValues?.water_taps??null,overview_water:currentValues?.water??null,overview_water_records:currentValues?.water_records??0,overview_records:native?.[0]??values?.counts?.records??0,overview_fully_covered:values?.fully_covered_villages??0,overview_withheld:values?.withheld_villages??0}};});map.getSource('electoral-boundaries').setData({type:'FeatureCollection',features:electoralSummaryFeatures});paintElectoralOverview();metricCoverage();};
        const mapFeatures=(features,kind)=>{electoralSummaryFeatures=features;refreshElectoralSummary();scheduleRainfall();scheduleSoilElectoral();loadElectoralOverview();map.setLayoutProperty('electoral-boundaries','visibility','visible');map.setLayoutProperty('electoral-boundaries-line','visibility','visible');map.setLayoutProperty('coverage','visibility','none');};
        const outline=feature=>map.getSource('electoral-selection').setData({type:'FeatureCollection',features:feature?[feature]:[]});
        const fillPCs=()=>{options(pcSelect,state.value?pcs.features.filter(f=>electoralName(f.properties.state)===electoralName(state.value)):[],state.value?'Choose a PC':'Choose a state first');pcSelect.disabled=!state.value;};
        const constituencySlug=value=>String(value).normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
        const seatNavigation=feature=>feature?[{label:`${feature.properties.kind==='pc'?'Lok Sabha (PC)':'Assembly (AC)'}: ${feature.properties.name}`,url:`/india/constituencies/${feature.properties.kind}/${constituencySlug(feature.properties.state)}/${constituencySlug(feature.properties.name)}?source_name=${encodeURIComponent(feature.properties.name)}`}]:[];
        const electoralNavigation=()=>showNavigation([...stateNavigation(),...seatNavigation(currentPC),...seatNavigation(currentAC)]);
        const reset=()=>{showNavigation(stateNavigation());++revision;++villageVersion;choices(villageFilter,[],'Choose an AC first');acSelect.disabled=true;currentPC=null;currentAC=null;assemblies=[];district.value='';pcSelect.value='';options(acSelect,[],'Choose an assembly constituency');mode.value='pc';prune(new Set());outline(null);fillPCs();mapFeatures(pcs.features.filter(f=>!state.value||electoralName(f.properties.state)===electoralName(state.value)),'pc');details.replaceChildren(node('h2','Find your place'),node('p','Choose a state above, or click a constituency on the map.'));message.textContent=`${pcSelect.options.length-1} PC boundaries \u00b7 click the map or use filters.`;status.textContent='PC \u2192 AC \u2192 village';};
        const explore=()=>{++revision;currentAC=null;currentPC=null;mode.value='villages';pcSelect.value='';acSelect.value='';outline(null);map.setLayoutProperty('electoral-boundaries','visibility','none');map.setLayoutProperty('electoral-boundaries-line','visibility','none');map.setLayoutProperty('coverage','visibility','none');message.textContent='Explore existing source village layers.';};
        const wardList=data=>{const wards=data.entities.filter(e=>e.entity_type==='Ward');details.append(node('h3',`${wards.length} official ward records`));const list=node('ul');for(const ward of wards)list.append(node('li',`${ward.entity_name} \u00b7 ward code ${ward.entity_code} \u00b7 ${Number(ward.partial)?'partly covered':'fully covered'}`));details.append(list);return wards.length;};
        async function selectPC(feature) {
            pathUI('electoral');++villageVersion;choices(villageFilter,[],'Choose an AC first');const version=++revision;currentPC=feature;currentAC=null;prune(new Set());mode.value='ac';pcSelect.value=feature.id;
            const stateOption=[...state.options].find(o=>electoralName(o.value)===electoralName(feature.properties.state));if(stateOption){state.value=stateOption.value;populateDistricts();fillPCs();pcSelect.value=feature.id;}
            electoralNavigation();message.textContent=`Loading ${feature.properties.name} assembly constituencies\u2026`;
            try{const source=await sourceFor(feature.properties.state);if(version!==revision)return;assemblies=electoralChildren(source.features,feature);options(acSelect,assemblies,'Choose an assembly constituency');acSelect.disabled=assemblies.length===0;mapFeatures(assemblies,'ac');outline(feature);fitFeature(feature);details.replaceChildren(node('h2',feature.properties.name),node('p',`${assemblies.length} AC boundaries \u00b7 click an AC to display villages.`),node('p','PC/AC relationships use the existing boundary source and its edition.'));message.textContent=assemblies.length?`${feature.properties.name} \u2192 ${assemblies.length} AC boundaries`:'No AC boundaries are present in this source for the selected PC.';status.textContent='Click an assembly constituency.';}catch(error){if(version===revision)message.textContent=error.message;}
        }
        async function selectAC(feature) {
            ++villageVersion;choices(villageFilter,[],'Loading villages\u2026');const version=++revision;currentAC=feature;electoralNavigation();mode.value='villages';acSelect.value=feature.id;prune(new Set());outline(feature);mapFeatures([feature],'ac');fitFeature(feature);
            details.replaceChildren(node('h2',feature.properties.name),node('p','Loading available village and ward records\u2026'));message.textContent=`${currentPC?.properties.name||feature.properties.state} \u2192 ${feature.properties.name} \u2192 villages`;
            const seat=officialSeat(feature);
            if(!seat){choices(villageFilter,[],'Verified village list unavailable');details.replaceChildren(node('h2',feature.properties.name),node('p','A unique official LGD membership match is unavailable. Nearby source villages are shown as geographic context; their electoral membership is unverified.'));await nearby(feature,version);return;}
            try{
                const data=await fetchJson(`${section.dataset.membership}?${new URLSearchParams({state:seat.state,kind:'ac',code:seat.code})}`);if(version!==revision)return;
                choices(villageFilter,data.villages||[],'Choose a village');
                details.replaceChildren(node('h2',feature.properties.name),node('p',`${data.counts.villages} villages in official LGD membership \u00b7 ${data.counts.linked_villages} with verified shape links \u00b7 ${data.counts.villages_without_displayed_shape} without a displayed shape.`));const reference=node('details');reference.className='reference-notes';reference.append(node('summary','Membership & ward reference'));const startCount=details.childNodes.length;wardList(data);for(const child of [...details.childNodes].slice(startCount))reference.append(child);details.append(reference);
                reference.append(node('p','Village identities use the existing LGD crosswalk. Boundary editions remain unverified; partly covered villages are withheld.'));
                let shown=0,failed=0;
                for(const link of settlementDisplayLinks(data.layers,catalogue.layers)){if(version!==revision)return;const existing=catalogue.layers.find(l=>l.file===link.file&&l.sha256===link.sha256);const layer={...(existing||{}),id:`electoral-${link.id}`,geometry_url:existing?`${section.dataset.layerBase}/${existing.id}`:`${section.dataset.linkedLayerBase}/${link.id}`,electoralCodes:link.codes,indices:link.indices,metricId:existing?.id||link.id,nationalLayerId:existing?.id,sha256:link.sha256};try{await ensure(layer);if(version!==revision)return;paintMetrics(layer);shown+=link.indices.length;}catch(error){if(error.name!=='AbortError')failed++;}}
                if(version!==revision)return;status.textContent=`${shown} village shapes displayed${failed?` \u00b7 ${failed} source layers unavailable`:''}. Click a village to open its records.`;
                if(!shown)await nearby(feature,version);
            }catch(error){if(version===revision)mapWarning(error.message);}
        }
        async function nearby(feature,version){const bounds=new maplibregl.LngLatBounds();const visit=c=>{if(typeof c[0]==='number')bounds.extend(c.slice(0,2));else c.forEach(visit);};visit(feature.geometry.coordinates);const layers=visibleLayers(catalogue.layers,[bounds.getWest(),bounds.getSouth(),bounds.getEast(),bounds.getNorth()],'');const wanted=new Set(layers.map(l=>l.id));prune(wanted);await Promise.allSettled(layers.map(ensure));if(version===revision)status.textContent=`${layers.length} nearby SOI source layers \u00b7 geographic context, electoral membership unverified.`;}
        const setLevel=async()=>{++revision;prune(new Set());if(mode.value==='pc'){reset();return;}if(mode.value==='ac'){if(currentPC){await selectPC(currentPC);return;}const source=state.value?await sourceFor(state.value):await fetchJson(`${section.dataset.electoralBase}/india-ac.json`);assemblies=source.features.filter(f=>f.properties.kind==='ac');options(acSelect,assemblies,'Choose an assembly constituency');acSelect.disabled=assemblies.length===0;mapFeatures(assemblies,'ac');outline(null);message.textContent=`${assemblies.length} source AC boundaries \u00b7 click an AC.`;return;}if(mode.value==='wards'){if(!currentAC){message.textContent='Choose a PC and AC first to see ward records.';return;}const seat=officialSeat(currentAC);if(!seat){message.textContent='Official ward membership unavailable for this AC.';return;}const data=await fetchJson(`${section.dataset.membership}?${new URLSearchParams({state:seat.state,kind:'ac',code:seat.code})}`);details.replaceChildren(node('h2',`${currentAC.properties.name} \u00b7 wards`));const reference=node('details');reference.className='reference-notes';reference.append(node('summary','Membership & ward reference'));const startCount=details.childNodes.length;wardList(data);for(const child of [...details.childNodes].slice(startCount))reference.append(child);details.append(reference);mapFeatures([currentAC],'ac');outline(currentAC);status.textContent='Ward records listed. Separate ward polygons are unavailable in the existing source maps.';return;}if(currentAC){await selectAC(currentAC);return;}explore();};
        map.addSource('electoral-boundaries',{type:'geojson',data:{type:'FeatureCollection',features:[]}});map.addLayer({id:'electoral-boundaries',type:'fill',source:'electoral-boundaries',paint:{'fill-color':'#e0b867','fill-opacity':0.35}});map.addLayer({id:'electoral-boundaries-line',type:'line',source:'electoral-boundaries',paint:{'line-color':'#404040','line-opacity':0.65,'line-width':0.6}});
        map.addSource('electoral-selection',{type:'geojson',data:{type:'FeatureCollection',features:[]}});map.addLayer({id:'electoral-selection',type:'line',source:'electoral-selection',paint:{'line-color':'#475569','line-width':1.5}});
        map.on('click','electoral-boundaries',event=>{if(['villages','wards'].includes(mode.value))return;const feature=event.features[0];const pool=mode.value==='pc'?pcs.features:assemblies;const selected=pool.find(f=>f.id===feature.properties.electoral_id);if(selected)(selected.properties.kind==='pc'?selectPC(selected):selectAC(selected));});
        map.on('mouseenter','electoral-boundaries',()=>{map.getCanvas().style.cursor='pointer';});map.on('mouseleave','electoral-boundaries',()=>{map.getCanvas().style.cursor='';});
        const electoralHover=new maplibregl.Popup({closeButton:false,closeOnClick:false});metric.addEventListener('change',()=>electoralHover.remove());
        map.on('mousemove','electoral-boundaries',event=>{if(!metric.value||villageMetricCount())return;const p=event.features[0].properties,value=p[`overview_${metric.value}`],content=node('div');content.append(node('strong',`${p.kind.toUpperCase()}: ${p.name}`));content.append(node('p',`${metric.selectedOptions[0].textContent}: ${value==null?'Unavailable':(typeof value==='number'?value.toLocaleString('en-IN'):String(value))+(isSoil(metric.value)||['literacy','water'].includes(metric.value)?'%':metric.value==='rainfall'?' mm':'')}`));content.append(node('p',isSoil(metric.value)?`SHC ${soilCycle.value} \u00b7 ${p['overview_'+metric.value+'_samples']??'Unavailable'} tested samples from ${p['overview_'+metric.value+'_villages']??0} linked villages. Official LGD membership; linked-sample summary.`:metric.value==='rainfall'?`IMD ${rainfallYear.value} \u00b7 Area-weighted rainfall over this boundary. Missing grid coverage remains unavailable.`:`${isJjm(metric.value)?p.overview_water_records:p.overview_records} linked village records - ${isJjm(metric.value)?'JJM - household-weighted coverage':`Census ${censusYear.value}`} \u00b7 ${p.overview_withheld} partial/conflicting village memberships withheld. Towns and wards excluded.`));if(isNativeCensusField(metric.value))content.append(node('p',p.overview_native_note||'No linked values'));electoralHover.setLngLat(event.lngLat).setDOMContent(content).addTo(map);});
        map.on('mouseleave','electoral-boundaries',()=>electoralHover.remove());
        pcSelect.addEventListener('change',()=>{const f=pcs.features.find(f=>f.id===pcSelect.value);if(f)selectPC(f);else reset();});acSelect.addEventListener('change',()=>{const f=assemblies.find(f=>f.id===acSelect.value);if(f)selectAC(f);});mode.addEventListener('change',()=>setLevel().catch(error=>{mapWarning(error.message);}));document.getElementById('electoral-back').addEventListener('click',()=>{if(currentAC&&currentPC)selectPC(currentPC);else {state.value='';populateDistricts();reset();map.fitBounds([[67,6],[98,37]],{padding:30});}});
        state.addEventListener('change',()=>{reset();syncHierarchy();});
        reset();fitSelection=()=>map.fitBounds([[67,6],[98,37]],{padding:30});fitSelection();
        const params=new URL(location.href).searchParams,st=official.states.find(s=>s.code===params.get('state')||electoralName(s.name)===electoralName(params.get('state')));const named=official.seats.find(s=>s.state===st?.code&&s.kind===(params.get('kind')||'pc')&&s.code===params.get('code'));
        const wantedName=named?.name||params.get('name');if(wantedName&&st){const source=await sourceFor(st.name);const kind=params.get('kind')||'pc';const matches=source.features.filter(f=>f.properties.kind===kind&&electoralName(f.properties.name)===electoralName(wantedName));if(matches.length===1){if(kind==='pc')await selectPC(matches[0]);else{const parent=pcs.features.find(f=>electoralName(f.properties.state)===electoralName(matches[0].properties.state)&&String(f.properties.code)===String(matches[0].properties.parent_pc_code));if(parent)await selectPC(parent);await selectAC(matches[0]);}}}
        return {explore,reset,stopLoading(){++revision;},get active(){return mode.value!=='villages'||Boolean(currentAC);},get mode(){return mode.value;}};
    }
    function highlight(place) {
        for (const id of loaded) map.setFilter(`villages-${id}-selected`,['==',['id'],id === place.layer ? place.feature_index : -1]);
    }
    function saveSelection(place) {
        const url = new URL(location.href); url.searchParams.set('place',place.id); history.replaceState(null,'',url);
    }
    async function select(place, preservePath=false) {
        ++areaVersion;if(ready&&map.getLayer('admin-boundaries')){map.setLayoutProperty('admin-boundaries','visibility','none');map.setLayoutProperty('admin-lines','visibility','none');}
        if(!preservePath)electoral?.explore();else electoral?.stopLoading();
        const version = ++generation;
        const layer = byId.get(place.layer);
        display(place); saveSelection(place);
        if (place.kind !== 'district' && !place.properties) {
            try {
                const response = await fetch(`${section.dataset.layerBase.replace('/layers','/places')}/${place.id}`);
                if (!response.ok) throw new Error('Village details could not be loaded. Please select the village again.');
                place = await response.json();
                if (generation !== version) return;
                display(place);
            } catch (error) { if (generation === version) mapWarning(error.message); return; }
        }
        if (!layer || !ready) return;
        state.value = layer.state; populateDistricts(); district.value = layer.id;
        prune(new Set([layer.id]));
        status.textContent = `Loading ${layer.district} ${layer.kind === 'district' ? 'district outline' : 'village shapes'}\u2026`;
        try {
            await ensure(section.dataset.initialVillage?{...layer,indices:[place.feature_index]}:layer);
            if (generation !== version) return;
            const waterRow=metricLayers.get(layer.id)?.records?.find(row=>Number(row.feature_index)===Number(place.feature_index));
            display({...place,water:waterRow?.water||null,water_note:waterRow?.water?null:jjmMissingNote(waterRow||{lgd_code:place.lgd_code})});
            window.dispatchEvent(new Event('census-comparison-refresh'));
            map.once('moveend',()=>window.dispatchEvent(new Event('census-comparison-refresh')));
            highlight(place); fit(place.bbox,layer.kind === 'district' ? 9 : 13,0); status.textContent = `${place.name} selected \u00b7 ${layer.kind === 'district' ? 'district outline only; village polygons unavailable' : layer.district + ' source group'}`;
        } catch (error) { if (error.name !== 'AbortError') mapWarning(error.message); }
    }
    async function runSearch() {
        const version = ++searchGeneration;
        searchAbort?.abort(); searchAbort = new AbortController(); results.replaceChildren();
        if (query.value.trim().length < 2) {searchStatus.textContent='Type at least two characters to search.'; return;}
        const params = new URLSearchParams({q:query.value.trim(),state:state.value});
        searchStatus.textContent='Searching village source records\u2026';
        try {
            const response = await fetch(`${section.dataset.search}?${params}`,{signal:searchAbort.signal});
            if (!response.ok) throw new Error('Search unavailable. Try again shortly.');
            const data = await response.json();
            if (version !== searchGeneration) return;
            searchStatus.textContent=data.results.length ? `${data.results.length} results${data.results.length === 25 ? ' \u00b7 refine the name or district for more' : ''}` : 'No matching indexed shapes. Check another district or the coverage gaps below.';
            for (const place of data.results) {
                const li=node('li'),button=node('button',place.name); button.type='button';
                button.append(node('small',`${place.code} \u00b7 ${place.district} \u00b7 ${place.state}`));
                button.addEventListener('click',()=>select(place));li.append(button);
                if (safeLink(place.url)) { const profile=node('a','Village profile \u2192'); profile.href=place.url;li.append(profile); }
                results.append(li);
            }
        } catch (error) {if (error.name !== 'AbortError' && version === searchGeneration) mapWarning(error.message);}
    }
    document.getElementById('back-to-india').addEventListener('click',()=>{if(stateOnly){adminDistrict.value='';adminSubdistrict.value='';syncHierarchy();return;}if(section.dataset.dashboardUrl){location.assign('/india/map');return;}++generation;++villageVersion;++searchGeneration;searchAbort?.abort();query.value='';results.replaceChildren();pathUI('admin');state.value='';district.value='';metric.value='literacy';minimum.value='';maximum.value='';missing.checked=true;updateMetrics();electoral?.reset();populateDistricts();prune(new Set());syncHierarchy();const url=new URL(location.href);for(const key of ['place','village','state','kind','code','name'])url.searchParams.delete(key);history.replaceState(null,'',url);fit([67,6,98,37],5);});
    query.addEventListener('input',()=>{clearTimeout(timer); ++searchGeneration; searchAbort?.abort(); timer=setTimeout(runSearch,250);});
    state.addEventListener('change',()=>{++generation;populateDistricts();runSearch();if(!electoral)syncHierarchy(); if (ready) {prune(new Set()); const layers=catalogue.layers.filter(layer=>!state.value||layer.state===state.value);if(layers.length)fit(layers.reduce((b,l)=>[Math.min(b[0],l.bbox[0]),Math.min(b[1],l.bbox[1]),Math.max(b[2],l.bbox[2]),Math.max(b[3],l.bbox[3])],[180,90,-180,-90]),7);}});
    district.addEventListener('change',async()=>{electoral?.explore();++generation;const layer=byId.get(district.value);if(layer?.kind==='district'){results.replaceChildren();searchStatus.textContent='Village polygons unavailable for this source district.';await select(layer.context_place);return;}runSearch();if(layer&&ready){prune(new Set([layer.id]));fit(layer.bbox);try{await ensure(layer);status.textContent=`${layer.shapes.toLocaleString('en-IN')} indexed shapes \u00b7 ${layer.district} \u00b7 click a village`;}catch(error){if(error.name!=='AbortError')mapWarning(error.message);}}});
    try {
        if (!globalThis.maplibregl) throw new Error('Map graphics unavailable. Village search still works.');
        map = new maplibregl.Map({container:'india-map',center:[80,23],zoom:3.5,style:(stateOnly||section.dataset.basemap==='none')?{version:8,sources:{},layers:[{id:'background',type:'background',paint:{'background-color':'#f6f8f7'}}]}:{version:8,sources:{streets:{type:'raster',tiles:['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],tileSize:256,attribution:'\u00a9 OpenStreetMap contributors'}},layers:[{id:'background',type:'background',paint:{'background-color':'#f6f8f7'}},{id:'streets',type:'raster',source:'streets',paint:{'raster-opacity':1,'raster-saturation':0}}]}});
        map.addControl(new maplibregl.NavigationControl(),'top-right');
        map.addControl({onAdd(){const group=node('div'),button=node('button','Fit');group.className='maplibregl-ctrl maplibregl-ctrl-group map-fit';button.type='button';button.title='Fit selected area';button.setAttribute('aria-label','Fit selected area');button.addEventListener('click',()=>fitSelection());group.append(button);this.container=group;return group;},onRemove(){this.container.remove();}},'top-right');
        let initialContextReady=!section.dataset.initialState;
        window.addEventListener('census-map-capture',async event=>{const deadline=Date.now()+15000;while((!ready||!initialContextReady||map.isMoving()||!adminFeatures.length)&&Date.now()<deadline)await new Promise(resolve=>setTimeout(resolve,150));await Promise.allSettled([overviewPromise,overview2001Promise,...nativePending.values()]);while(map.getSource('admin-boundaries')&&!map.isSourceLoaded('admin-boundaries')&&Date.now()<deadline)await new Promise(resolve=>setTimeout(resolve,100));if(!map.getCanvas()){event.detail.resolve(null);return;}map.once('render',()=>{try{const canvas=document.createElement('canvas'),source=map.getCanvas();canvas.width=source.width;canvas.height=source.height;canvas.getContext('2d').drawImage(source,0,0);const lo=map.project([68,22]).x,hi=map.project([97.5,22]).x,w=map.getContainer().clientWidth;canvas.dataset.comparisonMin=String(Math.max(2,(Math.min(lo,hi)-20)/w*100));canvas.dataset.comparisonMax=String(Math.min(98,(Math.max(lo,hi)+20)/w*100));if(state.value){canvas.dataset.comparisonMin='3';canvas.dataset.comparisonMax='97';}event.detail.resolve(canvas);}catch{event.detail.resolve(null);}});map.triggerRepaint();});
        const positionHomeDivider=()=>{
            if(!document.body.classList.contains('home-dashboard'))return;
            const width=map.getContainer().clientWidth;if(!width)return;
            window.dispatchEvent(new CustomEvent('census-map-delhi-position',{detail:{percent:map.project([77.209,28.614]).x/width*100}}));
        };
        map.on('moveend',positionHomeDivider);map.on('resize',positionHomeDivider);map.on('load',positionHomeDivider);
        map.on('load',async()=>{
            ready=true;
            map.addSource('coverage',{type:'geojson',data:{type:'FeatureCollection',features:catalogue.layers.map(layer=>({type:'Feature',geometry:{type:'Point',coordinates:[(layer.bbox[0]+layer.bbox[2])/2,(layer.bbox[1]+layer.bbox[3])/2]},properties:{layer:layer.id,state:layer.state,kind:layer.kind||'settlement'}}))}});
            map.addLayer({id:'coverage',type:'circle',source:'coverage',maxzoom:8,layout:{visibility:'none'},paint:{'circle-color':['match',['get','kind'],'district','#71879d','#087d6d'],'circle-radius':5,'circle-stroke-width':1,'circle-stroke-color':'#ffffff'}});
            map.on('click','coverage',event=>{const layer=byId.get(event.features[0].properties.layer);state.value=layer.state;populateDistricts();district.value=layer.id;district.dispatchEvent(new Event('change'));});
            map.on('moveend',async()=>{
                if (finderPath==='admin'||electoral?.active) return;
                if (district.value) return;
                if(map.getZoom()<8){prune(new Set());status.textContent='Click a coverage marker or choose a district group. Zoom in for village shapes.';return;}
                const bounds=map.getBounds(), bbox=[bounds.getWest(),bounds.getSouth(),bounds.getEast(),bounds.getNorth()];
                const layers=visibleLayers(catalogue.layers,bbox,state.value);
                prune(new Set(layers.map(layer=>layer.id)));
                const outcome=await Promise.allSettled(layers.map(ensure));
                const failure=outcome.find(result=>result.status==='rejected'&&result.reason.name!=='AbortError');
                status.textContent=failure ? failure.reason.message : `${layers.length} nearby source layers loaded. Choose a district group to see a layer outside this view or download limit.`;
            });
            if(section.dataset.electoralCatalogue){try{if(new URL(location.href).searchParams.has('kind'))await enableElectoral();if(finderPath==='admin'&&!stateOnly)await syncHierarchy();if(finderPath==='electoral'&&electoral.mode==='pc')status.textContent='Choose a state, or click a parliamentary constituency.';}catch(error){mapWarning(error.message);}}
            else status.textContent='Click a coverage marker or choose a district group. Zoom in for village shapes.';
            if(section.dataset.initialState){const selectedState=officialDirectory.states.find(row=>String(row.code).replace(/^0+/, '')===String(section.dataset.initialState).replace(/^0+/, ''));if(selectedState){state.value=selectedState.name;populateDistricts();if(stateOnly){syncHierarchy();}else await syncHierarchy();if(section.dataset.initialDistrict){adminDistrict.value=section.dataset.initialDistrict;await districtChanged();if(section.dataset.initialSubdistrict){adminSubdistrict.value=section.dataset.initialSubdistrict;await subdistrictChanged();}}}}
            else if(section.dataset.dashboardUrl){
                await drawAdmin();
                const target=new URL(section.dataset.dashboardUrl).pathname;
                const matches=Object.entries(dashboardIndex).filter(([,row])=>row.navigation.some(link=>new URL(link.url,location.origin).pathname===target));
                if(matches.length===1){
                    const [stateCode,districtCode]=matches[0][0].split(':');
                    const selectedState=officialDirectory.states.find(row=>row.code===stateCode);
                    if(selectedState){state.value=selectedState.name;populateDistricts();await syncHierarchy();adminDistrict.value=districtCode;await districtChanged();}
                }else status.textContent='Choose the current district in the location filters. This dashboard uses a separately dated district identity.';
            }
            if(section.dataset.initialState&&!section.dataset.initialDistrict)await drawAdmin();
            initialContextReady=true;
            const villageCode=section.dataset.initialVillage||new URL(location.href).searchParams.get('village');
            if(villageCode&&/^[0-9]{1,8}$/.test(villageCode)){
                try {
                    const response=await fetch(`${section.dataset.villageApi}/${villageCode}`);
                    if(!response.ok)throw new Error('The linked village is temporarily unavailable.');
                    const place=await response.json();
                    if(place.id&&place.layer)await select(place);
                    else {display(place);status.textContent='Village record selected. A verified map shape is unavailable; district context remains visible.';}
                }catch(error){mapWarning(error.message);}
                return;
            }
            const id=new URL(location.href).searchParams.get('place');
            if(id&&/^[a-f0-9]{32}$/.test(id)){const response=await fetch(`${section.dataset.layerBase.replace('/layers','/places')}/${id}`);if(response.ok)await select(await response.json());}
        });
    } catch (error) {mapWarning(error.message);}
    if (query.value.trim().length >= 2) await runSearch();
}

if(typeof document!=='undefined') {
    const section=document.querySelector('[data-catalogue]');
    if(section)start(section).catch(error=>{mapWarning(error.message);});
}
