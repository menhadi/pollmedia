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
        if (event.target.matches('[data-go-url]')) location.assign(event.target.value);
        if (event.target.matches('[data-submit-choice]')) event.target.form.requestSubmit();
    });
    document.querySelectorAll('[data-seat-list]').forEach(list => {
        const rows = Array.from(list.querySelectorAll('tbody tr'));
        if (!rows.some(row => row.querySelector('th'))) return;
        const input = list.querySelector('[data-seat-filter]');
        const status = list.querySelector('[role=status]');
        const buttons = list.querySelectorAll('[data-step]');
        let page = 0;
        const size = 12;
        function render() {
            const query = input.value.trim().toLocaleLowerCase();
            const matches = rows.filter(row => row.cells[0].textContent.toLocaleLowerCase().includes(query));
            const start = page * size;
            const visible = new Set(matches.slice(start, start + size));
            rows.forEach(row => { row.hidden = !visible.has(row); });
            status.textContent = matches.length ? (start + 1) + '–' + Math.min(start + size, matches.length) + ' / ' + matches.length : '0 results';
            buttons[0].disabled = page === 0;
            buttons[1].disabled = start + size >= matches.length;
        }
        input.addEventListener('input', () => { page = 0; render(); });
        buttons.forEach(button => button.addEventListener('click', () => { page += Number(button.dataset.step); render(); }));
        list.querySelectorAll('[data-seat-tools]').forEach(item => { item.hidden = false; });
        render();
    });
})();
