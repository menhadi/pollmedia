const node=(tag,text,className)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(className)el.className=className;return el;};
const units={Quintal:['₹ per quintal','₹ प्रति क्विंटल'],Kilogram:['₹ per kg','₹ प्रति किलो'],Nos:['₹ per item','₹ प्रति नग'],Bundle:['₹ per bundle','₹ प्रति गट्ठर']};
export async function loadMarkets(panel,hub,t){
    const status=panel.querySelector('[data-market-status]'),target=panel.querySelector('[data-market-records]');
    const limitedMessage=t('Too many requests. Please wait about a minute and retry.','बहुत अधिक अनुरोध हुए हैं। लगभग एक मिनट बाद फिर कोशिश करें।');
    const hi=hub.dataset.language==='hi',locale=hi?'hi-IN':'en-IN',numbers=new Intl.NumberFormat(locale,{maximumFractionDigits:2});
    const date=value=>new Intl.DateTimeFormat(locale,{dateStyle:'medium',timeZone:'Asia/Kolkata'}).format(new Date(value+'T00:00:00+05:30'));
    const month=value=>new Intl.DateTimeFormat(locale,{month:'short',year:'numeric',timeZone:'Asia/Kolkata'}).format(new Date(value+'-01T00:00:00+05:30'));
    const price=value=>value===null||value===undefined?t('Unavailable','उपलब्ध नहीं'):numbers.format(value);
    const unit=value=>units[value]?.[hi?1:0]||t('Unit not verified','इकाई की पुष्टि नहीं हुई');
    const named=value=>hi?({'Pilibhit APMC':'पीलीभीत मंडी','Bishalpur APMC':'बीसलपुर मंडी','Puranpur APMC':'पूरनपुर मंडी'}[value]||value):value;
    const controls=node('div',undefined,'market-controls'),label=node('label',t('Commodity','जिंस / फसल')),select=node('select');label.append(select);
    const more=node('button',t('Show more items','और जिंस देखें'),'secondary');more.type='button';more.hidden=true;
    const list=node('div'),history=node('section',undefined,'market-history');history.hidden=true;history.setAttribute('aria-live','polite');
    controls.append(label,more);target.replaceChildren(controls,list,history);
    let records=[],catalog=[],next=null,commodity='',version=0,historyVersion=0;
    const labels=[t('Market','बाज़ार'),t('Commodity / variety / grade','जिंस / किस्म / ग्रेड'),t('Price unit','भाव की इकाई'),t('Minimum','न्यूनतम'),t('Modal','प्रचलित भाव'),t('Maximum','अधिकतम'),t('Reported date','रिपोर्ट की तारीख'),t('History','इतिहास')];
    function render(){
        const table=node('table',undefined,'market-table'),head=node('thead'),tr=node('tr');labels.forEach(text=>{const th=node('th',text);th.scope='col';tr.append(th);});head.append(tr);table.append(head);
        const body=node('tbody');
        for(const record of records){
            const row=node('tr');
            const texts=[named(record.market),[hi?(record.commodity_hi||record.commodity):record.commodity,record.variety,record.grade].filter(Boolean).join(' · '),unit(record.unit),price(record.min),price(record.modal),price(record.max),date(record.date)];
            texts.forEach((text,i)=>{const cell=node('td',text);cell.dataset.label=labels[i];row.append(cell);});
            const cell=node('td');cell.dataset.label=labels[7];const button=node('button',t('1-year trend','1 वर्ष का रुझान'),'secondary');button.type='button';button.addEventListener('click',()=>showHistory(record));cell.append(button);row.append(cell);body.append(row);
        }
        table.append(body);list.replaceChildren(table);
    }
    async function fetchPage(append=false){
        const current=++version;select.disabled=true;more.disabled=true;
        if(!append){records=[];list.replaceChildren();history.hidden=true;++historyVersion;more.hidden=true;}
        status.hidden=false;status.textContent=t('Checking reported prices…','रिपोर्ट किए गए भाव जाँच रहे हैं…');
        try{
            const url=new URL(hub.dataset.marketUrl);url.search=new URLSearchParams({state:hub.dataset.state,district:hub.dataset.district,commodity,offset:append?next||0:0});
            const response=await fetch(url,{headers:{Accept:'application/json'}});if(!response.ok)throw new Error('Prices HTTP '+response.status);const data=await response.json();if(current!==version||!target.isConnected)return;
            catalog=data.catalog||catalog;
            const selection=select.value;select.replaceChildren(new Option(t('All reported items','सभी रिपोर्ट की गई जिंसें'),''));
            const names=new Set();for(const item of catalog){if(names.has(item.name))continue;names.add(item.name);select.append(new Option(hi&&item.hi?`${item.hi} · ${item.name}`:item.name,item.name));}
            if(commodity&&!names.has(commodity))select.append(new Option(commodity,commodity));select.value=commodity||selection;
            const map=new Map(records.map(row=>[JSON.stringify([row.market,row.commodity,row.variety,row.grade]),row]));
            for(const row of data.records||[]){const key=JSON.stringify([row.market,row.commodity,row.variety,row.grade]);if(!map.has(key)||map.get(key).date<row.date||(map.get(key).date===row.date&&row.source==='daily'&&map.get(key).source!=='daily'))map.set(key,row);}
            records=[...map.values()];records.sort((a,b)=>b.date.localeCompare(a.date));next=data.next_offset??null;more.hidden=next===null;
            if(records.length){
                status.textContent=t('Latest loaded reports: ','लोड की गई नवीनतम रिपोर्ट: ')+date(records[0].date)+t(` · ${records.length} market / variety entries. Older prices retain their reporting date.`,` · बाज़ार / किस्म की ${numbers.format(records.length)} प्रविष्टियाँ। पुराने भाव के साथ उनकी रिपोर्ट की तारीख दी गई है।`);
                if(next!==null)status.append(document.createTextNode(t(' More items are available: choose a commodity or show more.',' और जिंसें उपलब्ध हैं: जिंस चुनें या और देखें।')));
                if(data.partial_sources)status.append(document.createTextNode(t(' Some reports are temporarily unavailable.',' कुछ रिपोर्ट फिलहाल उपलब्ध नहीं हैं।')));
                if(data.cached_fallback)status.append(document.createTextNode(t(' Showing saved verified reports while the feed is unavailable.',' डेटा स्रोत उपलब्ध न होने पर पिछली सत्यापित रिपोर्ट दिख रही हैं।')));
                const sourceStatus=panel.querySelector('[data-market-source-status]');if(sourceStatus){sourceStatus.textContent=status.textContent;status.textContent='';status.hidden=true;}
                render();
            }else{list.replaceChildren();status.textContent=data.reason==='no_matching_records'?t('No matching reports for this selection. Choose another commodity.','इस चयन की रिपोर्ट नहीं मिली। दूसरी जिंस चुनें।'):t('The government feed is temporarily unavailable. Please retry later.','सरकारी डेटा फिलहाल उपलब्ध नहीं है। कुछ देर बाद फिर कोशिश करें।');}
        }catch(error){if(current===version){status.textContent=error?.message==='Prices HTTP 429'?limitedMessage:t('Prices could not load. Please retry.','भाव लोड नहीं हुए। फिर कोशिश करें।');const retry=node('button',t('Retry prices','भाव फिर लोड करें'),'secondary');retry.type='button';retry.addEventListener('click',()=>fetchPage());status.append(retry);delete panel.dataset.loaded;}}
        finally{if(current===version){select.disabled=false;more.disabled=false;}}
    }
    async function showHistory(record){
        const current=++historyVersion;history.hidden=false;history.replaceChildren(node('h3',t('One-year price trend','एक वर्ष के भाव का रुझान')),node('p',t('Loading official history…','आधिकारिक इतिहास लोड हो रहा है…')));history.scrollIntoView({block:'start'});
        try{
            const url=new URL(hub.dataset.historyUrl);url.search=new URLSearchParams({state:hub.dataset.state,district:hub.dataset.district,commodity:record.commodity,variety:record.variety,grade:record.grade});
            const response=await fetch(url,{headers:{Accept:'application/json'}});if(!response.ok)throw new Error('History HTTP '+response.status);const data=await response.json();if(current!==historyVersion||!history.isConnected)return;
            history.replaceChildren(node('h3',`${hi?(record.commodity_hi||record.commodity):record.commodity} · ${record.variety} · ${record.grade}`));
            history.append(node('p',t('District trend across reporting markets · monthly mean of reported modal prices.','जिले के रिपोर्ट देने वाले बाज़ारों का रुझान · रिपोर्ट किए गए प्रचलित भाव का मासिक औसत।')));
            if(data.reason){history.append(node('p',data.reason==='history_limit_reached'?t('This history is too large to verify completely in one request. No partial trend is presented.','इतना बड़ा इतिहास एक बार में पूरा सत्यापित नहीं हुआ। अधूरा रुझान नहीं दिखाया गया है।'):t('Comparable official history is unavailable for this selection.','इस चयन का तुलनीय आधिकारिक इतिहास उपलब्ध नहीं है।')));return;}
            history.append(node('strong',unit(data.unit)));
            drawTrend(history,data.months,month,price,t);
            const comparison=data.comparison,grid=node('div',undefined,'market-comparison');
            for(const [period,summary] of [[comparison.month,comparison.current],[comparison.prior_month,comparison.previous]]){
                const card=node('article');card.append(node('h4',month(period)+t(` · days 1–${comparison.through_day}`,` · दिन 1–${comparison.through_day}`)),node('strong',price(summary.value)),node('p',t(`${summary.observations} observations on ${summary.days} reporting days`,`${numbers.format(summary.observations)} प्रविष्टियाँ, ${numbers.format(summary.days)} रिपोर्ट वाले दिन`)));grid.append(card);
            }
            history.append(node('h4',t('Same month last year','पिछले वर्ष का यही महीना')),grid,node('p',comparison.change_percent===null?t('Year-on-year change unavailable: one comparison period has no comparable reports.','सालाना बदलाव उपलब्ध नहीं: तुलना की एक अवधि की तुलनीय रिपोर्ट नहीं मिली।'):t('Change in reported mean: ','रिपोर्ट के औसत में बदलाव: ')+numbers.format(comparison.change_percent)+'%'));
            const details=node('section',undefined,'market-monthly'),summary=node('h4',t('Monthly data and reporting coverage','मासिक आँकड़े और रिपोर्ट की उपलब्धता'));details.append(summary);
            const table=node('table'),thead=node('thead'),heading=node('tr');[t('Month','महीना'),t('Mean modal price','औसत प्रचलित भाव'),t('Reporting coverage','रिपोर्ट की उपलब्धता')].forEach(text=>{const cell=node('th',text);cell.scope='col';heading.append(cell);});thead.append(heading);table.append(thead);
            const tbody=node('tbody'),points=[{month:comparison.prior_month,...comparison.previous_full_month},...data.months];for(const point of points){const row=node('tr');[month(point.month),price(point.value),t(`${point.observations} observations / ${point.days} days`,`${numbers.format(point.observations)} प्रविष्टियाँ / ${numbers.format(point.days)} दिन`)].forEach(text=>row.append(node('td',text)));tbody.append(row);}table.append(tbody);details.append(table);history.append(details,node('p',t('Missing months remain gaps. The table also includes the full comparison month last year. The comparison cards use the same calendar days in both years; reporting coverage may differ.','गायब महीने खाली रहते हैं। तालिका में पिछले वर्ष का पूरा तुलना महीना भी है। तुलना के कार्ड दोनों वर्षों के समान कैलेंडर दिनों के हैं; रिपोर्ट की उपलब्धता अलग हो सकती है।')));
        }catch(error){console.warn('Mandi history could not load: '+String(error?.message||'network failure'));if(current===historyVersion)history.replaceChildren(node('p',error?.message==='History HTTP 429'?limitedMessage:t('History could not load. Tap the trend button to retry.','इतिहास लोड नहीं हुआ। रुझान का बटन दबाकर फिर कोशिश करें।')));}
    }
    select.addEventListener('change',()=>{commodity=select.value;fetchPage();});more.addEventListener('click',()=>fetchPage(true));
    await fetchPage();
}
function drawTrend(target,points,month,price,t){
    const readout=node('p',t('Hover, tap or focus a point to see its month and value.','महीना और भाव देखने के लिए बिंदु पर माउस रखें, टैप करें या फोकस करें।'),'market-chart-value');readout.setAttribute('aria-live','polite');
    const namespace='http://www.w3.org/2000/svg',svg=document.createElementNS(namespace,'svg');svg.setAttribute('viewBox','0 0 640 245');svg.setAttribute('role','img');svg.setAttribute('aria-label',t('Monthly reported price trend; missing months are gaps','मासिक रिपोर्ट किए गए भाव का रुझान; गायब महीने खाली हैं'));
    const values=points.filter(point=>point.value!==null).map(point=>point.value),max=Math.max(...values,1),min=Math.min(...values,0),range=max-min||1;
    const add=(tag,attrs,text)=>{const el=document.createElementNS(namespace,tag);Object.entries(attrs).forEach(([key,value])=>el.setAttribute(key,value));if(text!==undefined)el.textContent=text;svg.append(el);return el;};
    for(let i=0;i<4;i++){const y=25+i*52;add('line',{x1:105,x2:620,y1:y,y2:y,stroke:'#d6e4df'});add('text',{x:96,y:y+4,'text-anchor':'end','font-size':12,fill:'#355d52'},price(max-i*(range/3)));}
    const inspect=event=>{const rect=svg.getBoundingClientRect();if(!rect.width||!points.length)return;const index=Math.max(0,Math.min(points.length-1,Math.round(((event.clientX-rect.left)*640/rect.width-110)/44)));const point=points[index];readout.textContent=month(point.month)+': '+price(point.value);};svg.addEventListener('pointermove',inspect);svg.addEventListener('click',inspect);
    let path='';points.forEach((point,i)=>{const x=110+i*44;if(i===0||i===5||i===11)add('text',{x,y:222,'text-anchor':i===0?'start':i===11?'end':'middle','font-size':11,fill:'#355d52'},month(point.month));if(point.value===null){path+=' ';return;}const y=25+(max-point.value)/range*156;path+=(i===0||points[i-1].value===null?' M':' L')+x+','+y;const value=month(point.month)+': '+price(point.value);const circle=add('circle',{cx:x,cy:y,r:7,fill:'var(--chart-series-1,#155748)',tabindex:0,role:'button','aria-label':value,'data-market-point':point.month});for(const event of ['pointerenter','focus','click'])circle.addEventListener(event,()=>{readout.textContent=value;});const title=document.createElementNS(namespace,'title');title.textContent=month(point.month)+': '+price(point.value);circle.append(title);});add('path',{d:path,fill:'none',stroke:'var(--chart-series-1,#155748)','stroke-width':3,'pointer-events':'none'});target.append(readout,svg);
}
