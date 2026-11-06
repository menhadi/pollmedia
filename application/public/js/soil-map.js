export const soilMetrics = {
    soil_n: ['Soil · nitrogen low samples (%)', 'N', 'Low'],
    soil_p: ['Soil · phosphorus low samples (%)', 'P', 'Low'],
    soil_k: ['Soil · potassium low samples (%)', 'K', 'Low'],
    soil_oc: ['Soil · organic carbon low samples (%)', 'OC', 'Low'],
    soil_acidic: ['Soil · acidic samples (%)', 'P H', 'Acidic'],
    soil_saline: ['Soil · saline samples (%)', 'EC', 'Saline'],
};
export const isSoil = key => Object.hasOwn(soilMetrics, key);
export function soilSampleTotal(row, prefix) {
    const categories = prefix === 'P H' ? ['Acidic', 'Alkaline', 'Neutral'] : prefix === 'EC' ? ['Saline', 'Non Saline'] : ['N','P','K','OC'].includes(prefix) ? ['Low', 'Medium', 'High'] : ['Sufficient', 'Deficient'];
    const entries = Object.entries(row?.counts || {}).filter(([name]) => name.startsWith(prefix + '_'));
    if (entries.length !== categories.length || !categories.every(name => Number.isFinite(row?.counts?.[prefix + '_' + name]) && row.counts[prefix + '_' + name] >= 0)) return null;
    return entries.reduce((total, [, count]) => total + count, 0);
}
export function soilSampleCount(row, key) {
    const prefix = soilMetrics[key]?.[1];
    return prefix ? soilSampleTotal(row, prefix) : null;
}
export function soilValues(row) {
    return Object.fromEntries(Object.entries(soilMetrics).map(([key, [, prefix, category]]) => {
        const total = soilSampleTotal(row, prefix);
        const value = row?.counts?.[prefix + '_' + category];
        return [key, total && Number.isFinite(value) ? Math.round(value * 10000 / total) / 100 : null];
    }));
}
export const soilCycles = ['2026-27', '2025-26', '2024-25', '2023-24'];
const pending = new Map();
const nationalBase = '/maps/soil-national-20261014/';
function jsonFile(path) {
    if (!pending.has(path)) pending.set(path, fetch(path).then(response => {
        if (!response.ok) throw Error('Official soil records temporarily unavailable');
        return response.json();
    }).catch(error => { pending.delete(path); throw error; }));
    return pending.get(path);
}
export async function loadSoil(cycle = soilCycles[0], codes = [], censusCodes = []) {
    if (!soilCycles.includes(cycle)) throw Error('Unsupported soil cycle');
    const manifest = await jsonFile(nationalBase + 'manifest.json');
    const entry = manifest.cycles[cycle];
    const linkedCodes = new Set(codes.filter(Boolean).map(String));
    for (const prefix of new Set(censusCodes.filter(Boolean).map(code => String(code).slice(0, 2)))) {
        if (!manifest.census_prefixes.includes(prefix)) continue;
        const index = await jsonFile(nationalBase + `census-${prefix}.json`);
        for (const code of censusCodes) if (index[code]) linkedCodes.add(index[code]);
    }
    const prefixes = [...new Set([...linkedCodes].map(code => code.slice(0, manifest.prefix_length || 2)))];
    const chunks = await Promise.all(prefixes.filter(prefix => entry.shards[prefix]).map(prefix => jsonFile(nationalBase + entry.shards[prefix].file)));
    const summaries = await jsonFile(nationalBase + entry.summaries);
    return {records: chunks.flatMap(chunk => chunk.records).filter(row => linkedCodes.has(row.lgd_village_code)), summaries,
        linked_villages: entry.linked_villages, complete: manifest.complete, withheld: [],
        source: {url: manifest.source_url, scheme: manifest.scheme, cycle, retrieved_at: manifest.retrieved_at}, method: manifest.method};
}

export async function loadSoilConstituencies(cycle = soilCycles[0]) {
    if (!soilCycles.includes(cycle)) throw Error('Unsupported soil cycle');
    const data = await jsonFile(`/maps/soil-constituencies-20261014/${cycle}.json`);
    if (data.cycle !== cycle) throw Error('Soil reporting cycle does not match');
    return data;
}

export function soilOverview(properties, data) {
    if (properties.subdistrict_code || properties.lgd_districts?.length !== 1) return null;
    const parent = properties.lgd_districts[0];
    return data?.summaries?.districts?.[`${parent.state}:${parent.district}`] || null;
}
export function soilCycleControl(metric, onChange) {
    const label = document.createElement('label'); label.textContent = 'Soil cycle ';
    const select = document.createElement('select'); select.id = metric.id + '-soil-cycle'; label.htmlFor = select.id;
    for (const cycle of soilCycles) { const option = document.createElement('option'); option.value = cycle; option.textContent = cycle; select.append(option); }
    label.append(select); (metric.closest('label') || metric).after(label);
    const visibility = () => { label.hidden = !isSoil(metric.value); label.style.display = label.hidden ? 'none' : ''; };
    metric.addEventListener('change', visibility); select.addEventListener('change', onChange); visibility();
    return select;
}
export function linkSoil(records, data) {
    const byCode = new Map(data.records.map(row => [row.lgd_village_code, row]));
    return records.map(record => {
        const code = soilCode(record);
        const soil = code ? byCode.get(String(code)) : null;
        return {...record, soil, values: {...record.values, ...soilValues(soil)}};
    });
}
export function soilCode(record) {
    return record.level === 'TOWN' ? null : record.lgd_code || record.original_lgd_village_code || record.water?.lgd_village_code;
}
export function soilDisclaimer() {
    if (document.getElementById('soil-map-disclaimer')) return;
    const details = document.createElement('details'); details.id = 'soil-map-disclaimer'; details.className = 'panel card coverage';
    const summary = document.createElement('summary'); summary.textContent = 'Soil map sources, dates and coverage';
    const text = document.createElement('p'); text.textContent = 'Soil Health Card RKVY, cycles 2023–24 to 2026–27; downloaded 14 October 2026. Colours show tested samples for the selected cycle, not the percentage of village land. District colours use official district sample summaries; existing outlines are geographic context, not a certification of matching boundary editions. Village colours use only linked village observations. Each cycle is displayed separately: historical values are not substituted for current missing results. Villages without published records or verified links remain unavailable; no soil values are extrapolated. Official summaries and village reports can have different update times; totals are retained as reported. Original identifiers and polygons are retained.';
    const source = document.createElement('a'); source.href = 'https://soilhealth.dac.gov.in/nutrient-dashboard'; source.textContent = 'Official Soil Health Card source ↗'; source.target = '_blank'; source.rel = 'noopener';
    const electoral = document.createElement('p'); electoral.textContent = 'PC/AC colours sum reported samples only from villages with fully covered, unambiguous official LGD constituency membership. They are linked-sample summaries, not official constituency totals. Partial or conflicting membership, missing village reports and incomplete indicators are excluded; district results are never substituted.';
    details.append(summary, text, electoral, source); document.querySelector('main')?.append(details);
}
