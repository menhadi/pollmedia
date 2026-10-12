// Navigation candidates only: code/name agreement does not certify boundary equivalence.
export function censusCandidate(feature, features, records) {
    const code = String(feature.properties.source_code || '').replace(/^0+/, '');
    if (!code || features.filter(f => String(f.properties.source_code || '').replace(/^0+/, '') === code).length !== 1) return null;
    const name = value => String(value || '').toLowerCase().replace(/\s*\(\d+\)\s*$/, '').replace(/[^a-z0-9]/g, '');
    const matches = records.filter(r => String(r.code).replace(/^0+/, '') === code && name(r.name) === name(feature.properties.name));
    return matches.length === 1 ? matches[0] : null;
}

export function appendCensusCandidate(detail, feature, features, records) {
    const candidate = censusCandidate(feature, features, records);
    if (!candidate || !/^\/india\/census\/places\/\d+$/.test(candidate.url)) return;
    const notice = document.createElement('p');
    notice.textContent = 'Matching Census code and name found. This is a possible record match; the map boundary edition and identifier crosswalk remain unverified.';
    const link = document.createElement('a');
    link.href = candidate.url;
    link.textContent = `View Census village: ${candidate.name} (${candidate.code})`;
    detail.append(notice, link);
}
