// Soil observations retain the reporting cycle and exact source identifiers.
(async () => {
    const polygon = document.querySelector('[data-polygon-map]');
    const anchor = document.querySelector('[data-rainfall-panel]') || polygon;
    if (!anchor || document.getElementById('soil')) return;
    const {loadSoil,soilCycles,soilSampleTotal} = await import('./soil-map.js?v=20261014-national-1');
    const district = Boolean(polygon) || anchor.dataset.district === '1';
    const codes = new Set((anchor.dataset.lgd || '').split(',').filter(Boolean));
    let districtKey = null;
    if (district) {
        const response = await fetch('/maps/india-district-fast.json');
        if (!response.ok) return;
        const catalogue = await response.json();
        const matches = Object.entries(catalogue.dashboards || {}).filter(([, value]) => value.navigation.some(link => new URL(link.url, location.origin).pathname === location.pathname));
        if (matches.length === 1) districtKey = matches[0][0];
    }
    const datasets = (await Promise.all(soilCycles.map(cycle => loadSoil(cycle, [...codes], anchor.dataset.census ? [anchor.dataset.census] : []).catch(() => null)))).filter(Boolean);
    const matchesFor = data => data.records.filter(row => codes.size
        ? codes.has(row.lgd_village_code)
        : anchor.dataset.census && row.census_2011_code === anchor.dataset.census);
    const available = datasets.filter(data => district ? Boolean(data.summaries.districts[districtKey]) : matchesFor(data).length === 1);
    if (!available.length) return;
    const cycleSelect = document.createElement('select'); cycleSelect.id = 'soil-table-cycle';
    for (const data of available) { const option = document.createElement('option'); option.value = data.source.cycle; option.textContent = data.source.cycle; cycleSelect.append(option); }
    function render() {
    document.getElementById('soil')?.remove(); document.getElementById('soil-disclaimer')?.remove();
    const data = available.find(data => data.source.cycle === cycleSelect.value), matches = matchesFor(data);
    const row = district ? data.summaries.districts[districtKey] : matches[0], counts = row.counts;
    const el = (tag, text) => { const node = document.createElement(tag); if (text) node.textContent = text; return node; };
    const panel = el('section'); panel.id = 'soil'; panel.className = 'panel card coverage';
    panel.append(el('h2', 'Soil health · tested samples'));
    panel.append(el('p', `${data.source.scheme} · ${row.name} · ${district ? 'Official district summary' : 'Village sample results'}`));
    const cycleLabel = el('label', 'Reporting cycle '); cycleLabel.htmlFor = cycleSelect.id; cycleLabel.append(cycleSelect); panel.append(cycleLabel);
    const wrap = el('div'); wrap.className = 'table-wrap';
    const table = el('table'), head = el('thead'), header = el('tr');
    ['Indicator', 'Sample results', 'Samples reported'].forEach(text => header.append(el('th', text)));
    head.append(header); table.append(head); const body = el('tbody');
    for (const [prefix, label] of [['N','Nitrogen'],['P','Phosphorus'],['K','Potassium'],['OC','Organic carbon'],['P H','pH'],['EC','Electrical conductivity'],['S','Sulfur'],['Fe','Iron'],['Zn','Zinc'],['Cu','Copper'],['B','Boron'],['Mn','Manganese']]) {
        const entries = Object.entries(counts).filter(([key]) => key.startsWith(prefix + '_'));
        const total = soilSampleTotal(row, prefix);
        const tr = el('tr'); tr.append(el('td', label));
        tr.append(el('td', entries.map(([key, value]) => `${key.slice(prefix.length + 1)}: ${value.toLocaleString('en-IN')}${total ? ` (${(value * 100 / total).toFixed(1)}%)` : ''}`).join(' · ')));
        tr.append(el('td', total == null ? 'Unavailable' : total.toLocaleString('en-IN'))); body.append(tr);
    }
    table.append(body); wrap.append(table); panel.append(wrap);
    const source = el('a', 'Official Soil Health Card nutrient dashboard ↗'); source.href = data.source.url; source.target = '_blank'; source.rel = 'noopener';
    const sourceLine = el('p'); sourceLine.append(source); panel.append(sourceLine);
    const note = el('details'); note.id = 'soil-disclaimer';
    note.append(el('summary', 'Soil sources, dates and coverage'));
    note.append(el('p', `Downloaded ${data.source.retrieved_at.slice(0,10)}. ${data.method}`));
    note.append(el('p', 'Results describe tested samples in this reporting cycle, not every field or the percentage of village land. No pH averages, land-area estimates or values for villages without linked reports are inferred. Each indicator uses its own reported sample count. Original LGD, SOI and Census identifiers and polygons are unchanged.'));
    if (district) note.append(el('p', 'This is the official district summary, not an estimate for every village. Source district totals can differ from the sum of village records available in the portal.'));
    else note.append(el('p', `SHC village code ${row.shc_village_code}; LGD village code ${row.lgd_village_code}; SHC block code ${row.shc_block_code}.`));
    note.append(el('p', `Source record ${row.source_record_id}; downloaded ${row.retrieved_at}; source updated ${row.source_updated_at || 'not supplied'}. Source ${row.source_file}, row ${row.source_row}; SHA-256 ${row.source_sha256}.`));
    const placement = district ? (document.getElementById('villages') || polygon || anchor) : (polygon || anchor);
    placement.after(panel); document.querySelector('main')?.append(note);
    }
    cycleSelect.addEventListener('change',render); render();
})().catch(error => console.warn('Soil data unavailable:', error.message));
