import {isJjm,jjmValues,jjmRecords,addJjmMeasures,jjmNote,jjmMissingNote} from './jjm-map-values-20261016-v2.js';
const root=document.querySelector('[data-polygon-map]');
const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
async function start(){
 const {nativeBreaks}=await import('./census-native-overview.js?v=20261015-all-1');
 const {soilMetrics,isSoil,loadSoil,soilValues,soilDisclaimer,soilCycleControl,soilCode,soilSampleCount}=await import('./soil-map.js?v=20261014-national-1');
 const directory=JSON.parse(document.getElementById('polygon-district-directory').textContent),current=JSON.parse(document.getElementById('polygon-current-district').textContent),stateSelect=document.getElementById('polygon-state'),districtSelect=document.getElementById('polygon-district');
 const states=[...new Map(directory.map(row=>[row.state,row.state_name])).entries()].sort((a,b)=>a[1].localeCompare(b[1]));for(const [code,name]of states){const o=el('option',name);o.value=code;stateSelect.append(o);}stateSelect.value=current?.state||'';
 function districtOptions(){districtSelect.replaceChildren(el('option','Choose a district'));districtSelect.firstChild.value='';for(const row of directory.filter(r=>r.state===stateSelect.value).sort((a,b)=>a.name.localeCompare(b.name))){const o=el('option',row.name);o.value=row.path;districtSelect.append(o);}districtSelect.value=current?.path||'';}
 stateSelect.addEventListener('change',()=>{const row=directory.find(row=>row.state===stateSelect.value);if(!row)return;const slug=row.state_name.toLowerCase().replace(/&/g,'and').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');location.assign('/india/census/explore?state='+encodeURIComponent(slug));});districtSelect.addEventListener('change',()=>{if(districtSelect.value){const target=new URL(districtSelect.value,location.origin);const year=new URL(location.href).searchParams.get('censusYear');if(year)target.searchParams.set('censusYear',year);target.hash='map';location.assign(target.href);}});districtOptions();
 const r=await fetch(root.dataset.polygonMap);if(!r.ok)throw Error('District polygons unavailable');const data=await r.json();
 const {yearControl,loadHistory,censusValues,districtFeatures,renderDirectory,rawCensusValue}=await import('./census-map-year-20261017-zoom-v24.js');
 const features=districtFeatures(data);
 if(data.rows){data.rows.filter(r=>r.geometry).forEach((r,i)=>{if(r.directory_links?.length===1&&r.kind!=='town')features[i].lgd_code=r.directory_links[0].code;});}
 const svg=document.getElementById('district-polygons'),sub=document.getElementById('polygon-subdistrict'),village=document.getElementById('polygon-village'),search=document.getElementById('polygon-search'),metric=document.getElementById('polygon-metric'),min=document.getElementById('polygon-min'),max=document.getElementById('polygon-max'),missing=document.getElementById('polygon-missing'),tip=document.getElementById('polygon-tooltip'),legend=document.getElementById('polygon-legend');
 const soilCycle=soilCycleControl(metric,()=>{root.dispatchEvent(new Event('soil-cycle-change'));});
 addJjmMeasures(metric);
 let censusReady=false,censusVersion=0;
 const censusYear=yearControl(metric,()=>{if(censusReady)updateCensusYear();});
 async function updateCensusYear(){
  svg.dataset.comparisonReady='false';const version=++censusVersion,year=censusYear.value;
  for(const feature of features){feature.baseline2011??={...feature.values};feature.values={...feature.values,...Object.fromEntries(Object.keys(feature.values||{}).filter(k=>/^(vd_|pca_|c11_)/.test(k)).map(k=>[k,null])),...(year==='2011'?feature.baseline2011:{...Object.fromEntries(Object.keys(feature.values||{}).filter(k=>k.startsWith('vd_')).map(k=>[k,null])),...censusValues(null)})};}
  render();
  if(selectedIndices.size===1)recordPanel.replaceChildren(el('h2',features[[...selectedIndices][0]].properties.name||'Village records'),el('p',`Loading Census ${year} values...`));
  if(year==='2001'){
   document.getElementById('polygon-status').textContent='Loading verified Census 2001 village values...';
   try{const history=await loadHistory(features.map(f=>f.lgd_code));if(version!==censusVersion)return;
    for(const f of features){f.history=history[String(f.lgd_code||'')];f.values={...f.values,...censusValues(f.history)};}
   }catch(error){if(version===censusVersion)document.getElementById('polygon-status').textContent=error.message;return;}
  }
  if(version!==censusVersion)return;render();svg.dataset.comparisonReady='true';if(selectedIndices.size===1)showRecords([...selectedIndices][0]);
 }
 import('./rainfall-annual-table.js').then(module=>module.mountAnnualRainfallTable(features)).catch(error=>console.warn('Annual rainfall table unavailable:',error.message));
 const names=[...new Set(features.map(f=>f.properties.subdistrict||f.properties.source_subdistrict||'Unspecified'))].sort(),palette=['#ea4335','#fbbc05','#4285f4','#a142f4','#34a853'],scaleColours=['#ea4335','#fbbc05','#4285f4','#a142f4','#34a853'],breaks={population:[500,2000,5000,10000],households:[100,250,500,1000],literacy:[50,65,80,90],water:[25,50,75,100],rainfall:[500,1000,1500,2000]};
 for(const key of ['water_households','water_taps'])breaks[key]=[100,250,500,1000];
 const waterElement=document.getElementById('polygon-water-values'),waterValues=waterElement?JSON.parse(waterElement.textContent):{};
 {const option=el('option','Water · tap connection coverage (%)');option.value='water';metric.append(option);for(const [index,row]of Object.entries(waterValues)){if(features[index]){features[index].water=row;features[index].values={...features[index].values,...jjmValues(row)};}}}
 for(const name of names){const o=el('option',name);o.value=name;sub.append(o);}
 const rings=g=>g?.type==='Polygon'?g.coordinates:g?.type==='MultiPolygon'?g.coordinates.flat():[];
 const bounds=[Infinity,Infinity,-Infinity,-Infinity];for(const f of features)for(const ring of rings(f.geometry))for(const [x,y]of ring){bounds[0]=Math.min(bounds[0],x);bounds[1]=Math.min(bounds[1],y);bounds[2]=Math.max(bounds[2],x);bounds[3]=Math.max(bounds[3],y);}
 const [w,s,e,n]=bounds,cos=Math.cos((n+s)*Math.PI/360),scale=Math.min(700/((e-w)*cos),480/(n-s)),project=([x,y])=>[(760-(e-w)*cos*scale)/2+(x-w)*cos*scale,(540-(n-s)*scale)/2+(n-y)*scale];
 const ns=svg.namespaceURI,group=document.createElementNS(ns,'g'),paths=[];svg.append(group);
 const show=(f)=>{tip.replaceChildren(el('strong',f.properties.name||'Unnamed source place'),el('p',f.properties.subdistrict||f.properties.source_subdistrict||''));for(const [key,label]of [['population','Population'],['households','Households'],['literacy','Literacy']]){const v=f.values?.[key];tip.append(el('p',`${label}: ${v==null?'Unavailable':Number(v).toLocaleString('en-IN')+(key==='literacy'?'%':'')}`));}if(metric.value==='rainfall')tip.append(el('p',`Rainfall ${f.rainfallYear||document.getElementById('polygon-rainfall-year')?.value}: ${f.values?.rainfall==null?'Unavailable':f.values.rainfall+' mm'} (IMD 0.25° grid estimate)`));tip.append(el('small',`Census ${censusYear.value} | original source identifiers retained`));if(f.water||isJjm(metric.value)){const water=f.water;if(!water)tip.append(el('p',jjmMissingNote(f)));tip.append(el('p',`JJM tap coverage: ${water?.coverage_pct==null?'Unavailable':water.coverage_pct+'%'}`));if(water){tip.append(el('p',`JJM households: ${water.households.toLocaleString('en-IN')} · Tap connections: ${water.tap_connections.toLocaleString('en-IN')}`));}}if(isSoil(metric.value))tip.append(el('p',`${soilMetrics[metric.value][0]}: ${f.values?.[metric.value]==null?'Unavailable':f.values[metric.value]+'%'} · SHC ${soilCycle.value} - ${soilSampleCount(f.soil,metric.value)??'Unavailable'} tested samples`));if(censusYear.value==='2001'&&/^(vd_|pca_)/.test(metric.value)){const raw=rawCensusValue(f.history,metric.value);tip.append(el('p',metric.selectedOptions[0].textContent+': '+(raw===null?'Unavailable':String(raw))));}if(metric.value.startsWith('c11_'))tip.append(el('p',metric.selectedOptions[0].textContent+': '+(f.values?.[metric.value]??'Unavailable')));renderDirectory(tip,censusYear.value==='2001'?f.history:null);tip.hidden=false;};
 features.forEach((f,i)=>{const p=document.createElementNS(ns,'path');p.setAttribute('d',rings(f.geometry).map(r=>r.map((c,j)=>`${j?'L':'M'}${project(c).join(',')}`).join(' ')+'Z').join(' '));p.setAttribute('fill-rule','evenodd');p.setAttribute('tabindex','0');p.setAttribute('aria-label',f.properties.name||'Source place');p.addEventListener('mouseenter',()=>show(f));p.addEventListener('mouseleave',()=>tip.hidden=true);p.addEventListener('focus',()=>show(f));p.addEventListener('blur',()=>tip.hidden=true);p.addEventListener('click',()=>{if(dragged)return;selectPolygon(i);show(f);});p.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selectPolygon(i);show(f);}});group.append(p);paths.push(p);});
 let markerOrderKey='';
 const smallSourceShapes=paths.map(path=>{const box=path.getBBox();return box.width<3&&box.height<3;});
 const geometryNotice=el('p','Coloured markers emphasize very small source village shapes. They show linked JJM records, not village boundary coverage. Grey source areas have no assigned JJM value. Select a village to inspect its original shape and values.');geometryNotice.id='jjm-source-geometry-note';geometryNotice.hidden=true;geometryNotice.style.cssText='margin:0;padding:10px 20px;background:#f7fafc;color:#52645f;font-size:13px';legend.after(geometryNotice);
 const belongs=f=>!sub.value||(f.properties.subdistrict||f.properties.source_subdistrict||'Unspecified')===sub.value;
 function options(){village.replaceChildren(el('option','All villages'));village.firstChild.value='';features.map((f,i)=>({f,i})).filter(({f})=>belongs(f)).sort((a,b)=>(a.f.properties.name||'').localeCompare(b.f.properties.name||'')).forEach(({f,i})=>{const o=el('option',f.properties.name||'Unnamed source place');o.value=String(i);village.append(o);});}
 const selectedIndices=new Set();
 const recordPanel=el('section');recordPanel.id='polygon-records';recordPanel.setAttribute('aria-live','polite');root.after(recordPanel);
 let recordVersion=0,recordLayerPromise;
 const recordLayer=()=>recordLayerPromise??=(fetch(root.dataset.catalogue).then(r=>{if(!r.ok)throw Error('Map catalogue unavailable');return r.json();}).then(c=>c.layers.find(l=>l.file===root.dataset.file)));
 async function showRecords(index){
  const version=++recordVersion;recordPanel.replaceChildren();if(index===undefined)return;
  recordPanel.append(el('h2',features[index].properties.name||'Village records'),el('p','Loading linked village records…'));
  try{
   const layer=await recordLayer();
   const sourceIndex=data.features?index:data.rows.map((row,i)=>row.geometry?i:null).filter(i=>i!==null)[index];
   const directoryLinks=data.rows?.[sourceIndex]?.directory_links||[];
   const lgd=features[index].lgd_code||(directoryLinks.length===1?directoryLinks[0].code:null);
   if(!layer&&!lgd)throw Error('This source shape has no verified village profile link.');
   const response=await fetch(lgd?`/api/india-map/villages/${lgd}?records=1`:`/api/india-map/layers/${layer.id}/features/${sourceIndex}`);if(!response.ok)throw Error('Village records temporarily unavailable.');
   const place=await response.json();if(version!==recordVersion)return;
   recordPanel.replaceChildren(el('h2',place.name||'Village records'));const water=features[index].water;if(water){recordPanel.append(el('h3','Water · Jal Jeevan Mission'),el('p',`JJM households: ${water.households??'Unavailable'} | Household tap connections: ${water.tap_connections??'Unavailable'} | Tap connection coverage: ${water.coverage_pct==null?'Unavailable':water.coverage_pct+'%'}`),el('p',`JJM source retrieved: ${water.retrieved_at||'See original source'}. Independent of the selected Census year.`));if(water.source_url){try{const url=new URL(water.source_url);if(url.protocol==='https:'){const source=el('a','Official JJM source');source.href=url.href;source.rel='noopener';source.target='_blank';recordPanel.append(source);}}catch{}}}if(!features[index].water)recordPanel.append(el('p',jjmMissingNote(features[index])));const selected=features[index].values||{};recordPanel.append(el('h3',`Census ${censusYear.value}`),el('p',`Population: ${selected.population??'Unavailable'} | Households: ${selected.households??'Unavailable'} | Literacy (age 7+): ${selected.literacy==null?'Unavailable':selected.literacy+'%'}`));
   const addLink=(text,url)=>{if(!url)return;const target=new URL(url,location.origin);if(target.origin!==location.origin)return;const link=el('a',text);link.href=target.href;const paragraph=el('p');paragraph.append(link);recordPanel.append(paragraph);};
   addLink('Open village profile →',place.url);
   addLink('Show village on India map →',place.id?`/india/map?place=${place.id}`:lgd?`/india/map?village=${lgd}`:null);
   for(const record of place.census_history||[])addLink(`Open Census ${record.year} village record →`,record.url);
   recordPanel.append(el('p',place.url?'Linked records retain their Census year. Displayed boundaries do not establish historical boundaries or growth comparability.':'A verified village profile link is pending for this source shape.'));
  }catch(error){if(version===recordVersion)recordPanel.replaceChildren(el('p',jjmMissingNote(features[index])),el('p',error.message));}
 }

 function selectionPaths(){const selected=paths.filter((p,i)=>selectedIndices.has(i)&&!p.hidden);return selected.length?selected:paths.filter(p=>!p.hidden);}
 function fit(active=selectionPaths()){group.removeAttribute('transform');if(!active.length){svg.setAttribute('viewBox','0 0 760 540');return;}const b=active.reduce((b,p)=>{const r=p.getBBox();return [Math.min(b[0],r.x),Math.min(b[1],r.y),Math.max(b[2],r.x+r.width),Math.max(b[3],r.y+r.height)];},[Infinity,Infinity,-Infinity,-Infinity]);const padding=Math.max(b[2]-b[0],b[3]-b[1])*.06||1;svg.setAttribute('viewBox',`${b[0]-padding} ${b[1]-padding} ${b[2]-b[0]+2*padding} ${b[3]-b[1]+2*padding}`);}
 function render(){if(/^(vd_|pca_|c11_)/.test(metric.value))breaks[metric.value]=nativeBreaks(features.map(f=>f.values?.[metric.value]));tip.hidden=true;paths.forEach((p,i)=>p.classList.toggle('is-selected',selectedIndices.has(i)));let shown=0;const key=metric.value;min.disabled=max.disabled=missing.disabled=!key;features.forEach((f,i)=>{const v=f.values?.[key],match=`${f.properties.name} ${f.properties.source_code}`.toLowerCase().includes(search.value.toLowerCase())&&(!key||(v==null?missing.checked:(min.value===''||v>=Number(min.value))&&(max.value===''||v<=Number(max.value))));paths[i].hidden=!match;paths[i].style.display=match?'':'none';if(match)shown++;paths[i].setAttribute('fill',key?(v==null?'#9ca3af':typeof v==='string'?'#547ae5':(isSoil(key)?[...scaleColours].reverse():scaleColours)[(breaks[key]||[1,5,10,50]).filter(b=>v>=b).length]):palette[names.indexOf(f.properties.subdistrict||f.properties.source_subdistrict||'Unspecified')%palette.length]);const path=paths[i];path.style.stroke='';path.style.strokeWidth='';path.style.vectorEffect='';if(selectedIndices.has(i)){path.style.stroke='#e49b16';path.style.strokeWidth='3px';path.style.vectorEffect='non-scaling-stroke';}if(isJjm(key)&&v!=null&&smallSourceShapes[i]&&!selectedIndices.has(i)){path.style.stroke=path.getAttribute('fill');path.style.strokeWidth='6px';path.style.vectorEffect='non-scaling-stroke';}});const markerIndices=isJjm(key)?features.map((f,i)=>smallSourceShapes[i]&&f.values?.[key]!=null?i:null).filter(i=>i!==null):[],nextMarkerOrder=markerIndices.join(',');if(nextMarkerOrder!==markerOrderKey){for(const path of paths)group.append(path);for(const i of markerIndices)group.append(paths[i]);markerOrderKey=nextMarkerOrder;}geometryNotice.hidden=!isJjm(key)||!features.some((f,i)=>smallSourceShapes[i]&&f.values?.[key]!=null);legend.replaceChildren();const labels=key?[`< ${(breaks[key]||[1,5,10,50])[0]}`,...(breaks[key]||[1,5,10,50]).slice(0,-1).map((v,i)=>`${v} – < ${(breaks[key]||[1,5,10,50])[i+1]}`),`≥ ${(breaks[key]||[1,5,10,50])[3]}`,'Unavailable']:names;labels.forEach((label,i)=>{const item=el('span',label),swatch=el('i');swatch.style.background=key?((isSoil(key)?[...scaleColours].reverse():scaleColours)[i]||'#9ca3af'):palette[i%palette.length];item.prepend(swatch);legend.append(item);});if(/^(vd_|pca_)/.test(key)&&features.some(f=>typeof f.values?.[key]==='string')){const text=el('span','Reported text (see popup)'),swatch=el('i');swatch.style.background='#547ae5';text.prepend(swatch);legend.append(text);}document.getElementById('polygon-status').textContent=`${shown} of ${features.length} source polygons displayed${isSoil(key)?` - SHC ${soilCycle.value} - tested samples; grey means unavailable`:key==='rainfall'?' - IMD annual rainfall (mm), 0.25° grid estimate':isJjm(key)?` · JJM snapshot 13–14 October 2026 · ${Object.keys(waterValues).length} polygons linked to water records`:key?` | Census ${censusYear.value} | values shown only where linked`:''}`;}
 function notifySelection(){root.dispatchEvent(new CustomEvent('place-map-selection',{detail:{indices:[...selectedIndices]}}));}
 const shortcuts=el('nav');shortcuts.className='area-navigation';shortcuts.setAttribute('aria-label','Selected place pages');root.prepend(shortcuts);
 function selectionLinks(){shortcuts.replaceChildren();const matches=subdistrictPages.filter(row=>row.name===sub.value);if(matches.length===1){const link=el('a',`Open ${matches[0].name} subdistrict →`);link.href=matches[0].url;shortcuts.append(link);}const feature=village.value!==''?features[Number(village.value)]:null;if(feature?.lgd_code&&/^[0-9]{1,8}$/.test(String(feature.lgd_code))){const link=el('a',`Open ${village.selectedOptions[0].textContent} village →`);link.href='/india/elections/village-map/villages/'+encodeURIComponent(feature.lgd_code);shortcuts.append(link);}}
 function selectFromFilters(){selectionLinks();selectedIndices.clear();features.forEach((f,i)=>{if(village.value!==''?Number(village.value)===i:sub.value&&belongs(f))selectedIndices.add(i);});render();fit(paths.filter(p=>!p.hidden));showRecords(selectedIndices.size===1?[...selectedIndices][0]:undefined);notifySelection();window.dispatchEvent(new Event('census-comparison-refresh'));}
 const subdistrictPages=JSON.parse(document.getElementById('polygon-subdistrict-pages')?.textContent||'[]');
 sub.addEventListener('change',()=>{options();selectFromFilters();});village.addEventListener('change',selectFromFilters);search.addEventListener('input',render);metric.addEventListener('change',()=>{min.value=max.value='';render();});for(const c of [min,max,missing])c.addEventListener('input',render);
 document.getElementById('polygon-fit').addEventListener('click',()=>fit());for(const [id,factor]of [['polygon-in',.75],['polygon-out',1.333]])document.getElementById(id).addEventListener('click',()=>{const b=svg.viewBox.baseVal,ww=b.width*factor,hh=b.height*factor;svg.setAttribute('viewBox',`${b.x+(b.width-ww)/2} ${b.y+(b.height-hh)/2} ${ww} ${hh}`);});options();render();fit();
 let dragged=false;
 function selectPolygon(index){selectedIndices.clear();selectedIndices.add(index);render();fit();showRecords(index);notifySelection();}
 const reset=el('button','Back to district');reset.id='polygon-reset';reset.type='button';reset.addEventListener('click',()=>{selectedIndices.clear();sub.value='';search.value='';min.value=max.value='';missing.checked=true;options();render();fit();notifySelection();});document.getElementById('polygon-fit').after(reset);
 function zoomAt(factor,clientX,clientY){const point=new DOMPoint(clientX,clientY).matrixTransform(svg.getScreenCTM().inverse()),b=svg.viewBox.baseVal;const width=Math.max(.05,Math.min(3040,b.width*factor)),ratio=width/b.width;svg.setAttribute('viewBox',`${point.x+(b.x-point.x)*ratio} ${point.y+(b.y-point.y)*ratio} ${width} ${b.height*ratio}`);}
 svg.addEventListener('wheel',event=>{event.preventDefault();zoomAt(Math.exp(Math.max(-100,Math.min(100,event.deltaY))*.003),event.clientX,event.clientY);},{passive:false});
 svg.addEventListener('dblclick',event=>{event.preventDefault();zoomAt(.5,event.clientX,event.clientY);});
 let pan=null;
 svg.addEventListener('pointerdown',event=>{if(event.button!==0)return;dragged=false;const b=svg.viewBox.baseVal;pan={id:event.pointerId,x:event.clientX,y:event.clientY,box:[b.x,b.y,b.width,b.height],matrix:svg.getScreenCTM().inverse()};});
 svg.addEventListener('pointermove',event=>{if(!pan||event.pointerId!==pan.id)return;const dx=event.clientX-pan.x,dy=event.clientY-pan.y;if(!dragged&&Math.hypot(dx,dy)<5)return;dragged=true;tip.hidden=true;svg.setPointerCapture(event.pointerId);svg.classList.add('is-panning');const m=pan.matrix,b=pan.box;svg.setAttribute('viewBox',`${b[0]-dx*m.a-dy*m.c} ${b[1]-dx*m.b-dy*m.d} ${b[2]} ${b[3]}`);});
 function endPan(event){if(pan&&event.pointerId===pan.id){pan=null;svg.classList.remove('is-panning');if(svg.hasPointerCapture(event.pointerId))svg.releasePointerCapture(event.pointerId);}}
 svg.addEventListener('pointerup',endPan);svg.addEventListener('pointercancel',endPan);svg.addEventListener('pointerleave',event=>{if(!dragged)endPan(event);});
 {try{const layer=root.dataset.layerId?{id:root.dataset.layerId,sha256:root.dataset.layerSha,file:root.dataset.file}:await fetch(root.dataset.catalogue).then(r=>r.json()).then(catalogue=>catalogue.layers.find(l=>l.file===root.dataset.file)||catalogue.layers.find(l=>l.state===current?.state_name&&l.district===current?.name));if(layer){const response=await fetch(`${root.dataset.metrics}/${layer.id}?sha=${layer.sha256}&year=2011`);if(!response.ok)throw Error();const metrics=await response.json();for(const row of metrics.records){const index=layer.file===root.dataset.file?(data.features?row.feature_index:data.rows.map((r,i)=>r.geometry?i:null).filter(i=>i!==null).indexOf(row.feature_index)):features.findIndex(f=>String(f.lgd_code||'')===String(row.lgd_code||'')&&row.lgd_code);if(!features[index])continue;const feature=features[index];feature.lgd_code=soilCode(row);feature.water_note=row.water_note;if(row.water){feature.water=row.water;waterValues[row.feature_index]=row.water;}feature.values={...row.values,...(feature.water?{...jjmValues(feature.water)}:{})};feature.baseline2011={...feature.values};}render();if(selectedIndices.size===1)showRecords([...selectedIndices][0]);}}catch{document.getElementById('polygon-status').textContent+=' · Linked statistics temporarily unavailable';}}
 {
  for(const [key,[label]]of Object.entries(soilMetrics)){breaks[key]=[25,50,75,100];const option=el('option',label);option.value=key;metric.append(option);}
  if(data.rows){const rows=data.rows.filter(row=>row.geometry);features.forEach((feature,index)=>{const links=rows[index]?.directory_links||[];if(links.length===1&&rows[index].kind!=='town')feature.lgd_code=links[0].code;});}
  const soilCodes=[...new Set(features.map(feature=>feature.lgd_code).filter(Boolean))];
  root.dataset.soilLgd=JSON.stringify(soilCodes);
  import('./soil-panel.js?v=20261016-after-villages').catch(error=>console.warn(error.message));
  let soilLoaded=false,soilRequest=0;
  async function updateSoil(){if(!isSoil(metric.value))return;soilDisclaimer();if(soilLoaded){render();return;}
   const version=++soilRequest;try{const soil=await loadSoil(soilCycle.value,soilCodes);if(version!==soilRequest)return;
    const byCode=new Map(soil.records.map(row=>[row.lgd_village_code,row]));
    for(const feature of features){const row=byCode.get(String(feature.lgd_code));feature.soil=row;feature.values={...feature.values,...soilValues(row)};}
    soilLoaded=true;if(isSoil(metric.value))render();
   }catch(error){document.getElementById('polygon-status').textContent=error.message;}
  }
  metric.addEventListener('change',updateSoil);root.addEventListener('soil-cycle-change',()=>{soilRequest++;soilLoaded=false;for(const feature of features){feature.soil=null;feature.values={...feature.values,...soilValues(null)};}render();updateSoil();});
 }
 {
  const option=el('option','Rainfall · IMD grid estimate (mm)');option.value='rainfall';metric.append(option);
  const label=el('label','Rainfall year '),yearSelect=el('select');yearSelect.id='polygon-rainfall-year';label.htmlFor=yearSelect.id;label.hidden=true;label.style.display='none';
  for(const year of ['2024','2023']){const o=el('option',year);o.value=year;yearSelect.append(o);}label.append(yearSelect);missing.parentElement.after(label);
  let rainPromise,request=0;
  async function updateRain(){label.hidden=metric.value!=='rainfall';label.style.display=label.hidden?'none':'';if(label.hidden)return;const version=++request,year=yearSelect.value;
   document.getElementById('polygon-status').textContent='Loading IMD rainfall…';
   try{rainPromise??=import('./rainfall-annual-table.js').then(module=>module.getAnnualRainfallValues(features)).catch(error=>{rainPromise=null;throw error;});const values=await rainPromise;if(version!==request||metric.value!=='rainfall')return;
    features.forEach((feature,index)=>{feature.rainfallYear=year;feature.values={...feature.values,rainfall:values[index][year]??null};});render();
   }catch{document.getElementById('polygon-status').textContent='Rainfall temporarily unavailable. Please try again.';}
  }
  metric.addEventListener('change',updateRain);yearSelect.addEventListener('change',()=>{min.value=max.value='';updateRain();});
  const disclaimer=el('details');disclaimer.className='panel card coverage';disclaimer.id='polygon-rainfall-disclaimer';disclaimer.append(el('summary','Rainfall sources, dates and methodology'),el('p','IMD 2023–2024 annual rainfall in millimetres, calculated from the existing 0.25° daily grids downloaded on 14 October 2026. Estimates use area-weighted intersections with the original displayed polygons. These are area estimates, not village rain-gauge observations. Missing daily readings or grid coverage remain unavailable, never zero-filled. Coastal and island areas can lack complete coverage. Original LGD, SOI and Census IDs and polygons are unchanged. Two years do not establish a climate normal.'));
  const source=el('a','Official IMD rainfall source ↗');source.href='https://imdpune.gov.in/cmpg/Griddata/Rainfall_25_NetCDF.html';source.target='_blank';source.rel='noopener';disclaimer.append(source);document.querySelector('main')?.append(disclaimer);
 }

 censusReady=true;await updateCensusYear();
 const requestedVillage=new URL(location.href).searchParams.get('village');
 if(requestedVillage&&/^[0-9]{1,8}$/.test(requestedVillage)){
  const matches=features.map((f,i)=>String(f.lgd_code||'')===requestedVillage?i:null).filter(i=>i!==null);
  if(matches.length===1){village.value=String(matches[0]);selectPolygon(matches[0]);}
  else {
   const response=await fetch(`/api/india-map/villages/${requestedVillage}`);
   if(response.ok){const place=await response.json(),layer=await recordLayer();
    if(data.features&&place.layer===layer?.id&&features[place.feature_index]){village.value=String(place.feature_index);selectPolygon(place.feature_index);}
    else recordPanel.append(el('p','The requested village has no unique verified shape in this district layer.'));
   }
  }
 }
}
if(root)start().catch(e=>document.getElementById('polygon-status').textContent=e.message);
if(root)import('./satellite-comparison-20261018-highlight.js').then(module=>module.mountSatelliteComparison()).catch(error=>console.warn(error.message));
