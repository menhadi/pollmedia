(() => {
    const root = document.querySelector('[data-my-place]');
    if (!root) return;
    const form = root.querySelector('[data-place-form]');
    if (form) {
        const subdistrict = form.querySelector('[data-subdistrict]');
        const village = form.querySelector('[data-village]');
        const options = Array.from(village.options).map(option => option.cloneNode(true));
        const filterVillages = () => {
            const selected = village.value;
            village.replaceChildren(...options.filter(option => !option.value || !subdistrict.value || option.dataset.subdistrict === subdistrict.value).map(option => option.cloneNode(true)));
            village.value = Array.from(village.options).some(option => option.value === selected) ? selected : '';
        };
        subdistrict.addEventListener('change', filterVillages);
        filterVillages();
        const key = 'pollmedia-my-place-pilibhit-v1';
        try {
            const saved = localStorage.getItem(key);
            const params = new URLSearchParams(location.search);
            if (saved && !params.has('village') && !params.has('subdistrict') && options.some(option => option.value === saved)) {
                const url = new URL(form.action);
                url.searchParams.set('village', saved);
                url.searchParams.set('lang', form.querySelector('[name=lang]').value);
                location.replace(url.href);
                return;
            }
            form.querySelector('[data-memory-controls]').hidden = false;
            const remember = form.querySelector('[data-remember]');
            form.addEventListener('submit', () => {
                try {
                    if (remember.checked && village.value) localStorage.setItem(key, village.value);
                    else localStorage.removeItem(key);
                } catch (_) { /* The form remains usable without device storage. */ }
            });
            form.querySelector('[data-forget]').addEventListener('click', () => {
                localStorage.removeItem(key);
                remember.checked = false;
                const status = form.querySelector('[data-memory-status]');
                status.textContent = status.dataset.forgot;
            });
        } catch (_) { /* Village selection does not require device storage. */ }
    }
    const tools = root.querySelector('[data-service-tools]');
    const cards = Array.from(root.querySelectorAll('[data-service-card]'));
    const search = root.querySelector('[data-service-search]');
    const count = root.querySelector('[data-service-count]');
    let category = 'all';
    const filter = () => {
        const term = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        cards.forEach(card => {
            card.hidden = (category !== 'all' && card.dataset.category !== category) || !card.dataset.search.toLocaleLowerCase().includes(term);
            if (!card.hidden) visible++;
        });
        count.textContent = count.dataset.template.replace(':count', visible);
        root.querySelector('[data-service-empty]').hidden = visible !== 0;
    };
    tools.hidden = false;
    tools.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', () => {
        category = button.dataset.category;
        tools.querySelectorAll('[data-category]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        filter();
    }));
    search.addEventListener('input', filter);
    filter();
})();
