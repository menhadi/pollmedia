(() => {
    const $ = id => document.getElementById(id);
    const filters = ['record-year', 'record-state', 'record-pc', 'record-ac', 'record-station', 'record-edition'];
    let page = 1, criteria = null, optionsRequest, recordsRequest, generation = 0, officialStatistics = [], searchTimer, lastPage=1, retryMode='filters';
    const fmt = value => Number(value).toLocaleString('en-IN');
    function scope() {
        const input = {};
        const fields = {'record-year':'period', 'record-state':'state_code', 'record-station':'station_key', 'record-edition':'edition_key'};
        for (const [id, field] of Object.entries(fields)) if ($(id).value) input[field] = $(id).value;
        for (const [id, field] of [['record-pc','pc_code'], ['record-ac','ac_code']]) {
            if ($(id).value) { const [state, code] = $(id).value.split('|'); input.state_code = state; input[field] = code; }
        }
        return input;
    }
    function options(id, rows, placeholder) {
        const value = $(id).value;
        $(id).replaceChildren(new Option(placeholder, ''));
        rows.forEach(row => $(id).append(new Option(row.label, row.value)));
        $(id).value = rows.some(row => row.value === value) ? value : '';
    }
    function clearResults() {
        clearTimeout(searchTimer); recordsRequest?.abort(); generation++; $('filtered-summary').hidden=true;
        $('record-rows').replaceChildren(); $('record-page-info').textContent = '';$('record-page-info-top').textContent='';
        setNavigation(true);
    }
    function setNavigation(loading=false) {
        for(const suffix of ['', '-top']) {
            $('record-first'+suffix).disabled=loading || page<=1;
            $('record-prev'+suffix).disabled=loading || page<=1;
            $('record-next'+suffix).disabled=loading || page>=lastPage;
            $('record-last'+suffix).disabled=loading || page>=lastPage;
        }
    }
    function retry(message,mode) {retryMode=mode;$('record-retry').hidden=false;$('record-retry').textContent='Try again';$('record-status').textContent=message;}
    async function refreshOptions() {
        optionsRequest?.abort();
        const active = new AbortController(); optionsRequest = active; let timedOut=false; const timeout=setTimeout(()=>{timedOut=true;active.abort();},20000);
        $('record-status').textContent = 'Loading choices...'; $('record-retry').hidden=true;
        officialStatistics=[]; $('statistics-rows').replaceChildren(); $('statistics-download').disabled=true; $('statistics-status').textContent='Loading official totals...';
        try {
            const response = await fetch('/api/sir/editions?' + new URLSearchParams(scope()), {signal:active.signal, headers:{Accept:'application/json'}});
            if (!response.ok) throw Error('The choices could not be loaded. Please try again.');
            const data = await response.json();
            if(active.signal.aborted){if(timedOut && active===optionsRequest) retry('Loading choices took too long. Please try again.','filters');return false;}
            renderStatistics(data.statistics || []);
            options('record-year', data.periods, 'All available years');
            options('record-state', data.states, 'All available states');
            options('record-pc', data.pcs, data.pcs.length ? 'All available PCs' : 'Choose an assembly constituency');
            $('record-pc').disabled = !data.pcs.length;
            options('record-ac', data.acs, 'All available ACs');
            options('record-edition', data.editions.map(e => ({value:e.edition_key, label:e.edition + ' - ' + e.document_date + ' - AC ' + e.ac_code})), 'All available editions');
            options('record-station', data.stations, $('record-ac').value ? 'All polling stations in this AC' : 'Choose an AC to list stations');
            $('record-station').disabled = !$('record-ac').value;
            $('scope-note').textContent='Choose an area to see all its names, or narrow your search.';
            if(data.editions.length===1 && data.editions[0].printed_electors){const e=data.editions[0];$('scope-note').textContent+=' Available in this part: '+fmt(e.indexed_records)+' of '+fmt(e.printed_electors)+' entries. '+fmt(e.uncertain_records || 0)+' names may need checking in the original list.';}
            $('record-status').textContent = data.editions.length ? 'Choose any geographic filter, or enter a name, to show available records.' : 'No voter lists are available for this selection yet.';
            return true;
        } catch (error) {
            if (active !== optionsRequest) return false;
            if (timedOut) retry('Loading choices took too long. Please try again.','filters'); else if(error.name!=='AbortError') retry('The choices could not be loaded. Please try again.','filters');
            return false;
        } finally {clearTimeout(timeout);
        }
    }
    function renderSummary(summary) {
        if(!summary){$('filtered-summary').hidden=true;return;}
        $('filtered-summary').hidden=false;
        $('gender-summary').replaceChildren();
        for(const [field,label] of [['total','Total voters'],['male','Male'],['female','Female'],['third_gender','Third gender'],['unknown_gender','Gender not available'],['uncertain','Names to check']]) {
            const card=document.createElement('div');card.className='voter-card';
            const title=document.createElement('span');title.textContent=label;
            const count=document.createElement('b');count.textContent=fmt(summary[field] || 0);
            card.append(title,count);$('gender-summary').append(card);
        }
        $('age-summary').replaceChildren();
        const groups=summary.age_groups || [],maximum=Math.max(1,...groups.map(group=>Number(group.count)||0));
        for(const group of groups) {
            const row=document.createElement('div'),label=document.createElement('div');label.className='age-bar-label';
            const title=document.createElement('span');title.textContent=group.label;
            const count=document.createElement('b');count.textContent=fmt(group.count);
            label.append(title,count);
            const track=document.createElement('div');track.className='age-track';
            const fill=document.createElement('span');fill.className='age-fill';fill.style.width=Math.max(0,Math.min(100,(Number(group.count)||0)/maximum*100))+'%';
            track.append(fill);row.append(label,track);$('age-summary').append(row);
        }
    }
    $('methodology-link').onclick=()=>{$('sir-methodology').open=true;};
    function renderStatistics(rows) {
        officialStatistics=rows;
        $('statistics-rows').replaceChildren();
        $('statistics-download').disabled=!rows.length;
        $('statistics-status').textContent=rows.length ? 'Official PDF totals for '+rows.length+' available part summaries. More parts will be added.' : 'Printed totals are not available for this selection.';
        rows.forEach(stats=>{
            const row=document.createElement('tr');
            cell(row,(stats.year || 'Year not confirmed')+' / '+stats.edition+' / published '+stats.document_date);
            cell(row,'AC '+stats.ac_code+' / Part '+stats.part);
            for(const field of ['male','female','third_gender','total']) cell(row,stats[field] == null ? 'Unknown' : fmt(stats[field]));
            const source=cell(row,''); const link=document.createElement('a');link.href=stats.pdf_url;link.target='_blank';link.rel='noopener noreferrer';link.textContent='PDF page '+stats.pdf_page;source.append(link);
            $('statistics-rows').append(row);
        });
    }
    $('statistics-download').onclick=()=>{
        const blob=new Blob([JSON.stringify({coverage:'Imported parts only; editions must not be added together',statistics:officialStatistics},null,2)],{type:'application/json;charset=utf-8'});
        const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download='sir-official-totals.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    };
    function cell(row, value) { const element=document.createElement('td'); element.textContent=value; row.append(element); return element; }
    function displayName(value) {return /\[Unreadable .* in OCR\]/.test(value) ? 'Name not clear' : value;}
    function small(parent,value) {const item=document.createElement('small');item.textContent=value;parent.append(item);return item;}
    async function showRecords() {
        recordsRequest?.abort(); const active=new AbortController(); recordsRequest=active;
        let timedOut=false;const timeout=setTimeout(()=>{timedOut=true;active.abort();},20000);
        $('record-retry').hidden=true;
        const ticket=++generation;
        setNavigation(true);
        $('record-rows').replaceChildren(); $('record-status').textContent='Loading records...';
        try {
            const response=await fetch('/api/sir/records/search', {method:'POST', signal:active.signal,
                headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                body:JSON.stringify({...criteria, page, per_page:Number($('record-page-size').value)})});
            if(!response.ok) throw Error(response.status===419 ? 'Your session expired. Refresh the page and search again.' : response.status===429 ? 'Please pause briefly, then try your search again.' : 'The records could not be loaded. Please try again.');
            const data=await response.json(); if (ticket!==generation) return false;
            if(active.signal.aborted){if(timedOut) retry('The search took too long. Please try again.','records');return false;}
            if(!Array.isArray(data.data)) throw Error('The records could not be loaded. Please try again.');
            page=data.current_page || 1;lastPage=data.last_page || 1;
            renderSummary(data.summary);
            data.data.forEach(record => {
                const row=document.createElement('tr');
                const sequence=cell(row,record.serial); if(!record.serial_verified) small(sequence,'Check number in original list');
                const name=cell(row,displayName(record.name)); if(record.extraction_status==='ocr_uncertain') {const badge=small(name,'Check original');badge.className='ocr-badge';badge.title='Check the name in the linked official list';}
                const relative=cell(row,displayName(record.relative_name)); small(relative,record.relationship==='Other' ? 'Other / unclear relationship' : record.relationship);
                cell(row,record.house_number || 'Unreadable');
                const age=cell(row,record.age ?? 'Unreadable'); if(record.age==null && record.age_text) small(age,record.age_text); if(record.age!=null && record.age<18 && (record.field_notes || record.extraction_status!=='reviewed')) small(age,'Check age in PDF');
                cell(row,record.gender || 'Unreadable');
                const section=cell(row,record.section_number ? 'Section '+record.section_number+(record.section_name ? ' - '+record.section_name : '') : 'Section not published'); small(section,record.ward_number ? 'Ward '+record.ward_number : 'Ward not published');
                cell(row,record.elector_id || 'Unreadable');
                const edition=cell(row,record.year ? record.year+' (revision)' : record.document_date.slice(0,4)+' (document year)'); small(edition,record.edition);
                const station=cell(row,'AC '+record.ac_code+' - '+record.ac_name); small(station,'Part '+record.part+' - '+record.station);
                const link=document.createElement('a'); link.href=record.pdf_url;
                link.textContent='PDF page '+record.pdf_page; link.target='_blank'; link.rel='noopener noreferrer'; const sourceCell=cell(row,''); sourceCell.append(link);
                const official=document.createElement('a'); official.href=record.source_landing_url||record.source_url; official.textContent='Official publication'; official.target='_blank'; official.rel='noopener noreferrer'; sourceCell.append(document.createElement('br'),official);
                if(record.extraction_status!=='reviewed') small(sourceCell,'Check original list');

                $('record-rows').append(row);
            });
            $('record-status').textContent=data.total ? fmt(data.total)+' records match your search.' : 'No matches found here. You can still check the official PDF.';
            const info=data.total ? fmt(data.from)+'–'+fmt(data.to)+' of '+fmt(data.total)+' · Page '+page+' of '+lastPage : 'No matching records';
            $('record-page-info').textContent=info;$('record-page-info-top').textContent=info;
            setNavigation();return true;
        } catch(error) {
            if(ticket!==generation) return false;
            if(timedOut) retry('The search took too long. Please try again.','records');
            else if(error.name!=='AbortError') {const messages=['Your session expired. Refresh the page and search again.','Please pause briefly, then try your search again.'];retry(messages.includes(error.message)?error.message:'The records could not be loaded. Please try again.','records');}
            return false;
        } finally {clearTimeout(timeout);}

    }
    function submit() {
        clearTimeout(searchTimer);
        criteria={...scope(), name:$('record-name').value.trim(), relative_name:$('record-relative').value.trim()};
        page=1;
        if (!Object.values(criteria).some(Boolean)) { $('record-status').textContent='Choose a year, state or constituency, or enter a name. No need to fill every field.'; return; }
        showRecords();
    }
    filters.forEach(id => $(id).addEventListener('change', async () => {
        clearResults();
        const downstream = {
            'record-year':['record-state','record-pc','record-ac','record-station','record-edition'],
            'record-state':['record-pc','record-ac','record-station','record-edition'],
            'record-pc':['record-ac','record-station','record-edition'],
            'record-ac':['record-station','record-edition'],
            'record-edition':['record-station'], 'record-station':[]
        };
        downstream[id].forEach(child => $(child).value='');
        if (['record-pc','record-ac'].includes(id) && $(id).value) $('record-state').value=$(id).value.split('|')[0];
        if (await refreshOptions()) submit();
    }));
    $('record-form').onsubmit=event => {event.preventDefault(); submit();};
    function scheduleSearch(event) {
        clearResults();
        if(event?.isComposing) return;
        $('record-status').textContent='Searching names...';
        searchTimer=setTimeout(submit,500);
    }
    for (const id of ['record-name','record-relative']) {$(id).addEventListener('input', scheduleSearch); $(id).addEventListener('compositionend', scheduleSearch);}

    $('record-page-size').onchange=()=>{clearResults();page=1;if(criteria) submit();};
    async function goToPage(target) {page=Math.max(1,Math.min(target,lastPage));if(await showRecords()) $('records-top').scrollIntoView({behavior:'smooth',block:'start'});}
    for(const suffix of ['', '-top']) {
        $('record-first'+suffix).onclick=()=>goToPage(1);$('record-prev'+suffix).onclick=()=>goToPage(page-1);
        $('record-next'+suffix).onclick=()=>goToPage(page+1);$('record-last'+suffix).onclick=()=>goToPage(lastPage);
    }
    $('record-reset').onclick=() => {clearResults(); filters.forEach(id => $(id).value=''); $('record-name').value=''; $('record-relative').value=''; criteria=null; page=1; lastPage=1; startPilot();};
    $('record-retry').onclick=async()=>{if(retryMode==='records') submit();else if(await refreshOptions()) submit();};
    async function startPilot() {
        if (!await refreshOptions()) return;
        const acChoices=Array.from($('record-ac').options).filter(option=>option.value);
        if(acChoices.length===1){
            $('record-ac').value=acChoices[0].value;
            $('record-state').value=acChoices[0].value.split('|')[0];
            for(const id of ['record-year','record-pc']){
                const choices=Array.from($(id).options).filter(option=>option.value);
                if(choices.length===1) $(id).value=choices[0].value;
            }
            if(await refreshOptions()) {
                for(const id of ['record-station','record-edition']){
                    const choices=Array.from($(id).options).filter(option=>option.value);
                    if(choices.length===1) $(id).value=choices[0].value;
                }
                submit();
            }
        }
    }
    startPilot();
})();
