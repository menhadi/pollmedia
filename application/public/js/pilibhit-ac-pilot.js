export const statusLabels = {linked_inside: 'Directory linked · map agrees', linked_review: 'Directory linked · map review', map_candidate: 'Map candidate · link pending'};
export function filterRows(rows, query = '', status = 'all', reason = 'all') {
    const q = query.trim().toLocaleLowerCase();
    return rows.filter(row => (status === 'all' || row.status === status) && (reason === 'all' || row.review_reason === reason) && `${row.name} ${row.code}`.toLocaleLowerCase().includes(q))
        .sort((a, b) => a.name.localeCompare(b.name));
}
export function literacy(row) {
    const denominator = row.census.population - row.census.population_0_6;
    return denominator > 0 ? 100 * row.census.literate / denominator : null;
}
const number = value => new Intl.NumberFormat('en-IN').format(value);
const element = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
};

async function start(root) {
    const response = await fetch(root.dataset.source);
    if (!response.ok) throw new Error('Evidence package unavailable');
    const data = await response.json();
    const q = document.getElementById('q'), status = document.getElementById('status');
    const reason = document.getElementById('reason');
    const tbody = document.getElementById('village-rows'), pager = document.getElementById('pagination');
    const svg = document.getElementById('village-map'), detail = document.getElementById('place-detail');
    const paths = new Map(), byCode = new Map(data.rows.map(row => [row.code, row]));
    let page = Math.max(1, Number(new URL(location.href).searchParams.get('page')) || 1), selected = null, zoom = 1;
    let matches = [];
    const ns = 'http://www.w3.org/2000/svg';
    const svgNode = (tag, attrs = {}) => {
        const node = document.createElementNS(ns, tag);
        for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
        return node;
    };
    const rings = geometry => geometry.type === 'Polygon' ? geometry.coordinates : geometry.coordinates.flat();
    const all = [data.ac.geometry, ...data.rows.filter(row => row.geometry).map(row => row.geometry)].flatMap(rings).flat();
    const minX = Math.min(...all.map(point => point[0])), maxX = Math.max(...all.map(point => point[0]));
    const minY = Math.min(...all.map(point => point[1])), maxY = Math.max(...all.map(point => point[1]));
    const cosine = Math.cos((minY + maxY) * Math.PI / 360);
    const scale = Math.min(700 / ((maxX - minX) * cosine), 460 / (maxY - minY));
    const offsetX = (760 - (maxX - minX) * cosine * scale) / 2, offsetY = (540 - (maxY - minY) * scale) / 2;
    const project = ([x, y]) => [offsetX + (x - minX) * cosine * scale, offsetY + (maxY - y) * scale];
    const pathData = geometry => rings(geometry).map(ring => ring.map((point, i) => `${i ? 'L' : 'M'}${project(point).join(',')}`).join(' ') + 'Z').join(' ');
    const group = svgNode('g'); svg.append(group);
    for (const row of data.rows) {
        if (!row.geometry) continue;
        const path = svgNode('path', {d: pathData(row.geometry), fill: {linked_inside: '#388565', linked_review: '#d5a35b', map_candidate: '#789ab8'}[row.status],
            class: 'village-shape', 'fill-rule': 'evenodd', tabindex: '0', role: 'button', 'aria-label': `${row.name}, ${statusLabels[row.status]}`});
        path.append(svgNode('title')); path.firstChild.textContent = row.name;
        path.addEventListener('click', () => select(row.code));
        path.addEventListener('keydown', event => {if (event.key === 'Enter' || event.key === ' ') {event.preventDefault(); select(row.code);}});
        group.append(path); paths.set(row.code, path);
    }
    group.append(svgNode('path', {d: pathData(data.ac.geometry), class: 'ac-outline', 'fill-rule': 'evenodd'}));
    document.getElementById('map-message').textContent = 'Select a village for evidence. The dark line is the existing AC outline; colours show the relationship checks.';
    function sourceLink(label, url) {
        const link = element('a', label); link.href = url; link.target = '_blank'; link.rel = 'noopener'; return link;
    }
    function select(code) {
        selected = code;
        const row = byCode.get(code); if (!row) return;
        for (const [key, path] of paths) {path.classList.toggle('selected', key === code); path.setAttribute('aria-pressed', key === code ? 'true' : 'false');}
        detail.replaceChildren(element('p', 'Village evidence', 'eyebrow'), element('h3', row.name), element('p', `${row.code} · ${statusLabels[row.status]}`, 'status'));
        const dl = element('dl'), values = {'Population · 2011': number(row.census.population), Households: number(row.census.households),
            'Literacy · age 7+': literacy(row) === null ? 'Unavailable' : `${literacy(row).toFixed(1)}%`,
            'Land inside AC': row.land_overlap_percent === null ? 'Unavailable' : `${row.land_overlap_percent.toFixed(2)}%`,
            'Map finding': row.spatial_status.replaceAll('_', ' ')};
        for (const [label, value] of Object.entries(values)) dl.append(element('dt', label), element('dd', value));
        detail.append(dl, element('p', 'Land overlap is diagnostic. It is never used to split population.', 'muted'));
        if (row.linked) {
            detail.append(element('h3', 'Directory evidence'));
            for (const link of row.directory_links) detail.append(element('p', `LGD ${link.lgd_code} · ${link.subdistrict} · official electoral report row(s) ${link.source_rows.join(', ')}`));
            detail.append(sourceLink('Official LGD electoral report ↗', data.links.electoral));
        } else detail.append(element('p', 'The map overlaps this village, but an accepted AC 127 directory assignment is missing from this package. Census counts are excluded from the linked baseline.'));
        detail.append(element('h3', 'Polling-area evidence'));
        if (row.booths.length) {
            detail.append(element('p', `Reviewed named-area references: booth(s) ${row.booths.join(', ')}. These do not independently prove whole-village coverage.`), sourceLink('Approved coverage table · page 2 ↗', `${data.links.final_stations}#page=2`));
        } else detail.append(element('p', 'No reviewed polling-area link is attached yet. An absent link does not mean this village has no polling station.'));
        if (row.review_reason === 'polling_directory_conflict') detail.append(element('p', `Source conflict: a visually reviewed AC 127 polling-area reference names this village, while the imported LGD report assigns it to ${row.directory_ac.join(', ')}. Membership remains pending resolution.`, 'notice'));
        if (row.vision_suggestions?.length) {
            detail.append(element('h3', 'Vision suggestions · unaccepted'));
            for (const suggestion of row.vision_suggestions.slice(0, 5)) {
                detail.append(element('p', `Booth ${suggestion.booth}: ${suggestion.name_hi} → ${row.name}${suggestion.partial ? ' · partial booth coverage' : ''}`),
                    sourceLink(`Inspect PDF page ${suggestion.pdf_page} ↗`, `${data.links.final_stations}#page=${suggestion.pdf_page}`));
            }
        }
        const villagePath = `/india/village/${code}-${row.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}`;
        detail.append(sourceLink('Open Census village profile ↗', new URL(villagePath, data.links.constituency).href));
        const url = new URL(location.href); url.hash = `village-${code}`; history.replaceState(null, '', url);
    }
    function render() {
        matches = filterRows(data.rows, q.value, status.value, reason.value);
        const totalPages = Math.max(1, Math.ceil(matches.length / 25)); page = Math.min(page, totalPages);
        tbody.replaceChildren();
        for (const row of matches.slice((page - 1) * 25, page * 25)) {
            const tr = element('tr'), name = element('td'), button = element('button', row.name, 'village-link'); button.type = 'button'; button.addEventListener('click', () => select(row.code));
            name.append(button, element('small', row.code));
            const state = element('td'); state.append(element('span', statusLabels[row.status], `badge ${row.status}`));
            tr.append(name, state, element('td', number(row.census.population)), element('td', literacy(row) === null ? 'Unavailable' : `${literacy(row).toFixed(1)}%`),
                element('td', row.land_overlap_percent === null ? 'Unavailable' : `${row.land_overlap_percent.toFixed(2)}%`)); tbody.append(tr);
        }
        if (!matches.length) {const tr = element('tr'), td = element('td', 'No villages match these filters.'); td.colSpan = 5; tr.append(td); tbody.append(tr);}
        document.getElementById('result-count').textContent = `${matches.length} matching places · page ${page} of ${totalPages}`;
        pager.replaceChildren();
        for (const [label, step] of [['Previous', -1], ['Next', 1]]) {
            const button = element('button', label); button.type = 'button'; button.disabled = step < 0 ? page <= 1 : page >= totalPages;
            button.addEventListener('click', () => {page += step; render();}); pager.append(button);
        }
        const codes = new Set(matches.map(row => row.code));
        for (const [code, path] of paths) {path.classList.toggle('dimmed', !codes.has(code)); path.setAttribute('tabindex', codes.has(code) ? '0' : '-1');}
        const url = new URL(location.href); url.searchParams.delete('format'); url.searchParams.delete('page');
        q.value ? url.searchParams.set('q', q.value) : url.searchParams.delete('q');
        status.value !== 'all' ? url.searchParams.set('status', status.value) : url.searchParams.delete('status');
        reason.value !== 'all' ? url.searchParams.set('reason', reason.value) : url.searchParams.delete('reason');
        if (page > 1) url.searchParams.set('page', page);
        history.replaceState(null, '', url);
        url.searchParams.set('format', 'csv'); url.hash = ''; document.getElementById('download').href = url.href;
    }
    document.getElementById('filters').addEventListener('submit', event => {event.preventDefault(); page = 1; render();});
    q.addEventListener('input', () => {page = 1; render();}); status.addEventListener('change', () => {page = 1; render();});
    reason.addEventListener('change', () => {page = 1; render();});
    document.getElementById('reset').addEventListener('click', () => {q.value = ''; status.value = 'all'; reason.value = 'all'; page = 1; render();});
    document.getElementById('zoom-in').addEventListener('click', () => {zoom = Math.min(4, zoom * 1.4); setZoom();});
    document.getElementById('zoom-out').addEventListener('click', () => {zoom = Math.max(1, zoom / 1.4); setZoom();});
    document.getElementById('fit-map').addEventListener('click', () => {zoom = 1; setZoom();});
    function setZoom() {
        const points = selected && byCode.get(selected)?.geometry ? rings(byCode.get(selected).geometry).flat().map(project) : [[380, 270]];
        const x = points.reduce((sum, point) => sum + point[0], 0) / points.length, y = points.reduce((sum, point) => sum + point[1], 0) / points.length;
        const width = 760 / zoom, height = 540 / zoom;
        svg.setAttribute('viewBox', `${zoom === 1 ? 0 : Math.max(0, Math.min(760 - width, x - width / 2))} ${zoom === 1 ? 0 : Math.max(0, Math.min(540 - height, y - height / 2))} ${width} ${height}`);
    }
    const boothSearch = document.getElementById('booth-search'), boothList = document.getElementById('booth-list');
    function showBooths() {
        const query = boothSearch.value.trim().toLocaleLowerCase();
        const stations = data.stations.filter(station => `${station.number} ${station.name} ${(station.coverage_names || []).join(' ')} ${(station.vision?.areas || []).map(a => `${a.name_hi || ''} ${a.name_latin || ''} ${['unique_name_suggestion','probable_name_suggestion'].includes(a.match_state) ? a.candidates.slice(0,1).map(c => c.name).join(' ') : ''}`).join(' ')}`.toLocaleLowerCase().includes(query));
        boothList.replaceChildren();
        for (const station of stations.slice(0, 20)) {
            const article = element('article'); article.append(element('strong', `Booth ${station.number} · ${station.name}`));
            if (station.coverage_review !== 'not_reviewed') article.append(element('p', `Reviewed named areas: ${station.coverage_names.join(', ')}`), sourceLink('Approved coverage table · page 2 ↗', `${data.links.final_stations}#page=2`));
            else article.append(element('p', 'Building name captured. Census identities have not been accepted.'), sourceLink('Official SIR index ↗', data.links.stations));
            if (station.unresolved_names?.length) article.append(element('p', `Ambiguous Census identity: ${station.unresolved_names.join(', ')}`));
            if (station.vision) {
                article.append(element('p', `Vision read · physical PDF page ${station.vision.pdf_page} · suggestions only`, 'eyebrow'));
                const list = element('ul');
                for (const area of station.vision.areas) {
                    const candidate = area.candidates[0];
                    const suggestion = area.match_state === 'ambiguous_name' ? 'Ambiguous: ' + area.candidates.map(c => `${c.name} (${c.code})`).join('; ')
                        : candidate ? candidate.name + ' (' + candidate.code + ')' : 'No rural village suggestion';
                    list.append(element('li', `${area.name_hi || 'Unreadable'}${area.partial ? ' · partial booth area' : ''} → ${suggestion} · ${area.match_state.replaceAll('_', ' ')}`));
                }
                article.append(list, sourceLink(`Inspect coverage page ${station.vision.pdf_page} ↗`, `${data.links.final_stations}#page=${station.vision.pdf_page}`));
            }
            boothList.append(article);
        }
        boothList.prepend(element('p', `${stations.length} booth references match · showing up to 20.`, 'muted'));
    }
    boothSearch.addEventListener('input', showBooths); showBooths(); render();
    for (const link of document.querySelectorAll('.review-village')) link.addEventListener('click', event => {event.preventDefault(); select(link.dataset.code); document.getElementById('places').scrollIntoView({behavior:'smooth'});});
    const code = location.hash.match(/^#village-(\d+)$/)?.[1]; if (code && byCode.has(code)) select(code);
}
if (typeof document !== 'undefined') {
    const root = document.getElementById('ac-pilot');
    if (root) start(root).catch(() => {document.getElementById('map-message').textContent = 'The interactive map could not load. Use the directory, filters and CSV export below.';});
}
