const selector = document.getElementById('assam-electoral-district');
if (selector) selector.addEventListener('change', () => {
    let pcs = 0, acs = 0;
    for (const pc of document.querySelectorAll('[data-assam-pc]')) {
        const segments = [...pc.querySelectorAll('[data-electoral-district]')];
        const matches = segments.filter(ac => selector.value === 'all' || ac.dataset.electoralDistrict === selector.value);
        pc.hidden = matches.length === 0;
        if (matches.length) { pcs++; acs += matches.length; }
        for (const ac of segments) ac.style.fontWeight = selector.value !== 'all' && ac.dataset.electoralDistrict === selector.value ? '700' : '400';
    }
    document.getElementById('assam-electoral-filter-note').textContent = `${acs} ACs under the selected 2023 heading, associated with ${pcs} PCs. All ACs in each associated PC remain visible; matching district segments are bold. This does not certify correspondence to the village or district outline maps.`;
});
