(async()=>{
 const panel=document.querySelector('[data-rainfall-panel]');if(!panel)return;
 try{
  const response=await fetch('/maps/pilibhit-rainfall.json');if(!response.ok)throw Error('Rainfall unavailable');
  const data=await response.json();let source=null;
  if(panel.dataset.district!=='1'){
   const matches=new Set((panel.dataset.lgd||'').split(',').map(code=>data.lgd_to_source[code]).filter(Boolean));
   const census=data.census_to_source[panel.dataset.census];if(census)matches.add(census);
   if(matches.size!==1)return;source=[...matches][0];
  }
  const select=document.getElementById('rainfall-year'),years=Object.keys(data.years).sort().reverse();
  for(const year of years){const option=document.createElement('option');option.value=year;option.textContent=year;select.append(option);}
  const format=value=>value==null?'Unavailable':Number(value).toLocaleString('en-IN',{maximumFractionDigits:1});
  function render(){
   const year=select.value,edition=data.years[year],values=source?data.villages[source][year]:edition.district;
   const prior=source?data.villages[source][String(Number(year)-1)]:data.years[String(Number(year)-1)]?.district;
   document.getElementById('rainfall-total').textContent=`${year} annual rainfall: ${format(values.annual_mm)}${values.annual_mm==null?'':' mm'} · Area estimate`;
   const body=document.getElementById('rainfall-months');body.replaceChildren();
   values.monthly_mm.forEach((value,index)=>{const tr=document.createElement('tr');for(const text of [new Date(2000,index,1).toLocaleString('en',{month:'long'}),format(value),format(prior?.monthly_mm[index])]){const td=document.createElement('td');td.textContent=text;tr.append(td);}body.append(tr);});
   document.getElementById('rainfall-source').textContent=`Observation year ${year}; downloaded ${edition.source.retrieved_at.slice(0,10)}. Grid spacing 0.25°. Source SHA-256: ${edition.source.sha256}. ${data.method}`;
  }
  select.addEventListener('change',render);render();panel.hidden=false;
  const disclaimer=document.getElementById('rainfall-disclaimer');if(disclaimer)document.querySelector('main')?.append(disclaimer);
 }catch(error){console.warn(error.message);}
})();

import('/js/soil-panel.js?v=20261014-national-1').catch(error=>console.warn('Soil panel unavailable:',error.message));
import('/js/satellite-comparison-20261018-highlight.js?v=20261024-all-districts').then(module=>module.mountSatelliteComparison()).catch(error=>console.warn('Satellite panel unavailable:',error.message));
