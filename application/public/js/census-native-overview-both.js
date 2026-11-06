export const isNativeCensusField = field => /^(vd_|pca_|c11_)/.test(field);

export function nativeVillageColours(field, breaks, colours) {
    const value=['feature-state',field];
    return ['case',['==',value,null],'#9ca3af',['==',['typeof',value],'string'],'#547ae5',
        ['step',['to-number',value,0],colours[0],...breaks.flatMap((v,i)=>[v,colours[i+1]])]];
}

export function nativeGroupKey(properties) {
    if (properties.subdistrict_code) return `${properties.state_code}:${properties.district_code}:${properties.subdistrict_code}`;
    const parents = properties.lgd_districts || [];
    return parents.length === 1 ? `${parents[0].state}:${parents[0].district}:` : null;
}

export function nativeSummary(properties, data) {
    return data?.groups?.[nativeGroupKey(properties)] || null;
}

export function nativeOverviewValue(summary, kind) {
    if (!summary || !summary[0]) return null;
    if (kind === 'category') return summary[1] ? 100 * summary[1] / summary[0] : null;
    if (!summary[2]) return null;
    return kind === 'mean' ? summary[3] / summary[2] : summary[3];
}

export function nativeSummaryText(summary, descriptor) {
    if (!summary) return 'No linked published values for this boundary.';
    const [records, reported, numeric, sum, distribution, other] = summary;
    const value = nativeOverviewValue(summary, descriptor.kind);
    const meaning = descriptor.kind === 'sum' ? 'Sum of original numeric values' : descriptor.kind === 'mean' ? 'Mean of reported original numeric values' : 'Records with a reported value (%) — not facility availability';
    const values = Object.entries(distribution).map(([raw, count]) => `${JSON.parse(raw)}: ${count} records`).join('; ');
    return `${meaning}: ${value === null ? 'Unavailable' : Number(value.toFixed(2)).toLocaleString('en-IN')}. ${reported} reported, ${records - reported} missing, ${records} linked records. ${descriptor.kind === 'category' ? values + (other ? `; ${other} records with other original values` : '') : `${numeric} numeric records contribute.`} Current parent geography; historical boundaries may differ. Original doubtful values retained.`;
}

export function nativeBreaks(values) {
    const sorted = [...new Set(values.filter(v => typeof v === 'number' && Number.isFinite(v)))].sort((a,b) => a-b);
    if (!sorted.length) return [1,5,10,50];
    const cuts = [0.2,0.4,0.6,0.8].map(q => sorted[Math.min(sorted.length-1,Math.floor(q*sorted.length))]);
    return cuts.every((v,i) => !i || v > cuts[i-1]) ? cuts : [1,5,10,50];
}
