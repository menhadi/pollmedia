export function facilityDistribution(properties, data, field) {
    const parents = properties.lgd_districts || [];
    const key = properties.subdistrict_code
        ? `${properties.state_code}:${properties.district_code}:${properties.subdistrict_code}`
        : parents.length === 1 ? `${parents[0].state}:${parents[0].district}:` : null;
    if (!key || data?.year !== 2001) return null;
    const group = data.groups?.[key], values = group?.fields?.[field];
    if (!values) return null;
    return {records: group.records, reported: values.reported, missing: values.missing,
        distribution: Object.entries(values.raw_distribution).map(([raw, records]) => ({value: JSON.parse(raw), records}))};
}

export function facilityDistributionText(summary) {
    if (!summary) return 'Census 2001 source distribution unavailable.';
    const values = summary.distribution.map(item => `${JSON.stringify(item.value)}: ${item.records} villages`).join('; ');
    return `Census 2001 original codes/values — ${values}. ${summary.reported} reported, ${summary.missing} missing, ${summary.records} linked records. Codes are not household water percentages. Current parent geography; historical boundaries may differ.`;
}

export function schoolOverviewTotal(summary, field) {
    if (!summary || !['P_SCH','M_SCH','S_SCH','S_S_SCH','COLLEGE'].includes(field)) return null;
    let total = 0, reported = 0;
    for (const item of summary.distribution) {
        if (typeof item.value !== 'number' || !Number.isFinite(item.value)) continue;
        total += item.value * item.records;
        reported += item.records;
    }
    return reported ? total : null;
}
