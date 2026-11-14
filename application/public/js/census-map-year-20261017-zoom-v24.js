import {isJjm,jjmNote} from './jjm-map-values-20261016.js';
import {yearPlayback} from './census-year-comparison-20261017-zoom-v26.js?v=home-click-20261114';
const fields2011={"F_06": "Children aged 0–6 · female", "F_ILL": "Illiterate population · female", "F_LIT": "Literate population · female", "F_SC": "Scheduled Caste population · female", "F_ST": "Scheduled Tribe population · female", "MAINWORK_F": "Main workers · female", "MAINWORK_M": "Main workers · male", "MAINWORK_P": "Main workers · total", "MAIN_AL_F": "Main workers · agricultural labourers · female", "MAIN_AL_M": "Main workers · agricultural labourers · male", "MAIN_AL_P": "Main workers · agricultural labourers · total", "MAIN_CL_F": "Main workers · cultivators · female", "MAIN_CL_M": "Main workers · cultivators · male", "MAIN_CL_P": "Main workers · cultivators · total", "MAIN_HH_F": "Main workers · household industry · female", "MAIN_HH_M": "Main workers · household industry · male", "MAIN_HH_P": "Main workers · household industry · total", "MAIN_OT_F": "Main workers · other workers · female", "MAIN_OT_M": "Main workers · other workers · male", "MAIN_OT_P": "Main workers · other workers · total", "MARGWORK_0_3_F": "Marginal workers · under 3 months · female", "MARGWORK_0_3_M": "Marginal workers · under 3 months · male", "MARGWORK_0_3_P": "Marginal workers · under 3 months · total", "MARGWORK_3_6_F": "Marginal workers · 3–6 months · female", "MARGWORK_3_6_M": "Marginal workers · 3–6 months · male", "MARGWORK_3_6_P": "Marginal workers · 3–6 months · total", "MARGWORK_F": "Marginal workers · female", "MARGWORK_M": "Marginal workers · male", "MARGWORK_P": "Marginal workers · total", "MARG_AL_0_3_F": "Marginal workers · agricultural labourers · under 3 months · female", "MARG_AL_0_3_M": "Marginal workers · agricultural labourers · under 3 months · male", "MARG_AL_0_3_P": "Marginal workers · agricultural labourers · under 3 months · total", "MARG_AL_3_6_F": "Marginal workers · agricultural labourers · 3–6 months · female", "MARG_AL_3_6_M": "Marginal workers · agricultural labourers · 3–6 months · male", "MARG_AL_3_6_P": "Marginal workers · agricultural labourers · 3–6 months · total", "MARG_AL_F": "Marginal workers · agricultural labourers · female", "MARG_AL_M": "Marginal workers · agricultural labourers · male", "MARG_AL_P": "Marginal workers · agricultural labourers · total", "MARG_CL_0_3_F": "Marginal workers · cultivators · under 3 months · female", "MARG_CL_0_3_M": "Marginal workers · cultivators · under 3 months · male", "MARG_CL_0_3_P": "Marginal workers · cultivators · under 3 months · total", "MARG_CL_3_6_F": "Marginal workers · cultivators · 3–6 months · female", "MARG_CL_3_6_M": "Marginal workers · cultivators · 3–6 months · male", "MARG_CL_3_6_P": "Marginal workers · cultivators · 3–6 months · total", "MARG_CL_F": "Marginal workers · cultivators · female", "MARG_CL_M": "Marginal workers · cultivators · male", "MARG_CL_P": "Marginal workers · cultivators · total", "MARG_HH_0_3_F": "Marginal workers · household industry · under 3 months · female", "MARG_HH_0_3_M": "Marginal workers · household industry · under 3 months · male", "MARG_HH_0_3_P": "Marginal workers · household industry · under 3 months · total", "MARG_HH_3_6_F": "Marginal workers · household industry · 3–6 months · female", "MARG_HH_3_6_M": "Marginal workers · household industry · 3–6 months · male", "MARG_HH_3_6_P": "Marginal workers · household industry · 3–6 months · total", "MARG_HH_F": "Marginal workers · household industry · female", "MARG_HH_M": "Marginal workers · household industry · male", "MARG_HH_P": "Marginal workers · household industry · total", "MARG_OT_0_3_F": "Marginal workers · other workers · under 3 months · female", "MARG_OT_0_3_M": "Marginal workers · other workers · under 3 months · male", "MARG_OT_0_3_P": "Marginal workers · other workers · under 3 months · total", "MARG_OT_3_6_F": "Marginal workers · other workers · 3–6 months · female", "MARG_OT_3_6_M": "Marginal workers · other workers · 3–6 months · male", "MARG_OT_3_6_P": "Marginal workers · other workers · 3–6 months · total", "MARG_OT_F": "Marginal workers · other workers · female", "MARG_OT_M": "Marginal workers · other workers · male", "MARG_OT_P": "Marginal workers · other workers · total", "M_06": "Children aged 0–6 · male", "M_ILL": "Illiterate population · male", "M_LIT": "Literate population · male", "M_SC": "Scheduled Caste population · male", "M_ST": "Scheduled Tribe population · male", "NON_WORK_F": "Non-workers · female", "NON_WORK_M": "Non-workers · male", "NON_WORK_P": "Non-workers · total", "No_HH": "Households", "P_06": "Children aged 0–6 · total", "P_ILL": "Illiterate population · total", "P_LIT": "Literate population · total", "P_SC": "Scheduled Caste population · total", "P_ST": "Scheduled Tribe population · total", "TOT_F": "Population · female", "TOT_M": "Population · male", "TOT_P": "Population · total", "TOT_WORK_F": "All workers · female", "TOT_WORK_M": "All workers · male", "TOT_WORK_P": "All workers · total"};
const requests=new Map();

