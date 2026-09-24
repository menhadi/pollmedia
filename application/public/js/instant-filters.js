(() => {
    if (window.pollmediaInstantFilters) return;
    window.pollmediaInstantFilters = true;
    document.querySelectorAll('form').forEach(form => {
        if (form.method.toLowerCase() !== 'get' || form.matches('.header-search,[data-manual-submit]')) return;
        let timer;
        const submit = () => {
            if (!form.checkValidity()) return;
            const page = form.querySelector('input[name="page"]');
            if (page) page.value = '1';
            form.requestSubmit();
        };
        form.addEventListener('change', event => {
            if (event.defaultPrevented || !event.target.matches('select,input[type=radio],input[type=checkbox],input[type=date]')) return;
            clearTimeout(timer);
            submit();
        });
        form.addEventListener('input', event => {
            if (event.isComposing || !event.target.matches('input[type=search],input[type=text],input[type=number],input:not([type])')) return;
            clearTimeout(timer);
            timer = setTimeout(submit, 650);
        });
        form.addEventListener('submit', () => clearTimeout(timer));
    });
    const input = document.getElementById('site-search');
    if (!input) return;
    const list = document.getElementById('search-suggestions');
    const status = document.getElementById('search-suggestion-status');
    let timer, request, selected = -1, matches = [];
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); selected = -1; };
    const highlight = () => {
        Array.from(list.children).forEach((node, index) => node.setAttribute('aria-selected', String(index === selected)));
        if (selected >= 0) { input.setAttribute('aria-activedescendant', `suggestion-${selected}`); list.children[selected].scrollIntoView({block:'nearest'}); }
    };
    input.addEventListener('input', event => {
        clearTimeout(timer); request?.abort(); close(); status.textContent = '';
        if (event.isComposing || input.value.trim().length < 2) return;
        timer = setTimeout(async () => {
            const query = input.value.trim();
            const active = new AbortController(); request = active;
            status.textContent = 'Searching…';
            try {
                const url = new URL(input.form.action); url.searchParams.set('q', query);
                const response = await fetch(url, {headers:{Accept:'application/json'},signal:active.signal});
                if (!response.ok) throw new Error('Search unavailable');
                const data = await response.json();
                if (active.signal.aborted || input.value.trim() !== query) return;
                matches = data.suggestions;
                list.replaceChildren();
                matches.forEach((match,index) => {
                    const option = document.createElement('a'); option.id=`suggestion-${index}`;
                    option.href=match.url; option.role='option'; option.setAttribute('aria-selected','false'); option.tabIndex=-1;
                    const name=document.createElement('strong'); name.textContent=match.label;
                    const type=document.createElement('small'); type.textContent=match.type;
                    option.append(name,type); list.append(option);
                });
                status.textContent=matches.length ? `${matches.length} suggestions. Use arrow keys to choose.` : 'No suggestions. Press Search to view available results.';
                list.hidden=!matches.length; input.setAttribute('aria-expanded',String(matches.length>0));
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
    document.addEventListener('click',event=>{if(!event.target.closest('.header-search-wrap')){clearTimeout(timer);request?.abort();close();}});
})();
