document.querySelectorAll('table[data-sortable]').forEach((table) => {
    const body = table.tBodies[0];
    if (!body || !table.tHead) return;
    const headers = Array.from(table.tHead.rows[0].cells);
    const collator = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });
    headers.forEach((header, column) => {
        header.scope = 'col';
        header.setAttribute('aria-sort', 'none');
        const label = header.textContent.trim();
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'table-sort';
        button.textContent = label;
        button.setAttribute('aria-label', `Sort by ${label}`);
        header.replaceChildren(button);
        button.addEventListener('click', () => {
            const descending = header.getAttribute('aria-sort') === 'ascending';
            const numeric = header.dataset.sortType === 'number';
            const value = (row) => {
                const cell = row.cells[column];
                const text = (cell?.dataset.sortValue ?? cell?.textContent ?? '').trim();
                if (!text || text === '—' || text === 'Not available') return null;
                if (!numeric) return text;
                const number = Number.parseFloat(text.replaceAll(',', ''));
                return Number.isFinite(number) ? number : null;
            };
            const rows = Array.from(body.rows);
            rows.sort((a, b) => {
                const left = value(a), right = value(b);
                if (left === null) return right === null ? 0 : 1;
                if (right === null) return -1;
                const compared = numeric ? left - right : collator.compare(left, right);
                return descending ? -compared : compared;
            });
            headers.forEach((item) => item.setAttribute('aria-sort', 'none'));
            header.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
            body.append(...rows);
        });
    });
});
