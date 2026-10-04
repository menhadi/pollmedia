(() => {
    document.querySelectorAll('[data-census-year]').forEach(select => {
        const section=select.closest('.census-year-data');
        const showYear=()=>section.querySelectorAll('[data-census-year-panel]').forEach(panel=>panel.hidden=panel.dataset.censusYearPanel!==select.value);
        select.addEventListener('change',showYear);showYear();
    });
})();