export const censusMetric=key=>['population','households','literacy'].includes(key)||key.startsWith('vd_')||key.startsWith('pca_')||key.startsWith('c11_');

export function censusValues(record){

 const valid=n=>typeof n==='number'&&Number.isFinite(n)&&n>=0?n:null;

 const population=valid(record?.population),households=valid(record?.households),children=valid(record?.population_0_6),literate=valid(record?.literate);

 const denominator=population!==null&&children!==null?population-children:null;

 return {...directoryValues(record),...pcaValues(record),population,households,literacy:denominator>0&&literate!==null&&literate<=denominator?Math.round(10000*literate/denominator)/100:null};

}

export function datedRecords(records,history,year){return records.map(row=>{

 const observation=row.level==='TOWN'?null:history[String(row.lgd_code||'')];

 return {...row,history:observation,values:{...row.values,...(year==='2001'?censusValues(observation):{})}};

});}

export async function loadHistory(codes){

 const unique=[...new Set(codes.filter(c=>/^[0-9]{1,8}$/.test(String(c))).map(String))].sort(),result={};

 for(let offset=0;offset<unique.length;offset+=600){
  await Promise.all([0,200,400].map(async step=>{
   const codes=unique.slice(offset+step,offset+step+200);if(!codes.length)return;const key=codes.join(',');
   if(!requests.has(key))requests.set(key,fetch(`/api/india-map/history?codes=${key}`).then(r=>{if(!r.ok)throw Error('Census 2001 records temporarily unavailable.');return r.json();}).then(d=>d.records).catch(e=>{requests.delete(key);throw e;}));
   Object.assign(result,await requests.get(key));
  }));
 }

 if(typeof window!=='undefined'){const fields={};for(const record of Object.values(result))Object.assign(fields,record.directory?.fields||{});if(Object.keys(fields).length)window.dispatchEvent(new CustomEvent('census-directory-fields',{detail:fields}));}

 if(typeof window!=='undefined'){const fields={};for(const record of Object.values(result))Object.assign(fields,record.pca?.fields||{});if(Object.keys(fields).length)window.dispatchEvent(new CustomEvent('census-pca-fields',{detail:fields}));}

 return result;

}

const fieldTitle=(key,title)=>({P_06:'Population aged 0–6 · total',M_06:'Population aged 0–6 · male',F_06:'Population aged 0–6 · female',TOT_P:'Population · total',TOT_M:'Population · male',TOT_F:'Population · female',P_LIT:'Literate population · total',M_LIT:'Literate population · male',F_LIT:'Literate population · female'}[key]||title);

