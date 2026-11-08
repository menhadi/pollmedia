(() => {
    const root = document.querySelector('[data-national-elections]');
    const more = root?.querySelector('[data-national-more]');
    if (!more) return;
    const table = root.querySelector('table');
    const status = root.querySelector('[data-national-status]');
    let busy = false;
    more.addEventListener('click', async event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (busy) return;
        busy = true;
        table.setAttribute('aria-busy','true');
        const url = new URL(more.href);
        url.searchParams.set('rows_only','1');
        try {
            const response = await fetch(url,{headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error('Records unavailable');
            const page = await response.json();
            const batch = document.createElement('tbody');
            batch.innerHTML = page.rows;
            table.tBodies[0].append(...Array.from(batch.rows));
            status.textContent = root.dataset.first+'–'+page.last+' / '+page.total;
            if (page.next) more.href=page.next;
            else { status.tabIndex=-1; status.focus({preventScroll:true}); more.remove(); }
        } catch {
            status.textContent = document.documentElement.lang==='hi'?'लोड नहीं हुआ। पुनः प्रयास करें।':'Could not load records. Try again.';
        } finally {
            table.removeAttribute('aria-busy'); busy=false;
        }
    });
})();
