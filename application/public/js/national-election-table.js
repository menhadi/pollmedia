(() => {
    const root = document.querySelector('[data-national-elections]');
    if (!root) return;
    const section = () => root.querySelector('#national-records');
    let pending;
    let activeSearch = location.search;
    function start() {
        pending?.abort();
        pending = new AbortController();
        section().setAttribute('aria-busy', 'true');
        return pending;
    }
    async function update(url, focusName, scroll = false) {
        const job = start();
        const mapEdition = new URL(location.href).searchParams.get('map_edition');
        if (mapEdition) url.searchParams.set('map_edition', mapEdition);
        const request = new URL(url);
        request.searchParams.set('panel', '1');
        try {
            const response = await fetch(request, {signal: job.signal});
            if (!response.ok) throw new Error();
            const replacement = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById('national-records');
            if (!replacement) throw new Error();
            if (job !== pending) return;
            section().replaceWith(replacement);
            history.pushState(null, '', url);
            activeSearch = location.search;
            replacement.querySelector('[name="' + focusName + '"]')?.focus({preventScroll: true});
            if (scroll) replacement.scrollIntoView({block: 'start'});
        } catch (error) {
            if (!job.signal.aborted) location.assign(url);
        } finally {
            if (pending === job) section().removeAttribute('aria-busy');
        }
    }
    function filterUrl(form) {
        const url = new URL(form.action);
        for (const [name,value] of new FormData(form)) {
            if (value !== '') url.searchParams.set(name,value);
        }
        return url;
    }
    root.addEventListener('change', event => {
        const field = event.target;
        const form = field.closest('[data-record-filters]');
        if (!form || field.tagName !== 'SELECT') return;
        const reset = field.name === 'year' ? ['type', 'state', 'seat'] : field.name === 'seat' ? [] : ['seat'];
        for (const name of reset) {
            if (form.elements[name]) {
                form.elements[name].value = '';
                form.elements[name].disabled = true;
            }
        }
        if (form.elements.q) form.elements.q.value = '';
        update(filterUrl(form), field.name);
    });
    root.addEventListener('submit', event => {
        const form = event.target;
        if (form.matches('[data-map-edition-form]')) {
            event.preventDefault();
            const url = new URL(location.href);
            url.searchParams.set('map_edition', form.elements.map_edition.value);
            url.hash = 'national-lok-sabha-map';
            location.assign(url);
            return;
        }
        if (form.matches('[data-by-year-form]')) {
            event.preventDefault();
            update(filterUrl(form), 'year', true);
            return;
        }
        if (!form.matches('[data-record-filters]')) return;
        event.preventDefault();
        update(filterUrl(event.target), 'q');
    });
    root.addEventListener('click', async event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[data-record-link], a[data-national-more]');
        if (!link) return;
        event.preventDefault();
        if (link.hasAttribute('data-record-link')) return update(new URL(link.href), 'seat', true);
        if (section().getAttribute('aria-busy') === 'true') return;
        const job = start();
        const url = new URL(link.href);
        url.searchParams.set('panel','1');
        try {
            const response = await fetch(url, {signal:job.signal});
            if (!response.ok) throw new Error();
            const next = new DOMParser().parseFromString(await response.text(),'text/html').getElementById('national-records');
            if (!next || job !== pending) return;
            section().querySelector('.records-table tbody').append(...next.querySelector('.records-table tbody').rows);
            const status = section().querySelector('[data-national-status]');
            status.textContent = next.querySelector('[data-national-status]').textContent.replace(/^[\d,]+–/, Number(section().dataset.first).toLocaleString()+'–');
            const more = next.querySelector('[data-national-more]');
            if (more) link.href=more.href;
            else {status.tabIndex=-1;status.focus({preventScroll:true});link.remove();}
        } catch (error) {
            if (!job.signal.aborted) location.assign(link.href);
        } finally {
            if (pending === job) section().removeAttribute('aria-busy');
        }
    });
    window.addEventListener('popstate', () => {
        if (location.search !== activeSearch) location.reload();
    });
})();