export function yearControl(metric,onChange){

 const available=new Map(Object.entries(fields2011).flatMap(([key,title])=>[['c11_'+key,{key,title}],...(/0_3|3_6/.test(key)?[]:[['pca_'+key,{key,title}]])]));
 const topicLabel=document.createElement('label');topicLabel.textContent='Topic ';
 const topic=document.createElement('select');topic.setAttribute('aria-label','Census topic');
 for(const name of ['Education','Population and work','Employment','Census water','Health','Other facilities']){const option=document.createElement('option');option.value=name;option.textContent=name;topic.append(option);}
 topicLabel.append(topic);(metric.closest('label')||metric).after(topicLabel);metric.style.cssText+=';min-width:240px;max-width:100%;padding:12px;border:1px solid #9eb9b2;border-radius:7px;background:white;font:inherit;font-weight:600';

 const groupFor=(key,title)=>/workers|cultivators|labourers|industry/i.test(title)?'Employment':/school|college|liter|education|training/i.test(title)?'Education':/water|well|tank|handpump|spring|canal|river/i.test(title)?'Census water':/population|workers|literate|household|age|scheduled/i.test(title)||key.startsWith('pca_')||key.startsWith('c11_')?'Population and work':/medical|health|hospital|dispens|maternity|nursing/i.test(title)?'Health':'Other facilities';
 const renderFields=()=>{
  const selected=metric.value,temporary=[...metric.options].find(o=>o.value===selected&&o.dataset.comparisonTemporary);
  metric.querySelectorAll('option[data-comparison-temporary]').forEach(option=>option.remove());metric.querySelectorAll('optgroup[data-census-fields]').forEach(group=>group.remove());
  topicLabel.hidden=true;topicLabel.style.display='none';
  for(const option of topic.options){option.hidden=option.disabled=select.value==='2011'&&!['Education','Population and work','Employment'].includes(option.value);}
  if(topic.selectedOptions[0]?.disabled)topic.value='Education';
  {
   const groups=new Map();
   for(const [value,{key,title}] of available){
    if(value.startsWith('c11_')!==(select.value==='2011'))continue;
    if(/RANG|CODE|NAME|COLUMN|SERIAL|SL_NO/i.test(key)||/range code|source code|if not available/i.test(title))continue;
    if(['T_P','T_HH','TOT_P','No_HH'].includes(key))continue;
    let text=fieldTitle(key,title).replace(/^Number of /i,'').replace(/\s*\([^)]*\)/g,'').replace(/Collage/g,'College').trim();
    if(text===key||/^[A-Z0-9_]+$/.test(text))continue;
    const name=groupFor(value,text);
    if(!groups.has(name)){const group=document.createElement('optgroup');group.label=name;group.dataset.censusFields='2001';groups.set(name,group);}
    const option=document.createElement('option');option.value=value;option.textContent=text;groups.get(name).append(option);
   }
   const order=option=>{const text=option.textContent.toLowerCase(),sex=/ · (total|male|female)$/.exec(text);return [text.replace(/ · (total|male|female)$/,''),sex?{total:0,male:1,female:2}[sex[1]]:0];};
   for(const group of groups.values())group.append(...[...group.children].sort((a,b)=>{const x=order(a),y=order(b);return x[0].localeCompare(y[0])||x[1]-y[1];}));
   for(const name of ['Education','Population and work','Employment','Census water','Health','Other facilities'])if(groups.has(name))metric.append(groups.get(name));
  }
  if([...metric.options].some(option=>option.value===selected))metric.value=selected;
  else if(temporary&&selected.startsWith(select.value==='2001'?'pca_':'c11_')){metric.append(temporary);metric.value=selected;}else if(/^(vd_|pca_|c11_)/.test(selected))metric.value=topic.value==='Education'?'literacy':'population';
 };
 topic.addEventListener('change',()=>{renderFields();metric.dispatchEvent(new Event('change',{bubbles:true}));});
 for(const [eventName,prefix] of [['census-directory-fields','vd_'],['census-pca-fields','pca_']])window.addEventListener(eventName,event=>{
  for(const [key,title] of Object.entries(event.detail))available.set(prefix+key,{key,title});
  renderFields();
 });

 const label=document.createElement('label');label.textContent='Census year ';label.htmlFor='census-map-year';

 const select=document.createElement('select');select.id='census-map-year';select.style.cssText='min-width:140px;padding:12px;border:1px solid #9eb9b2;border-radius:7px;background:white;font:inherit';select.setAttribute('aria-label','Census year');

 for(const year of ['2011','2001']){const option=document.createElement('option');option.value=year;option.textContent=year;select.append(option);}

 select.value=new URL(location.href).searchParams.get('censusYear')==='2001'?'2001':'2011';label.append(select);(metric.closest('label')||metric).after(label);

 const note=document.createElement('p');note.id='census-year-note';label.after(note);

 const sync=()=>{const water=isJjm(metric.value);for(const old of document.querySelectorAll('.metric-controls > p'))if(old!==note)old.hidden=water;label.style.display=water?'none':'flex';select.disabled=!censusMetric(metric.value);note.hidden=select.disabled&&!water;note.textContent=water?jjmNote:`Census ${select.value} · Existing source boundaries. Historical boundary comparability is unverified; Original values and caution notes are displayed; missing cells remain missing.`;};

 select.addEventListener('change',event=>{const comparison=event.detail?.comparison;if(!comparison&&/^(vd_|pca_|c11_)/.test(metric.value)){topic.value='Education';metric.value='literacy';}renderFields();if(comparison){let field=comparison.field;if(/^(pca_|c11_)/.test(field))field=(select.value==='2001'?'pca_':'c11_')+field.replace(/^(pca_|c11_)/,'');else if(field.startsWith('vd_')&&select.value!=='2001')field='c11_UNAVAILABLE';if(/^(vd_|pca_|c11_)/.test(field)&&!available.has(field))field=(select.value==='2001'?'pca_':'c11_')+'UNAVAILABLE';if(![...metric.options].some(o=>o.value===field)){const option=new Option(comparison.title,field);option.dataset.comparisonTemporary='true';metric.append(option);}metric.value=field;}sync();const url=new URL(location.href);url.searchParams.set('censusYear',select.value);history.replaceState(null,'',url);onChange();});metric.addEventListener('change',()=>{if((metric.value.startsWith('vd_')||metric.value.startsWith('pca_'))&&select.value!=='2001'){select.value='2001';onChange();}sync();});renderFields();sync();
 const mainLabel=document.createElement('label');mainLabel.textContent='Map data ';
 const main=document.createElement('select');main.setAttribute('aria-label','Main parameter');
 const detailLabel=document.createElement('label');detailLabel.textContent='Measure ';
 const detail=document.createElement('select');detail.setAttribute('aria-label','Secondary parameter');
 const style='min-width:220px;max-width:100%;padding:12px;border:1px solid #9eb9b2;border-radius:7px;background:white;font:inherit;font-weight:600';
 main.style.cssText=detail.style.cssText=style;mainLabel.append(main);detailLabel.append(detail);
 const originalLabel=metric.closest('label');
 for(const external of metric.labels||[]){if(external!==originalLabel){external.hidden=true;external.style.display='none';}}
 if(originalLabel){originalLabel.before(mainLabel,detailLabel);originalLabel.hidden=true;originalLabel.style.display='none';}
 else{metric.before(mainLabel,detailLabel);metric.hidden=true;metric.style.display='none';}
 const categoryFor=option=>{
  const key=option.value,text=option.textContent;
  if(key==='literacy')return 'Education';
  if(key.startsWith('gw_'))return 'Water monitoring';
  if(['population','households'].includes(key))return 'Population';
  if(key==='')return 'Boundaries';
  if(key==='water'||key==='water_households'||key==='water_taps')return 'Water (JJM)';
  if(/soil/i.test(key))return 'Soil';
  if(/rain/i.test(key))return 'Rainfall';
  if(/satellite|ndvi|ndwi|built|vegetation/i.test(key+' '+text))return 'Satellite';
  const group=option.closest('optgroup')?.label;
  return (group||groupFor(key,text)).replace('Population and work','Population');
 };
 const refreshControls=()=>{
  const groups=new Map();
  for(const option of metric.options){if(option.disabled)continue;const category=categoryFor(option);if(!groups.has(category))groups.set(category,[]);groups.get(category).push(option);}
  const selected=metric.selectedOptions[0],category=selected?categoryFor(selected):'Education';
  main.replaceChildren();
  const ordered=['Education','Population','Employment','Census water','Health','Other facilities','Water (JJM)','Soil','Rainfall','Satellite','Boundaries'];
  for(const name of [...ordered,...groups.keys()].filter((name,index,all)=>all.indexOf(name)===index&&groups.has(name))){const option=new Option(name,name);main.add(option);}
  main.value=category;detail.replaceChildren();
  for(const source of groups.get(category)||[]){detail.add(new Option(source.textContent.replace(/^Education · /,''),source.value));}
  detail.value=metric.value;detailLabel.hidden=detail.options.length<2;detailLabel.style.display=detail.options.length<2?'none':'flex';
 };
 main.addEventListener('change',()=>{
  const options=[...metric.options].filter(option=>!option.disabled&&categoryFor(option)===main.value);
  const preferred={Education:'literacy',Population:'population','Census water':'vd_TAP'}[main.value];
  const chosen=options.find(option=>option.value===preferred)||options.find(option=>/total workers|workers.*total/i.test(option.textContent))||options[0];
  if(chosen){metric.value=chosen.value;metric.dispatchEvent(new Event('change',{bubbles:true}));refreshControls();}
 });
 detail.addEventListener('change',()=>{metric.value=detail.value;metric.dispatchEvent(new Event('change',{bubbles:true}));});
 metric.addEventListener('change',refreshControls);
 new MutationObserver(refreshControls).observe(metric,{childList:true,subtree:true});
 refreshControls();
