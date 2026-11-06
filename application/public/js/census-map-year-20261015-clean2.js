const requests=new Map();

export const censusMetric=key=>['population','households','literacy'].includes(key)||key.startsWith('vd_')||key.startsWith('pca_');

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

 for(let i=0;i<unique.length;i+=200){

  const key=unique.slice(i,i+200).join(',');

  if(!requests.has(key))requests.set(key,fetch(`/api/india-map/history?codes=${key}`).then(r=>{if(!r.ok)throw Error('Census 2001 records temporarily unavailable.');return r.json();}).then(d=>d.records).catch(e=>{requests.delete(key);throw e;}));

  Object.assign(result,await requests.get(key));

 }

 if(typeof window!=='undefined'){const fields={};for(const record of Object.values(result))Object.assign(fields,record.directory?.fields||{});if(Object.keys(fields).length)window.dispatchEvent(new CustomEvent('census-directory-fields',{detail:fields}));}

 if(typeof window!=='undefined'){const fields={};for(const record of Object.values(result))Object.assign(fields,record.pca?.fields||{});if(Object.keys(fields).length)window.dispatchEvent(new CustomEvent('census-pca-fields',{detail:fields}));}

 return result;

}

const fieldTitle=(key,title)=>({P_06:'Population aged 0–6 · total',M_06:'Population aged 0–6 · male',F_06:'Population aged 0–6 · female',TOT_P:'Population · total',TOT_M:'Population · male',TOT_F:'Population · female',P_LIT:'Literate population · total',M_LIT:'Literate population · male',F_LIT:'Literate population · female'}[key]||title);

export function yearControl(metric,onChange){

 const available=new Map();
 const topicLabel=document.createElement('label');topicLabel.textContent='Topic ';
 const topic=document.createElement('select');topic.setAttribute('aria-label','Census 2001 topic');
 for(const name of ['Education','Population and work','Census water','Health','Other facilities']){const option=document.createElement('option');option.value=name;option.textContent=name;topic.append(option);}
 topicLabel.append(topic);metric.after(topicLabel);

 const groupFor=(key,title)=>/school|college|liter|education|training/i.test(title)?'Education':/water|well|tank|handpump|spring|canal|river/i.test(title)?'Census water':/population|workers|literate|household|age|scheduled/i.test(title)||key.startsWith('pca_')?'Population and work':/medical|health|hospital|dispens|maternity|nursing/i.test(title)?'Health':'Other facilities';
 const renderFields=()=>{
  const selected=metric.value;
  metric.querySelectorAll('optgroup[data-census-fields]').forEach(group=>group.remove());
  topicLabel.hidden=select.value!=='2001';
  if(select.value==='2001'){
   const groups=new Map();
   for(const [value,{key,title}] of available){
    if(/RANG|CODE|NAME|COLUMN|SERIAL|SL_NO/i.test(key)||/range code|source code|if not available/i.test(title))continue;
    if(['T_P','T_HH','TOT_P','No_HH'].includes(key))continue;
    let text=fieldTitle(key,title).replace(/^Number of /i,'').replace(/\s*\([^)]*\)/g,'').replace(/Collage/g,'College').trim();
    if(text===key||/^[A-Z0-9_]+$/.test(text))continue;
    const name=groupFor(value,text);
    if(!groups.has(name)){const group=document.createElement('optgroup');group.label=name;group.dataset.censusFields='2001';groups.set(name,group);}
    const option=document.createElement('option');option.value=value;option.textContent=text;groups.get(name).append(option);
   }
   for(const name of ['Population and work','Education','Census water','Health','Other facilities'])if(name===topic.value&&groups.has(name))metric.append(groups.get(name));
  }
  if([...metric.options].some(option=>option.value===selected))metric.value=selected;
  else if(selected.startsWith('vd_')||selected.startsWith('pca_'))metric.value='population';
 };
 topic.addEventListener('change',()=>{renderFields();metric.dispatchEvent(new Event('change',{bubbles:true}));});
 for(const [eventName,prefix] of [['census-directory-fields','vd_'],['census-pca-fields','pca_']])window.addEventListener(eventName,event=>{
  for(const [key,title] of Object.entries(event.detail))available.set(prefix+key,{key,title});
  renderFields();
 });

 const label=document.createElement('label');label.textContent='Census year ';label.htmlFor='census-map-year';

 const select=document.createElement('select');select.id='census-map-year';select.setAttribute('aria-label','Census year');

 for(const year of ['2011','2001']){const option=document.createElement('option');option.value=year;option.textContent=year;select.append(option);}

 select.value=new URL(location.href).searchParams.get('censusYear')==='2001'?'2001':'2011';label.append(select);metric.after(label);

 const note=document.createElement('p');note.id='census-year-note';label.after(note);

 const sync=()=>{select.disabled=!censusMetric(metric.value);note.hidden=select.disabled;note.textContent=`Census ${select.value} · Existing source boundaries. Historical boundary comparability is unverified; Original values and caution notes are displayed; missing cells remain missing.`;};

 select.addEventListener('change',()=>{if(select.value==='2011'&&(metric.value.startsWith('vd_')||metric.value.startsWith('pca_')))metric.value='population';renderFields();sync();const url=new URL(location.href);url.searchParams.set('censusYear',select.value);history.replaceState(null,'',url);onChange();});metric.addEventListener('change',()=>{if((metric.value.startsWith('vd_')||metric.value.startsWith('pca_'))&&select.value!=='2001'){select.value='2001';onChange();}sync();});renderFields();sync();return select;

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

