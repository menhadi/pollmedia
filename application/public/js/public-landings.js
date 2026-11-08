(() => {
    const legacy = location.hash.match(/^#(pc|ac)-(?:history|results|constituencies)$/);
    const chamber = document.querySelector('.landing-switch a[aria-current="page"]');
    if (legacy && chamber && new URL(chamber.href).searchParams.get('election') !== legacy[1]) {
        const target = new URL(location.href);
        target.searchParams.set('election', legacy[1]);
        location.replace(target);
        return;
    }
    document.addEventListener('change', event => {
        if (event.target.matches('[data-go-url]') && event.target.value) location.assign(event.target.value);
        if (event.target.matches('[data-submit-choice]')) event.target.form.requestSubmit();
    });
    const pending = new WeakMap();
    const hi = document.documentElement.lang.startsWith('hi');
    function controlFor(table) {
        return table.parentElement.querySelector('[data-table-control="'+table.dataset.stateTable+'"]');
    }
    function expand(table) {
        if (!table.hasAttribute('data-deferred')) return Promise.resolve(true);
        if (pending.has(table)) return pending.get(table);
        const control = controlFor(table);
        const link = control.querySelector('a');
        const status = control.querySelector('[role=status]');
        status.textContent = hi ? 'लोड हो रहा है…' : 'Loading…';
        table.setAttribute('aria-busy','true');
        const task = (async () => {
            try {
                const response = await fetch(link.dataset.rowsUrl, {headers:{Accept:'application/json'}});
                if (!response.ok) throw new Error('Table unavailable');
                const data = await response.json();
                if (data.table !== table.dataset.stateTable || !Array.isArray(data.bodies) || data.bodies.length !== table.tBodies.length) {
                    throw new Error('Table changed');
                }
                const bodies = data.bodies.map(html => {
                    const body = document.createElement('tbody');
                    body.innerHTML = html;
                    return body;
                });
                if (bodies.reduce((n,body)=>n+body.rows.length,0)!==data.count) throw new Error('Incomplete table');
                Array.from(table.tHead.rows[table.tHead.rows.length-1].cells).forEach((th,index)=>{
                    th.toggleAttribute('data-number',data.numericColumns.includes(index));
                });
                bodies.forEach((body,index)=>table.tBodies[index].replaceChildren(...Array.from(body.rows)));
                table.removeAttribute('data-deferred');
                status.textContent = hi ? 'सभी '+data.count+' पंक्तियाँ' : 'All '+data.count+' rows loaded';
                if (document.activeElement===link) {
                    table.tabIndex = -1;
                    table.focus({preventScroll:true});
                }
                link.hidden = true;
                return true;
            } catch {
                status.textContent = hi ? 'लोड नहीं हुआ। पुनः प्रयास करें।' : 'Could not load the rows. Try again.';
                return false;
            } finally {
                table.removeAttribute('aria-busy');
                pending.delete(table);
            }
        })();
        pending.set(table,task);
        return task;
    }
    const searchSelector = '[data-party-filter],[data-seat-filter],[data-district-list] input[type=search],[data-area-table-search]';
    function searchTable(table,input) {
        if (!input) return;
        const query = input.value.trim().toLocaleLowerCase();
        const rows = Array.from(table.tBodies[0].rows);
        let matches = 0;
        for (const row of rows) {
            row.hidden = !row.cells[0].textContent.toLocaleLowerCase().includes(query);
            if (!row.hidden) matches++;
        }
        if (table.hasAttribute('data-deferred')) return;
        let status = controlFor(table)?.querySelector('[role=status]') || input.parentElement.querySelector('[data-search-count]');
        if (!status && query) {
            status = document.createElement('span');
            status.dataset.searchCount = '';
            status.setAttribute('role','status');
            input.after(status);
        }
        if (status) status.textContent = query
            ? (hi ? matches+' मिलती पंक्तियाँ' : matches+' matching rows')
            : (hi ? 'सभी '+rows.length+' पंक्तियाँ' : 'All '+rows.length+' rows');
    }
    function inputFor(table) {
        return table.closest('[data-party-list],[data-seat-list],[data-district-list],#area-tables')?.querySelector(searchSelector);
    }
    document.addEventListener('click',async event=>{
        const link=event.target.closest('[data-rows-url]');
        if (!link || event.button!==0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        const table=document.getElementById(link.getAttribute('aria-controls'));
        if (table && await expand(table)) searchTable(table,inputFor(table));
    });
    document.addEventListener('click',async event=>{
        const button=event.target.closest('thead button');
        const table=button?.closest('table[data-deferred]');
        if (!table) return;
        event.preventDefault();event.stopImmediatePropagation();
        if (await expand(table)) { searchTable(table,inputFor(table));button.click(); }
    },true);
    document.addEventListener('input',async event=>{
        const input=event.target.closest(searchSelector);
        if (!input) return;
        const scope=input.closest('[data-party-list],[data-seat-list],[data-district-list],#area-tables');
        if (!scope) return;
        for (const table of scope.querySelectorAll('table[data-state-table]')) {
            searchTable(table,input);
            if (input.value.trim() && table.hasAttribute('data-deferred') && await expand(table)) searchTable(table,input);
        }
    });
    function enableSearch(root=document) {
        root.querySelectorAll('[data-seat-tools][hidden]').forEach(el=>el.hidden=false);
    }
    enableSearch();
    document.addEventListener('pm-topic-mounted',()=>enableSearch());
})();
