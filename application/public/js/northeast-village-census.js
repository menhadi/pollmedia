const node = (tag, text) => {
    const element = document.createElement(tag);
    element.textContent = text;
    return element;
};

export function appendNortheastCensus(detail, feature) {
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
}

export function appendNortheastCoverage(section, map) {
    const coverage = map.metadata.census_2011_linkage;
    if (!coverage) return;
    const summary = node('p', `${coverage.village_records} Census villages · ${coverage.matched_shapes} source shapes with matching unique code and name · ${coverage.villages_without_matched_shape} Census villages without a matched shape · ${coverage.unmatched_shapes} source shapes without a Census match. Boundary editions and village-to-AC/PC links remain unverified.`);
    summary.className = 'notice';
    summary.id = 'northeast-village-coverage';
    section.querySelector('.section-heading').after(summary);
}
