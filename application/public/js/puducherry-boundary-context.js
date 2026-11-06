export async function addBoundaryContext({section, svg, group, project}) {
    if (!section.dataset.boundaryContext) return;
    const status = document.getElementById('boundary-context-status');
    try {
        const response = await fetch(section.dataset.boundaryContext);
        if (!response.ok) throw new Error('Boundary context unavailable');
        const context = await response.json();
        const make = (tag, attrs = {}) => {
            const element = document.createElementNS(svg.namespaceURI, tag);
            for (const [key, value] of Object.entries(attrs)) element.setAttribute(key, value);
            return element;
        };
        const defs = make('defs');
        const pattern = make('pattern', {id:'boundary-disagreement', width:7, height:7, patternUnits:'userSpaceOnUse'});
        pattern.append(make('path', {d:'M0 7L7 0', stroke:'#a35e15', 'stroke-width':1.4}));
        defs.append(pattern); svg.append(defs);
        const background = make('g', {'data-boundary-background':'', 'pointer-events':'none'});
        const foreground = make('g', {'data-boundary-disagreement':'', 'pointer-events':'none'});
        group.prepend(background); group.append(foreground);
        const rings = geometry => geometry.type === 'Polygon' ? geometry.coordinates : geometry.coordinates.flat();
        for (const feature of context.features) {
            const kind = feature.properties.kind;
            const path = make('path', {
                d:rings(feature.geometry).map(ring => ring.map((point,index) => `${index?'L':'M'}${project(point).join(',')}`).join(' ')+'Z').join(' '),
                'fill-rule':'evenodd',
                fill:kind==='tamil-nadu'?'#d3d8de':kind==='outline'?'none':'url(#boundary-disagreement)',
                stroke:kind==='outline'?'#374151':'none',
                'stroke-width':kind==='outline'?1.1:0,
                'stroke-dasharray':kind==='outline'?'5 3':'none',
                'data-boundary-kind':kind
            });
            const title = make('title'); title.textContent = feature.properties.name; path.append(title);
            (kind==='tamil-nadu'?background:foreground).append(path);
        }
        const toggle = document.getElementById('boundary-context-toggle');
        toggle.addEventListener('change', () => {
            background.style.display = foreground.style.display = toggle.checked ? '' : 'none';
        });
        status.textContent = 'Tamil Nadu context loaded from the SOI state boundary. Hatched areas mark source disagreements or uncovered Puducherry outline areas; they are not verified missing villages.';
    } catch {
        status.textContent = 'Boundary context could not load. White gaps must not be interpreted as missing villages; consult the linked official map.';
    }
}