if(metric.id==='polygon-metric'){const filters=metric.closest('.polygon-filters'),row=document.createElement('div'),ranges=document.createElement('div');row.className='census-parameter-row';row.style.cssText=ranges.style.cssText='display:flex;align-items:center;gap:12px;flex-wrap:wrap;width:100%';row.append(mainLabel,detailLabel,label);for(const item of [...filters.children])if(item.tagName==='LABEL'&&item.querySelector('input'))ranges.append(item);filters.append(row,ranges);filters.style.cssText='display:flex;flex-direction:column;align-items:flex-start;gap:14px;padding:20px 26px;background:#f5f9ff';for(const item of [...row.children,...ranges.children])item.style.cssText+=';display:flex;align-items:center;gap:8px;margin:0;white-space:nowrap;font:600 14px system-ui';for(const input of filters.querySelectorAll('select,input')){input.style.marginTop='0';if(input.tagName==='SELECT')input.style.width=input===select?'140px':'220px';}filters.after(note);note.style.cssText='margin:0;padding:8px 26px;font-size:12px;color:#596d65';}
for(const item of [mainLabel,detailLabel,label])item.style.cssText+=';display:flex;flex-direction:column;align-items:flex-start;gap:7px;margin:0;font:600 13px system-ui;color:#334155';for(const control of [main,detail,select])control.style.cssText+=';height:46px;border:1px solid #b9cbd1;border-radius:9px;padding:10px 14px;font:600 14px system-ui;background:#fff;color:#173d37;box-shadow:0 1px 2px #173d3708';fetch('/maps/census-2001-fields/manifest.json?v=all-1').then(r=>{if(!r.ok)throw Error();return r.json();}).then(data=>{for(const [key,field] of Object.entries(data.fields||{})){if(/^(vd_|pca_)/.test(key))available.set(key,{key:key.replace(/^(vd_|pca_)/,''),title:field.label});}renderFields();refreshControls();}).catch(()=>{});yearPlayback(select,metric);
return select;

}



