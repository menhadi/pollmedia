export const satelliteAssetUrl=path=>'/satellite-content?path='+encodeURIComponent(path.replace(/^\//,''));
let registryPromise;
const comparisons=new Map();
export async function satelliteRegistry(){
    registryPromise??=fetch('/maps/satellite-registry.json',{cache:'no-cache'}).then(async response=>{if(!response.ok)throw Error('Satellite coverage register unavailable');return response.json();}).catch(error=>{registryPromise=null;throw error;});
    return registryPromise;
}
export function vegetationMeaning(change){
    if(!Number.isFinite(change))return 'No reliable comparison is available for this area.';
    if(change===0)return 'The vegetation index was unchanged at the reported precision.';
    return change>0?'Vegetation looked greener on the later date.':'Vegetation looked less green on the later date.';
}
export function mergeSatellite(records,data,sha){
    if(data.geometry_sha256!==sha)return records;
    const existing=new Map(records.map(row=>[row.feature_index,row]));
    for(const row of data.villages){const old=existing.get(row.feature_index)||{feature_index:row.feature_index,values:{}};existing.set(row.feature_index,{...old,satellite:row,satellite_dates:data.dates,values:{...old.values,satellite:row.ndvi_change}});}
    return [...existing.values()];
}
export async function satelliteForLayer(layer){
    const registry=await satelliteRegistry();
    const entry=registry.entries.find(row=>row.geometry_sha256===layer.sha256&&(row.layer_id===(layer.nationalLayerId||layer.metricId||layer.id)||row.file===layer.file));
    if(!entry)return null;
    if(!comparisons.has(entry.base))comparisons.set(entry.base,fetch(satelliteAssetUrl(entry.base+'comparison.json')).then(async response=>{if(!response.ok)throw Error('Satellite observations unavailable');const data=await response.json();if(data.geometry_sha256!==entry.geometry_sha256)throw Error('Satellite boundary edition changed');return data;}).catch(error=>{comparisons.delete(entry.base);throw error;}));
    return {entry,data:await comparisons.get(entry.base)};
}
export function satelliteDisclaimer(){
    if(document.getElementById('satellite-map-disclaimer'))return;
    const details=document.createElement('details');details.id='satellite-map-disclaimer';
    const summary=document.createElement('summary');summary.textContent='Satellite comparison: sources, dates and limitations';details.append(summary);
    const p=document.createElement('p');p.textContent='Contains modified Copernicus Sentinel-2 data, obtained through Earth Search. Colours show the change in NDVI between matched seasonal observations, using the same clear pixels in both years. They are not annual averages or proof of permanent change. Crop stage, harvest, moisture and haze can affect differences. No soil fertility, yield, water quality or cause is inferred. Grey means no verified comparison; it does not mean no vegetation. Original IDs and polygons remain intact. Exact acquisition dates and source records are provided with each image comparison.';details.append(p);
    document.querySelector('main').append(details);
}
