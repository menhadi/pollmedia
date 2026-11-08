const mapForm = document.querySelector('.electoral-pilot-controls');
mapForm?.addEventListener('submit', event => {
    const target = mapForm.elements.state.selectedOptions[0]?.dataset.mapPath;
    if (!target) return;
    const url = new URL(target, location.origin);
    if (mapForm.elements.kind.value === 'ac') url.searchParams.set('kind', 'ac');
    if (mapForm.elements.lang?.value) url.searchParams.set('lang', mapForm.elements.lang.value);
    event.preventDefault();
    location.assign(url);
});
