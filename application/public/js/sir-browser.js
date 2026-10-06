(() => {
    const $ = id => document.getElementById(id);
    const filters = ['record-year', 'record-state', 'record-pc', 'record-ac', 'record-station', 'record-edition'];
    let page = 1, criteria = null, optionsRequest, recordsRequest, generation = 0, officialStatistics = [];
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
        recordsRequest?.abort(); generation++;
        $('record-rows').replaceChildren(); $('record-page-info').textContent = '';
        $('record-prev').disabled = true; $('record-next').disabled = true;
    }
    async function refreshOptions() {
        optionsRequest?.abort();
        const active = new AbortController(); optionsRequest = active; let timedOut=false; const timeout=setTimeout(()=>{timedOut=true;active.abort();},20000);
        $('record-status').textContent = 'Loading available filters...';
        officialStatistics=[]; $('statistics-rows').replaceChildren(); $('statistics-download').disabled=true; $('statistics-status').textContent='Loading official totals...';
        try {
            const response = await fetch('/api/sir/editions?' + new URLSearchParams(scope()), {signal:active.signal, headers:{Accept:'application/json'}});
            if (!response.ok) throw Error('Unable to load available filters. Confirm the SIR geography migration has run.');
            const data = await response.json();
            if (active.signal.aborted) return false;
            renderStatistics(data.statistics || []);
            options('record-year', data.periods, 'All available years');
            options('record-state', data.states, 'All available states');
            options('record-pc', data.pcs, data.pcs.length ? 'All available PCs' : 'PC mapping unavailable - choose AC directly');
            $('record-pc').disabled = !data.pcs.length;
            options('record-ac', data.acs, 'All available ACs');
            options('record-edition', data.editions.map(e => ({value:e.edition_key, label:e.edition + ' - ' + e.document_date + ' - AC ' + e.ac_code})), 'All available editions');
            options('record-station', data.stations, $('record-ac').value ? 'All polling stations in this AC' : 'Choose an AC to list stations');
            $('record-station').disabled = !$('record-ac').value;
            $('scope-note').textContent = data.pcs.length ? 'Browse all imported names in the selected scope, or narrow the optional filters.' : 'PC grouping has no verified mapping for these records. Choose an AC directly. Document-year choices are labelled separately from verified revision years.';
            if(data.editions.length===1 && data.editions[0].printed_electors){const e=data.editions[0];$('scope-note').textContent+=' Pilot coverage: '+fmt(e.indexed_records)+' of '+fmt(e.printed_electors)+' entries indexed; '+fmt(e.held_records_count)+' held for review.';}
            $('record-status').textContent = data.editions.length ? 'Choose any geographic filter, or enter a name, to show available records.' : 'No voter-roll data imported for this selection. The older uncollected-form sample is excluded.';
            return true;
        } catch (error) {
            if (timedOut) $('record-status').textContent='Filters took too long to load. Press Retry loading filters.'; else if (error.name !== 'AbortError') $('record-status').textContent = error.message;
            return false;
        } finally {clearTimeout(timeout);
        }
    }
    function renderStatistics(rows) {
        officialStatistics=rows;
        $('statistics-rows').replaceChildren();
        $('statistics-download').disabled=!rows.length;
        $('statistics-status').textContent=rows.length ? 'Official PDF totals for '+rows.length+' imported part/edition summaries. Coverage is incomplete.' : 'No verified printed totals available for this selection.';
        rows.forEach(stats=>{
            const row=document.createElement('tr');
            cell(row,(stats.year || 'Revision year unverified')+' / '+stats.edition+' / published '+stats.document_date);
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
    async function showRecords() {
        recordsRequest?.abort(); const active=new AbortController(); recordsRequest=active;
        const ticket=++generation;
        $('record-prev').disabled=true; $('record-next').disabled=true;
        $('record-rows').replaceChildren(); $('record-status').textContent='Loading records...';
        try {
            const response=await fetch('/api/sir/records/search', {method:'POST', signal:active.signal,
                headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                body:JSON.stringify({...criteria, page})});
            const data=await response.json(); if (ticket!==generation) return;
            if (!response.ok) throw Error(data.message || 'Unable to load records.');
            data.data.forEach(record => {
                const row=document.createElement('tr'); cell(row,record.name); cell(row,record.relative_name); cell(row,record.relationship);
                cell(row,(record.year ? record.year+' (revision)' : record.document_date.slice(0,4)+' (document year; revision unverified)')+' / '+record.edition+' / '+record.document_date);
                cell(row,'AC '+record.ac_code+' - '+record.ac_name+' / Part '+record.part+' - '+record.station);
                const link=document.createElement('a'); link.href=record.pdf_url;
                link.textContent='PDF page '+record.pdf_page+' / serial '+record.serial; link.target='_blank'; link.rel='noopener noreferrer'; const sourceCell=cell(row,''); sourceCell.append(link); const official=document.createElement('a'); official.href=record.source_landing_url||record.source_url; official.textContent='Official publication'; official.target='_blank'; official.rel='noopener noreferrer'; sourceCell.append(document.createElement('br'),official); if(record.extraction_status==='ocr_candidate'){const note=document.createElement('small'); note.textContent='OCR text - verify PDF';sourceCell.append(document.createElement('br'),note);} $('record-rows').append(row);
            });
            $('record-status').textContent=data.total ? fmt(data.total)+' imported records match this scope. Names are optional.' : 'No imported records match this selection. This does not establish absence from the official electoral roll.';
            $('record-page-info').textContent=data.total ? 'Page '+data.current_page+' of '+data.last_page : '';
            $('record-prev').disabled=page<=1; $('record-next').disabled=!data.next_page_url;
        } catch (error) { if (ticket===generation && error.name!=='AbortError') $('record-status').textContent=error.message; }
    }
    function submit() {
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
    for (const id of ['record-name','record-relative']) $(id).addEventListener('input', () => {clearResults(); $('record-status').textContent='Press Show records to apply the optional name filters.';});
    $('record-prev').onclick=() => {page--; showRecords();}; $('record-next').onclick=() => {page++; showRecords();};
    $('record-reset').onclick=() => {clearResults(); filters.forEach(id => $(id).value=''); $('record-name').value=''; $('record-relative').value=''; criteria=null; page=1; refreshOptions();};
    $('record-retry').onclick=()=>refreshOptions();
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
