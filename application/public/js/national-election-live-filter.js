(() => {
    if (window.pollmediaNationalLiveSearch) return;
    window.pollmediaNationalLiveSearch = true;
    let timer;

    document.addEventListener('input', (event) => {
        const input = event.target;
        if (!input || input.name !== 'q' || !input.closest('[data-record-filters]')) return;
        const form = input.closest('form');
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (form.checkValidity()) form.requestSubmit();
        }, 320);
    });
})();
