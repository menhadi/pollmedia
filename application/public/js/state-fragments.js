(() => {
    const requests = new Map();
    async function update(target, url) {
        const panel = document.getElementById(target);
        if (!panel) return;
        requests.get(target)?.abort();
        const request = new AbortController(); requests.set(target, request);
        panel.setAttribute('aria-busy', 'true');
        let status = panel.querySelector('.fragment-status');
        if (!status) { status = document.createElement('p'); status.className = 'fragment-status'; status.setAttribute('role', 'status'); panel.prepend(status); }
        status.textContent = 'Loading results…';
        try {
            const response = await fetch(url, {signal:request.signal, headers:{'X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok) throw new Error('Request failed');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const replacement = page.getElementById(target);
            if (!replacement) throw new Error('Results missing');
            if (request.signal.aborted) return;
            panel.replaceWith(replacement);
            window.pollmediaSortTables?.(replacement);
            window.pollmediaPlaceSearch?.(replacement);
            window.pollmediaElectionMaps?.(replacement);
            replacement.setAttribute('tabindex', '-1'); replacement.focus({preventScroll:true});
            // Keep each section's filters independent; a reload returns to the latest selections.
            const current = new URL(location.href), incoming = new URL(url, location.href);
            const names = target.endsWith('-results') ? [target.slice(0,2)+'_edition'] : target.endsWith('-constituencies') ? [target.slice(0,2)+'_scope',target.slice(0,2)+'_page',target.slice(0,2)+'_q'] : ['place','type','page'];
            names.forEach(name => { incoming.searchParams.has(name) ? current.searchParams.set(name,incoming.searchParams.get(name)) : current.searchParams.delete(name); });
            current.hash = target; history.replaceState(null, '', current);
            document.querySelectorAll('input[type=hidden][name$="_edition"]').forEach(input => { if (current.searchParams.has(input.name)) input.value=current.searchParams.get(input.name); });
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = 'Results could not be loaded. Please try again.';
        } finally { if (requests.get(target)===request) {panel.removeAttribute('aria-busy');requests.delete(target);} }
    }
    document.addEventListener('submit', event => {
        const form = event.target.closest('[data-fragment-form]');
        if (!form) return;
        event.preventDefault();
        const url = new URL(form.action); url.search = new URLSearchParams(new FormData(form)).toString();
        update(form.dataset.target, url);
    });
    document.addEventListener('change', event => {
        const form = event.target.closest('[data-fragment-form]');
        if (form && event.target.matches('select')) {event.stopImmediatePropagation();form.requestSubmit();}
    }, true);
    document.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button!==0) return;
        const link = event.target.closest('[data-fragment-link], [id$="-constituencies"] nav a, #politics nav a');
        if (!link) return;
        const panel = link.closest('section[id]');
        if (!panel) return;
        event.preventDefault(); update(panel.id, link.href);
    });
})();
