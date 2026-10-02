(() => {
    const root = document.querySelector('[data-electoral-pilot]');
    if (!root) return;
    const svg = root.querySelector('svg'), layer = root.querySelector('[data-layer]');
    const seats = root.querySelector('[data-seats]'), heading = root.querySelector('[data-selected]');
    const link = root.querySelector('[data-records]'), status = root.querySelector('[role=status]');
    const warning = root.querySelector('[data-geometry-warning]');
    const ns = 'http://www.w3.org/2000/svg';
    let features = [];
    const project = ([x, y]) => [(x - 77) * Math.cos(27 * Math.PI / 180) * 100, (31 - y) * 100];
    function select(feature) {
        const p = feature.properties;
        heading.textContent = `${p.name} · ${p.kind.toUpperCase()} ${p.code}`;
        warning.hidden = !p.geometry_warning;
        warning.textContent = p.geometry_warning ? `Boundary geometry needs review: ${p.geometry_warning}. Original source coordinates are displayed.` : '';
        seats.value = String(p.code);
        const url = new URL(root.dataset.finder, location.origin);
        url.search = new URLSearchParams({state: 'Uttar Pradesh', kind: p.kind, q: p.name});
        link.href = url.href; link.hidden = false;
        for (const path of svg.querySelectorAll('path')) path.setAttribute('aria-pressed', String(path.dataset.code === String(p.code)));
    }
    function render() {
        svg.replaceChildren(); seats.replaceChildren();
        const visible = features.filter(f => f.properties.kind === layer.value).sort((a,b) => a.properties.code - b.properties.code);
        const placeholder = new Option('Choose a constituency', ''); seats.add(placeholder);
        const points = [];
        for (const f of visible) {
            seats.add(new Option(`${f.properties.code} · ${f.properties.name}${f.properties.geometry_warning ? ' · Boundary needs review' : ''}`, String(f.properties.code)));
            const polygons = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', polygons.map(polygon => polygon.map(ring => ring.map((p,i) => {
                const xy = project(p); points.push(xy); return `${i ? 'L' : 'M'}${xy[0].toFixed(2)},${xy[1].toFixed(2)}`;
            }).join(' ') + ' Z').join(' ')).join(' '));
            path.setAttribute('fill-rule', 'evenodd'); path.setAttribute('tabindex', '0'); path.setAttribute('role', 'button');
            path.setAttribute('aria-label', `${f.properties.name}, ${layer.value.toUpperCase()} ${f.properties.code}${f.properties.geometry_warning ? ', boundary needs review' : ''}`);
            path.dataset.code = String(f.properties.code);
            path.dataset.geometryStatus = f.properties.geometry_status;
            const title = document.createElementNS(ns, 'title'); title.textContent = path.getAttribute('aria-label'); path.append(title);
            path.addEventListener('click', () => select(f));
            path.addEventListener('keydown', event => { if (['Enter',' '].includes(event.key)) {event.preventDefault(); select(f);} });
            svg.append(path);
        }
        const bounds = points.reduce((b,p) => [Math.min(b[0],p[0]),Math.min(b[1],p[1]),Math.max(b[2],p[0]),Math.max(b[3],p[1])], [Infinity,Infinity,-Infinity,-Infinity]);
        const [left, top] = bounds, width = bounds[2]-left, height = bounds[3]-top;
        svg.setAttribute('viewBox', `${left-5} ${top-5} ${width+10} ${height+10}`);
        heading.textContent = 'Select a constituency'; link.hidden = true;
        warning.hidden = true;
        status.textContent = `${visible.length} ${layer.value.toUpperCase()} shapes available in this preview.`;
        if (layer.value === 'pc') {const pilibhit = visible.find(f => f.properties.code === 26); if (pilibhit) select(pilibhit);}
    }
    layer.addEventListener('change', render);
    seats.addEventListener('change', () => {const f = features.find(f => f.properties.kind === layer.value && String(f.properties.code) === seats.value); if (f) select(f);});
    fetch(root.dataset.source).then(r => {if (!r.ok) throw new Error(); return r.json();}).then(data => {
        features = data.features; render(); layer.disabled = false; seats.disabled = false;
    }).catch(() => {status.textContent = 'The boundary preview could not load. Use the constituency directory below.';});
})();
