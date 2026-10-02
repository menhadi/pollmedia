(() => {
    const root = document.querySelector('[data-electoral-pilot]');
    if (!root) return;
    const svg = root.querySelector('svg'), layer = root.querySelector('[data-layer]');
    const seats = root.querySelector('[data-seats]'), heading = root.querySelector('[data-selected]');
    const link = root.querySelector('[data-records]'), status = root.querySelector('[role=status]');
    const warning = root.querySelector('[data-geometry-warning]');
    const ns = 'http://www.w3.org/2000/svg';
    let features = [], requestVersion = 0;
    const sources = root.dataset.sources ? JSON.parse(root.dataset.sources) : {pc: root.dataset.source, ac: root.dataset.source};
    const state = root.dataset.state || 'Uttar Pradesh', cache = new Map();
    const project = ([x, y]) => [x * Math.cos(25 * Math.PI / 180) * 100, -y * 100];
    const label = p => p.name?.trim() || 'Name missing in source';
    function select(feature) {
        const p = feature.properties;
        heading.textContent = `${label(p)} · ${p.kind.toUpperCase()} ${p.code || 'Code missing'}`;
        const notes = p.review_notes || (p.geometry_warning ? [`Geometry: ${p.geometry_warning}`] : []);
        warning.hidden = !notes.length;
        warning.textContent = notes.join('. ') + (p.geometry_warning ? '. Original source coordinates are displayed.' : '');
        seats.value = String(feature.id);
        const url = new URL(root.dataset.finder, location.origin);
        url.search = new URLSearchParams({state, kind: p.kind, q: p.name || ''});
        link.href = url.href; link.hidden = !p.name?.trim();
        for (const path of svg.querySelectorAll('path')) path.setAttribute('aria-pressed', String(path.dataset.id === String(feature.id)));
    }
    function render() {
        svg.replaceChildren(); seats.replaceChildren();
        const visible = features.filter(f => f.properties.kind === layer.value).sort((a,b) => a.properties.code - b.properties.code);
        const placeholder = new Option('Choose a constituency', ''); seats.add(placeholder);
        const points = [];
        const counts = new Map(), occurrences = new Map();
        for (const f of visible) counts.set(f.properties.code, (counts.get(f.properties.code) || 0) + 1);
        for (const f of visible) {
            const p = f.properties, flagged = p.review_status === 'flagged' || !!p.geometry_warning;
            occurrences.set(p.code, (occurrences.get(p.code) || 0) + 1);
            const duplicateLabel = counts.get(p.code) > 1 ? ` · Source record ${occurrences.get(p.code)}` : '';
            seats.add(new Option(`${p.code || 'Code missing'} · ${label(p)}${duplicateLabel}${flagged ? ' · Needs review' : ''}`, String(f.id)));
            if (!f.geometry || !['Polygon', 'MultiPolygon'].includes(f.geometry.type)) continue;
            const polygons = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', polygons.map(polygon => polygon.map(ring => ring.map((p,i) => {
                const xy = project(p); points.push(xy); return `${i ? 'L' : 'M'}${xy[0].toFixed(2)},${xy[1].toFixed(2)}`;
            }).join(' ') + ' Z').join(' ')).join(' '));
            path.setAttribute('fill-rule', 'evenodd'); path.setAttribute('tabindex', '0'); path.setAttribute('role', 'button');
            path.setAttribute('aria-label', `${label(p)}, ${layer.value.toUpperCase()} ${p.code || 'code missing'}${flagged ? ', source needs review' : ''}`);
            path.dataset.code = String(f.properties.code);
            path.dataset.id = String(f.id);
            path.dataset.geometryStatus = f.properties.geometry_status;
            path.dataset.reviewStatus = flagged ? 'flagged' : 'unverified';
            const title = document.createElementNS(ns, 'title'); title.textContent = path.getAttribute('aria-label'); path.append(title);
            path.addEventListener('click', () => select(f));
            path.addEventListener('keydown', event => { if (['Enter',' '].includes(event.key)) {event.preventDefault(); select(f);} });
            svg.append(path);
        }
        if (!points.length) {
            svg.setAttribute('viewBox', '0 0 600 400');
            heading.textContent = 'No separate boundary layer'; link.hidden = true; warning.hidden = true;
            status.textContent = 'This source has no separate boundary shapes for the selected layer. Election records remain available in the directory.';
            return;
        }
        const bounds = points.reduce((b,p) => [Math.min(b[0],p[0]),Math.min(b[1],p[1]),Math.max(b[2],p[0]),Math.max(b[3],p[1])], [Infinity,Infinity,-Infinity,-Infinity]);
        const [left, top] = bounds, width = bounds[2]-left, height = bounds[3]-top;
        svg.setAttribute('viewBox', `${left-5} ${top-5} ${width+10} ${height+10}`);
        heading.textContent = 'Select a constituency'; link.hidden = true;
        warning.hidden = true;
        const flaggedCount = visible.filter(f => f.properties.review_status === 'flagged' || f.properties.geometry_warning).length;
        status.textContent = `${visible.length} ${layer.value.toUpperCase()} source records · ${flaggedCount} marked for review. Source records are not a current seat count.`;
        if (state === 'Uttar Pradesh' && layer.value === 'pc') {const pilibhit = visible.find(f => f.properties.code === 26); if (pilibhit) select(pilibhit);}
    }
    async function loadLayer() {
        const version = ++requestVersion, url = sources[layer.value];
        seats.disabled = true; svg.replaceChildren(); link.hidden = true; warning.hidden = true;
        status.textContent = 'Loading source boundaries…';
        try {
            if (!cache.has(url)) {
                const response = await fetch(url);
                if (!response.ok) throw new Error();
                cache.set(url, await response.json());
            }
            if (version !== requestVersion) return;
            features = cache.get(url).features.map((f, index) => ({...f, id: f.id ?? `${f.properties.kind}-${index}`}));
            render(); seats.disabled = false;
        } catch {
            if (version !== requestVersion) return;
            status.textContent = 'The boundary preview could not load. Use the constituency directory below.';
        } finally {
            if (version === requestVersion) layer.disabled = false;
        }
    }
    layer.addEventListener('change', loadLayer);
    seats.addEventListener('change', () => {const f = features.find(f => f.properties.kind === layer.value && String(f.id) === seats.value); if (f) select(f);});
    loadLayer();
})();
