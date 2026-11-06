const make=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
export function latestReported(station,parameter){
    const grouped=new Map();
    for(const row of station.observations||[]){const value=row.values[parameter];if(typeof value==='number'&&Number.isFinite(value)){const group=grouped.get(row.observed_at)||[];group.push({row,value});grouped.set(row.observed_at,group);}}
    for(const date of [...grouped.keys()].sort().reverse()){const values=grouped.get(date);if(new Set(values.map(v=>v.value)).size===1)return values[0];return {conflict:true,date};}
    return null;
}
export function mountWaterEvidence(root=document){
    for(const box of root.querySelectorAll('[data-water-evidence]:not([data-water-mounted])')){
        box.dataset.waterMounted='1';
        const t=(en,hi)=>box.dataset.language==='hi'?hi:en;
        if(box.dataset.identityPending==='1'){for(const s of box.querySelectorAll('[data-water-status]'))s.textContent=t('A unique current place identity is needed before monitoring records can be linked.','निगरानी रिकॉर्ड जोड़ने से पहले क्षेत्र की एकमात्र वर्तमान पहचान चाहिए।');continue;}
        fetch(box.dataset.url,{headers:{Accept:'application/json'}}).then(async response=>{if(!response.ok)throw Error();return response.json();}).then(data=>{
            if(!box.isConnected)return;
            for(const section of box.querySelectorAll('[data-water-kind]')){
                const kind=section.dataset.waterKind,status=section.querySelector('[data-water-status]'),records=section.querySelector('[data-water-records]'),controls=section.querySelector('[data-water-controls]');
                const stations=(data.stations||[]).filter(s=>s.kind===kind);
                if(kind==='quality'){status.textContent=t('No verified drinking-water test results are imported for this place.','इस क्षेत्र के पुष्ट पेयजल जाँच रिकॉर्ड अभी आयात नहीं हुए हैं।');continue;}
                if(!stations.length){status.textContent=t(data.scope==='village'?'No supported monitoring-location link for this village. District records have not been copied into the village.':'No accepted monitoring records are available for this selected area.','चुने क्षेत्र का पुष्ट निगरानी रिकॉर्ड उपलब्ध नहीं है। जिले के माप गाँव में नहीं लगाए गए हैं।');continue;}
                const dates=stations.flatMap(s=>s.observations.map(o=>o.observed_at)).sort();
                status.textContent=t(`${stations.length} historical monitoring series · ${dates[0].slice(0,10)} to ${dates.at(-1).slice(0,10)}. Monitoring locations, not village-wide averages.`,`${stations.length} ऐतिहासिक निगरानी श्रृंखलाएँ · ${dates[0].slice(0,10)} से ${dates.at(-1).slice(0,10)}। ये निगरानी स्थान के माप हैं, गाँव का औसत नहीं।`);
                const params=[...new Set(stations.flatMap(s=>s.observations.flatMap(o=>Object.keys(o.values))))];
                const select=make('select');select.setAttribute('aria-label',t('Groundwater measurement','भूजल माप'));for(const key of params)select.append(new Option(key,key));
                const label=make('label',t('Choose measurement ','माप चुनें '));label.append(select);controls.append(label);
                const render=()=>{
                    records.replaceChildren();const wrapper=make('div');wrapper.className='water-table';const table=make('table'),head=make('thead'),tr=make('tr');
                    for(const text of [t('Monitoring location','निगरानी स्थान'),t('Latest reported value','अंतिम रिपोर्ट किया माप'),t('Observation date','अवलोकन तारीख'),t('Explore','देखें')])tr.append(make('th',text));head.append(tr);table.append(head);const body=make('tbody');
                    for(const station of stations){const latest=latestReported(station,select.value),row=make('tr'),name=make('td',station.station);name.append(make('small',`${t('Source locality','स्रोत स्थान')}: ${station.village}`));row.append(name,make('td',latest?.conflict?t('Conflicting source rows','स्रोत रिकॉर्ड में अंतर'):latest?String(latest.value):t('Not reported','रिपोर्ट नहीं हुआ')),make('td',(latest?.row?.observed_at||latest?.date||'').slice(0,10)||'—'));
                        const links=make('td'),a=make('a',t('History and source','इतिहास और स्रोत'));a.href=station.explorer_url;links.append(a);if(station.village_url){const v=make('a',t('Village profile','गाँव प्रोफाइल'));v.href=station.village_url;links.append(make('br'),v);}row.append(links);body.append(row);
                    }table.append(body);wrapper.append(table);records.append(wrapper,make('p',select.value));
                };select.addEventListener('change',render);render();
                const sourceBox=section.querySelector('[data-water-sources]');if(sourceBox)for(const source of (data.sources||[]).filter(s=>s.kind===kind)){const p=make('p'),a=make('a',source.id.replaceAll('_',' '));a.href=source.url;a.target='_blank';a.rel='noopener';p.append(a);sourceBox.append(p);}
            }
        }).catch(()=>{if(box.isConnected)for(const s of box.querySelectorAll('[data-water-status]'))s.textContent=t('Monitoring evidence is temporarily unavailable. No values are inferred.','निगरानी रिकॉर्ड अभी उपलब्ध नहीं हैं। कोई अनुमान नहीं लगाया गया है।');});
    }
}
if(typeof document!=='undefined'){mountWaterEvidence();new MutationObserver(records=>{if(records.some(r=>r.addedNodes.length))mountWaterEvidence();}).observe(document.documentElement,{childList:true,subtree:true});}
