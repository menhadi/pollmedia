export const placeGroups = ['Overview','Places','Map','Satellite','Water','Panchayats','Soil','Rainfall','Mandi','Authorities','Facts','Graphs','Elections','Related','Sources'];

export function compactCountValue(value) {
    return String(value).replace(/^(\s*[-+]?\d[\d,]*(?:\.\d+)?)\s+(?:people|households)\s*$/i,'$1');
}

export function valueBarAppearance(value, maximum = 100) {
    if (value === null || value === undefined || String(value).trim() === '' || !Number.isFinite(Number(value)) || !Number.isFinite(Number(maximum)) || Number(maximum) <= 0) return null;
    const percent = Math.max(0, Math.min(100, Number(value) / Number(maximum) * 100));
    const colours = ['var(--chart-series-2,#573451)', 'var(--chart-series-3,#825026)', 'var(--chart-series-1,#155748)', 'var(--chart-series-4,#314766)'];
    return {colour: colours[Math.min(3, Math.floor(percent / 25))], strength: Math.round(45 + percent * .55)};
}

export function groupForSection(id = '', label = '') {
    const text = `${id} ${label}`.toLowerCase();
    if (/overview|baseline|at-a-glance|highlights/.test(text)) return 'Overview';
    if (/approved-place-explorer/.test(text)) return 'Places';
    if (/satellite|ndvi|vegetation|उपग्रह/.test(text)) return 'Satellite';
    if (/groundwater|chemistry|drinking.water|water-evidence|jjm|tap connection|village-water|^water|पेयजल|भूजल/.test(text)) return 'Water';
    if (/soil|मृदा|मिट्टी/.test(text)) return 'Soil';
    if (/rainfall|वर्षा/.test(text)) return 'Rainfall';
    if (/mandi|market|मंडी/.test(text)) return 'Mandi';
    if (/authorit|representative|public office|प्राधिक|अधिकारी|प्रतिनिधि/.test(text)) return 'Authorities';
    if (/related|explore further|explore this area|connected places/.test(text)) return 'Related';
    if (/methodolog|disclaimer|sources?|coverage|reference|पद्धति|स्रोत/.test(text)) return 'Sources';
    if (/election|constituenc|year-detail|party|turnout|result|candidate|seat|vote|voting|lok sabha|assembly|चुनाव|निर्वाचन/.test(text)) return 'Elections';
    if (/(?:^|[-\s])map(?:$|[-\s])|polygons|location|मानचित्र/.test(text)) return 'Map';
    if (/history|graph|chart/.test(text)) return 'Graphs';
    return 'Facts';
}

export function pageSlice(rows, page, size) {
    const pages = Math.max(1, Math.ceil(rows.length / size));
    const current = Math.max(1, Math.min(page, pages));
    const start = (current - 1) * size;
    return { page: current, pages, total: rows.length, start: rows.length ? start + 1 : 0, end: Math.min(start + size, rows.length), rows: rows.slice(start, start + size) };
}

export function compactReference(text) {
    return String(text).replace(/^Census\s+(\d{4})(?:\s*[·|]\s*(?:Total|Urban|Rural))?$/i, '$1');
}

export function editableNumber(text, column = '') {
    if (/\byear\b|\bcode\b|\bid\b|rank|position|serial|source|date|edition|क्रम|वर्ष|कोड|तिथि/i.test(column)) return false;
    return /^[-+−]?\s*[₹$]?\s*\d[\d,.\s]*(?:%|\s*(?:m|mm|km²|ha|mg\/L))?$/.test(String(text).trim());
}

export function panelCanShareRow({columns = [], charts = 0, map = false} = {}) {
    return !map && columns.length <= 1 && columns.every(count => count <= 4) && charts <= 1;
}

export function concisePageLink(text) {
    const labels = {'Map & filters':'Map','Quick facts':'Facts','Water, soil & rainfall':'Environment','History & graphs':'History','Lok Sabha & State Assembly':'Elections','Methodology & disclaimer':'Methodology and disclaimer'};
    return labels[String(text).trim()] || text;
}

export function uniquePlaceLinks(links, currentUrl) {
    const current = new URL(currentUrl);
    const seen = new Set();
    const pathOf = url => url.pathname.replace(/\/+$/, '').toLowerCase();
    const placePath = /^\/india\/(constituencies\/(pc|ac)|pc|ac|districts?|states?|subdistricts?|villages?|towns|census\/(states|districts|subdistricts|villages|towns))\/(.+)/i;
    const identity = match => match ? [(match[2] || match[3] || match[1]).replace(/s$/, '').toLowerCase(),match[4].replace(/\/+$/, '').toLowerCase()].join('|') : null;
    const currentIdentity = identity(current.pathname.match(placePath));
    const result = [];
    for (const link of links) {
        let url;
        try { url = new URL(link.href, current); } catch { continue; }
        if (url.origin !== current.origin || pathOf(url) === pathOf(current)) continue;
        const match = url.pathname.match(placePath);
        if (!match || /\/(?:source-tables|records)\//.test(url.pathname)) continue;
        const kind = match[2] || match[3] || match[1];
        const type = /pc|ac/.test(kind) ? kind.toUpperCase() : kind.replace(/s$/, '');
        const label = String(link.label || '').replace(/[▲▼↗→]/g, '').replace(/\s*·\s*(?:AC|PC)\s*\d+/gi,'').replace(/\s+/g, ' ').trim();
        if (!label) continue;
        if (/State population, literacy|Census context|Historical graphs|More places in/i.test(label)) continue;
        const key = identity(match);
        if (key === currentIdentity || seen.has(key)) continue;
        seen.add(key);
        url.hash = '';
        result.push({href: url.href, label, type});
    }
    return result;
}

export function summaryFingerprint(label, value, period, scope) {
    const name = String(label).toLowerCase().replace(/\s+/g,' ').trim();
    const metric = /^population$/.test(name) ? 'population' : /^households$/.test(name) ? 'households' : /^literacy(?: rate)?(?: age 7\+| \(age 7\+\))?$/.test(name) ? 'literacy' : null;
    const year = String(period).match(/\b(?:18|19|20)\d{2}\b/)?.[0];
    const number = String(value).replace(/,/g,'').match(/[-+]?\d+(?:\.\d+)?/)?.[0];
    if (!metric || !year || number === undefined || !scope || /unavailable|not available|—/i.test(value)) return null;
    return [metric,Number(number),year,String(scope).toLowerCase()].join('|');
}

