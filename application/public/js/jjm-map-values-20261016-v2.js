export const isJjm = key => key === 'water' || key === 'water_households' || key === 'water_taps';
export function jjmValues(row) {
    const number = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
    return {water: number(row?.coverage_pct), water_households: number(row?.households), water_taps: number(row?.tap_connections)};
}
export function jjmRecords(rows) {
    return rows.map(row => ({...row, values: {...row.values, ...jjmValues(row.water)}}));
}
export function addJjmMeasures(metric) {
    for (const [key, title] of [['water_households', 'JJM households'], ['water_taps', 'JJM household tap connections']]) {
        if (![...metric.options].some(option => option.value === key)) metric.add(new Option(title, key));
    }
}
export const jjmNote = 'JJM snapshot retrieved 13–14 October 2026 · linked rural records only; missing links remain unavailable. These are not Census 2001/2011 values.';

export const jjmMissingNote = feature => feature?.water_note || (feature?.lgd_code ? 'JJM unavailable: no accepted stored JJM observation for this village.' : 'JJM unavailable: no unique official village link for this polygon.');