export function districtFeatures(data){

 if(data.features)return data.features;

 return (data.rows||[]).filter(r=>r.geometry).map(r=>({geometry:r.geometry,lgd_code:r.kind==='village'&&r.status==='linked'&&r.directory_links?.length===1?String(r.directory_links[0].code):null,properties:{name:r.name,source_code:r.code,subdistrict:r.subdistrict},values:{population:r.census?.population,households:r.census?.households,literacy:r.census?.literacy_rate}}));

}



export function directoryValues(record){

 const result={};for(const [key,value] of Object.entries(record?.directory?.values||{}))result['vd_'+key]=typeof value==='number'&&Number.isFinite(value)?value:typeof value==='string'&&value!==''?value:null;

 return result;

}

export function renderDirectory(target,record){

 for(const d of [record?.directory||record?.census_directory,record?.pca]){if(!d)continue;

 const box=document.createElement('details'),title=document.createElement('summary');title.textContent='Census 2001 · original village values and notes';box.append(title);

 for(const note of d.notes||[]){const p=document.createElement('p');p.textContent='† '+note;box.append(p);}

 for(const [key,value] of Object.entries(d.values||{})){const p=document.createElement('p');p.textContent=(d.fields?.[key]||key)+': '+(value===null?'Not reported':String(value));box.append(p);}

 const link=document.createElement('a');link.href=d.url;link.textContent='Official source records and definitions';box.append(link);target.append(box);

 }

}



export function pcaValues(record){const result={};for(const [key,value] of Object.entries(record?.pca?.values||{}))result['pca_'+key]=typeof value==='number'&&Number.isFinite(value)?value:typeof value==='string'&&value!==''?value:null;return result;}



export function rawCensusValue(record,field){return field.startsWith('vd_')?record?.directory?.values?.[field.slice(3)]??null:field.startsWith('pca_')?record?.pca?.values?.[field.slice(4)]??null:null;}

