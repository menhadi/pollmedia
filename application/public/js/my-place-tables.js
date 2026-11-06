const collator = new Intl.Collator(undefined, {numeric:true, sensitivity:'base'});
const missing = value => /^(?:|—|–|Unavailable|Not available|उपलब्ध नहीं)$/i.test(value.trim());
const number = value => {
    const normalized = value.replace(/[₹,%\s,]/g,'');
    return /^[+-]?(?:\d+\.?\d*|\.\d+)$/.test(normalized) ? Number(normalized) : null;
};
export function compareCells(left, right, direction = 1) {
    if (missing(left) || missing(right)) return Number(missing(left))-Number(missing(right));
    const a=number(left), b=number(right);
    return direction * (a!==null && b!==null ? a-b : collator.compare(left,right));
}
export function mountTables(root) {
    const ready = new WeakSet();
    const scan = () => root.querySelectorAll('table').forEach(table => {
        if(ready.has(table) || !table.tBodies.length) return;
        if(!table.tHead) {
            const rows=[...table.tBodies[0].rows];
            if(!rows.length || !rows.every(row=>row.cells.length===2 && row.cells[0].tagName==='TH' && row.cells[1].tagName==='TD')) return;
            const heading=table.createTHead().insertRow();
            for(const label of root.dataset.language==='hi'?['विवरण','मान']:['Field','Value']) {
                const cell=document.createElement('th');cell.scope='col';cell.textContent=label;heading.append(cell);
            }
        }
        ready.add(table);
        const headers=[...table.tHead.rows[table.tHead.rows.length-1].cells];
        headers.forEach((header,index) => {
            if(header.colSpan!==1 || header.querySelector('button')) return;
            const button=document.createElement('button');
            button.type='button';button.className='table-sort';button.title=root.dataset.language==='hi'?'क्रम बदलने के लिए दबाएँ':'Click to sort ascending or descending';
            const title=document.createElement('span');title.textContent=header.textContent.trim();
            const indicator=document.createElement('span');indicator.className='table-sort-indicator';indicator.textContent='';const up=document.createElement('span'),down=document.createElement('span');up.textContent='▲';down.textContent='▼';indicator.append(up,down);indicator.setAttribute('aria-hidden','true');
            button.append(title,indicator);header.replaceChildren(button);header.setAttribute('aria-sort','none');
            button.addEventListener('click',() => {
                const direction=header.getAttribute('aria-sort')==='ascending'?-1:1;
                headers.forEach(cell => {cell.setAttribute('aria-sort','none');const icon=cell.querySelector('.table-sort-indicator');if(icon)icon.removeAttribute('data-direction');});
                header.setAttribute('aria-sort',direction===1?'ascending':'descending');indicator.dataset.direction=direction===1?'ascending':'descending';
                for(const body of table.tBodies) {
                    const rows=[...body.rows];
                    // Preserve full-width empty-state rows and compare only data rows.
                    const sortable=rows.filter(row=>row.cells.length===headers.length && [...row.cells].every(cell=>cell.colSpan===1));
                    sortable.sort((a,b)=>compareCells(a.cells[index].dataset.sortValue ?? a.cells[index].textContent.trim(),b.cells[index].dataset.sortValue ?? b.cells[index].textContent.trim(),direction));
                    let next=0;
                    body.append(...rows.map(row=>sortable.includes(row)?sortable[next++]:row));
                }
            });
        });
    });
    scan();
    const observer=new MutationObserver(scan);observer.observe(root,{childList:true,subtree:true});
    return () => observer.disconnect();
}
