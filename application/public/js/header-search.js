(() => {
    if (window.pollmediaPlaceSearch) return;
    window.pollmediaPlaceSearch = (root = document) => root.querySelectorAll('[data-place-search]').forEach(form => {
    if (form.dataset.suggestionsReady) return;
    form.dataset.suggestionsReady = 'true';
    const input = form.querySelector('[role=combobox]');
    if (!input) return;
    const list = document.getElementById(input.getAttribute('aria-controls'));
    const status = form.querySelector('[role=status]');
    if (!input || !list || !status) return;
    let timer, request, selected = -1, matches = [];
    const recent = new Map();
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); selected = -1; };
    const highlight = () => {
        Array.from(list.children).forEach((node, index) => node.setAttribute('aria-selected', String(index === selected)));
        if (selected >= 0) { input.setAttribute('aria-activedescendant', `${input.id}-suggestion-${selected}`); list.children[selected].scrollIntoView({block:'nearest'}); }
    };
    const render = rows => {
        selected = -1;
        input.removeAttribute('aria-activedescendant');
        matches = [...rows];
        list.replaceChildren();
        matches.forEach((match,index) => {
            const option = document.createElement('a'); option.id=`${input.id}-suggestion-${index}`;
            option.href=match.url; option.role='option'; option.setAttribute('aria-selected','false'); option.tabIndex=-1;
            const name=document.createElement('strong'); name.textContent=match.label;
            const type=document.createElement('small'); type.textContent=match.type+(match.period ? " · "+match.period : "");
            option.append(name,type); list.append(option);
        });
        status.textContent=matches.length ? `${matches.length} suggestions. Use arrow keys to choose.` : 'No suggestions. Press Search to view available results.';
        list.hidden=!matches.length; input.setAttribute('aria-expanded',String(matches.length>0));
    };
    input.addEventListener('input', event => {
        clearTimeout(timer); request?.abort(); close(); status.textContent = '';
        if (event.isComposing || input.value.trim().length < 2) return;
        const key = input.value.trim().toLocaleLowerCase();
        const cached = recent.get(key);
        if (cached && cached.expires > Date.now()) { render(cached.rows); return; }
        recent.delete(key);
        timer = setTimeout(async () => {
            const query = input.value.trim();
            const active = new AbortController(); request = active;
            status.textContent = 'Searching…';
            try {
                const url = new URL(form.dataset.suggestUrl || input.form.action, window.location.href); url.searchParams.set(form.dataset.suggestQuery || 'q', query);
                const response = await fetch(url, {headers:{Accept:'application/json'},signal:active.signal});
                if (!response.ok) throw new Error('Search unavailable');
                const data = await response.json();
                if (active.signal.aborted || input.value.trim() !== query) return;
                render(data.suggestions);
                if (recent.size >= 40) recent.delete(recent.keys().next().value);
                recent.set(key, {rows:data.suggestions,expires:Date.now()+30000});
            } catch(error) {
                if(error.name!=='AbortError') status.textContent='Suggestions unavailable. Press Search to continue.';
            }
        },250);
    });
    input.addEventListener('keydown', event => {
        if(event.key==='Escape'){request?.abort();clearTimeout(timer);close();return;}
        if(list.hidden) return;
        if(event.key==='ArrowDown'||event.key==='ArrowUp'){
            event.preventDefault();
            selected = selected < 0 ? (event.key === 'ArrowDown' ? 0 : matches.length - 1) : (selected + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
            highlight();
        } else if(event.key==='Enter'&&selected>=0){event.preventDefault();window.location.assign(matches[selected].url);}
        else if(event.key==='Tab') close();
    });
    document.addEventListener('click',event=>{if(!form.contains(event.target)){clearTimeout(timer);request?.abort();close();}});
    });
    window.pollmediaPlaceSearch();
})();
