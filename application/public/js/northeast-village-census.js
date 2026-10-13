const node = (tag, text) => {
    const element = document.createElement(tag);
    element.textContent = text;
    return element;
};

export function appendNortheastCensus(detail, feature, map) {
    const census = feature.properties.census_2011;
    if (!census) return;
    for (const paragraph of [...detail.querySelectorAll('p')]) {
        if (paragraph.textContent === 'Map-to-Census code link pending.' || paragraph.textContent.startsWith('Census population: link pending.')) paragraph.remove();
    }
    detail.append(node('h4', `Census 2011 · ${census.name} (${census.code})`));
    detail.append(node('p', `Village population: ${census.population === null ? 'Unavailable' : census.population.toLocaleString('en-IN')} · Households: ${census.households === null ? 'Unavailable' : census.households.toLocaleString('en-IN')}`));
    detail.append(node('p', census.match_basis));
    detail.append(node('p', 'Figures describe the Census village, not a population estimate for this polygon. LGD and AC/PC village membership remain unverified.'));
    const link = node('a', 'Official village Census workbook ↗');
    link.href = census.source_url;
    detail.append(link);
    const pc = map?.metadata.village_electoral_context?.pc;
    const ac = feature.properties.dated_ac;
    if (pc || ac) {
        detail.append(node('h4', 'Dated electoral source links'));
        for (const [kind, assignment] of [['AC', ac], ['PC', pc]]) {
            if (!assignment) {
                detail.append(node('p', `${kind}: village assignment pending official crosswalk.`));
                continue;
            }
            detail.append(node('p', `${kind} ${assignment.code} · ${assignment.name}`));
            detail.append(node('p', assignment.basis));
            const source = node('a', assignment.source_date);
            source.href = assignment.source_url;
            detail.append(source);
        }
    }
}

export function appendNortheastCoverage(section, map, choose) {
    const coverage = map.metadata.census_2011_linkage;
    if (!coverage) return;
    const summary = node('p', `${coverage.village_records} Census villages · ${coverage.matched_shapes} source shapes with matching unique code and name · ${coverage.villages_without_matched_shape} Census villages without a matched shape · ${coverage.unmatched_shapes} source shapes without a Census match. Boundary editions and village-to-AC/PC links remain unverified.`);
    summary.className = 'notice';
    summary.id = 'northeast-village-coverage';
    section.querySelector('.section-heading').after(summary);
    if (choose) {
        const label = node('label', 'Choose a village / source place');
        const select = document.createElement('select');
        select.id = 'northeast-village-picker';
        const placeholder = node('option', 'Select a village to view its details');
        placeholder.value = '';
        select.append(placeholder);
        map.features.map((feature, index) => ({feature, index})).sort((a, b) => a.feature.properties.name.localeCompare(b.feature.properties.name)).forEach(({feature, index}) => {
            const p = feature.properties;
            const option = node('option', `${p.name} · ${p.source_code}${p.census_2011 ? '' : ' · Census match pending'}`);
            option.value = String(index);
            select.append(option);
        });
        label.append(select);
        section.querySelector('.map-filters').append(label);
        select.addEventListener('change', () => {
            if (select.value !== '') choose(map.features[Number(select.value)]);
        });
        const code = new URL(location.href).searchParams.get('village');
        const matches = map.features.map((f, index) => ({f, index})).filter(({f}) => f.properties.census_2011?.code === code);
        if (code && matches.length === 1) {
            select.value = String(matches[0].index);
            choose(matches[0].f);
        }
    }
}
