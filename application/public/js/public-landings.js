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
    document.querySelectorAll('[data-party-list], [data-seat-list]').forEach(list => {
        const body = list.querySelector('tbody');
        if (!body?.querySelector('th')) return;
        const input = list.querySelector('input[type=search]');
        const status = list.querySelector('[role=status]');
        const steps = list.querySelectorAll('[data-step]');
        const toggle = list.querySelector('[data-show-all]');
        const hi = document.documentElement.lang.startsWith('hi');
        const size = 12;
        let page = 0;
        let showAll = false;
        function render() {
            const rows = Array.from(body.rows);
            const query = input.value.trim().toLocaleLowerCase();
            const matches = rows.filter(row => row.cells[0].textContent.toLocaleLowerCase().includes(query));
            const start = showAll ? 0 : page * size;
            const end = showAll ? matches.length : Math.min(start + size, matches.length);
            const visible = new Set(matches.slice(start, end));
            rows.forEach(row => { row.hidden = !visible.has(row); });
            status.textContent = matches.length ? (start + 1) + '–' + end + ' / ' + matches.length : (hi ? '0 परिणाम' : '0 results');
            steps[0].disabled = showAll || page === 0;
            steps[1].disabled = end >= matches.length;
            toggle.hidden = matches.length <= size;
            toggle.setAttribute('aria-pressed', String(showAll));
            toggle.textContent = showAll ? (hi ? 'पृष्ठों में दिखाएँ' : 'Show pages') : (hi ? 'सभी दिखाएँ' : 'Show all');
        }
        function firstPage() { page = 0; render(); }
        input.addEventListener('input', firstPage);
        steps.forEach(button => button.addEventListener('click', () => { page += Number(button.dataset.step); render(); }));
        toggle.addEventListener('click', () => { showAll = !showAll; firstPage(); });
        // The shared sorter reorders tbody rows. Page the new order on every sort.
        new MutationObserver(firstPage).observe(body, {childList:true});
        list.querySelectorAll('[data-seat-tools]').forEach(item => { item.hidden = false; });
        render();
    });
})();
