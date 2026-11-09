const records = document.getElementById('population-records');
const form = records?.querySelector('form');
form?.addEventListener('change', event => {
    if (event.target.name === 'census_year') form.elements.census_area.value = '';
    form.requestSubmit();
});
const more = records?.querySelector('[data-more-areas]');
if (more) {
    const remaining = [...records.querySelector('tbody').rows].slice(20);
    remaining.forEach(row => row.hidden = true);
    more.hidden = false;
    more.addEventListener('click', () => {
        remaining.forEach(row => row.hidden = false);
        remaining[0].firstElementChild.tabIndex = -1;
        remaining[0].firstElementChild.focus({preventScroll:true});
        more.remove();
    });
}
