(() => {
    const section = document.getElementById('geography');
    if (!section) return;
    const status = document.getElementById('map-status');
    const layer = document.getElementById('map-layer');
    const search = document.getElementById('map-search');
    const results = document.getElementById('map-results');
    const details = document.getElementById('map-selection');
    let map, collection, selectedId;
    const extent = [[79.621636, 28.100844], [80.449971, 28.887806]];
    const fit = bounds => map?.fitBounds(bounds, {padding: 30, duration: 0});
    function updateLayer() {
        if (!map?.getLayer('villages-fill')) return;
        for (const id of ['villages-fill', 'villages-line']) {
            map.setLayoutProperty(id, 'visibility', layer.value === 'villages' ? 'visible' : 'none');
        }
    }
    function selectVillage(feature) {
        const p = feature.properties;
        details.replaceChildren();
        const heading = document.createElement('h3'); heading.textContent = p.name; details.append(heading);
        const list = document.createElement('dl');
        for (const [label, value] of [['Source code', p.source_code], ['Source subdistrict', p.subdistrict], ['Source category', p.category]]) {
            const term = document.createElement('dt'); term.textContent = label;
            const detail = document.createElement('dd'); detail.textContent = value || 'Not supplied';
            list.append(term, detail);
        }
        details.append(list);
        const pageLink = document.createElement('a');
        pageLink.href = '/search?' + new URLSearchParams({q: p.name, kind: 'village'});
        pageLink.textContent = 'Find matching village pages →';
        details.append(pageLink);
        const note = document.createElement('p'); note.textContent = 'Census figures, MP/MLA and service responsibilities: village mapping pending verification.'; details.append(note);
        if (map?.getSource('villages')) {
            if (selectedId !== undefined) map.setFeatureState({source: 'villages', id: selectedId}, {selected: false});
            selectedId = feature.id;
            map.setFeatureState({source: 'villages', id: selectedId}, {selected: true});
            layer.value = 'villages'; updateLayer();
            fit([[feature.bbox[0], feature.bbox[1]], [feature.bbox[2], feature.bbox[3]]]);
        }
    }
    function renderResults() {
        results.replaceChildren();
        const query = search.value.trim().toLowerCase();
        if (!query || !collection) return;
        const matches = collection.features.filter(f => `${f.properties.name} ${f.properties.source_code}`.toLowerCase().includes(query));
        for (const feature of matches.slice(0, 20)) {
            const button = document.createElement('button'); button.type = 'button';
            button.textContent = `${feature.properties.name} · ${feature.properties.source_code}`;
            button.addEventListener('click', () => selectVillage(feature)); results.append(button);
        }
        const note = document.createElement('p');
        note.textContent = matches.length ? `${matches.length} matches${matches.length > 20 ? '; first 20 shown. Refine your search.' : '.'}` : 'No source villages match.';
        results.append(note);
    }
    search.addEventListener('input', renderResults);
    layer.addEventListener('change', updateLayer);
    document.getElementById('map-reset').addEventListener('click', () => fit(extent));
    document.getElementById('map-expand').addEventListener('click', event => {
        const expanded = section.classList.toggle('map-expanded');
        event.target.setAttribute('aria-expanded', String(expanded));
        event.target.textContent = expanded ? 'Collapse map' : 'Expand map'; map?.resize();
    });
    const data = fetch('/api/maps/pilibhit-villages').then(response => {
        if (!response.ok) throw new Error('Village map unavailable');
        return response.json();
    }).then(value => {collection = value; search.disabled = false; return value;});
    // Attach a handler immediately, even when WebGL or the library is unavailable.
    data.catch(() => {status.textContent = 'Village layer unavailable. You can still explore the street map and open the official source.';});
    try {
        map = new maplibregl.Map({container: 'place-map', center: [80.04, 28.49], zoom: 9,
            attributionControl: {compact: false}, scrollZoom: false,
            style: {version: 8, sources: {streets: {type: 'raster', tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'], tileSize: 256,
                attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors'}},
                layers: [{id: 'streets', type: 'raster', source: 'streets'}]}});
        map.addControl(new maplibregl.NavigationControl({showCompass: false}), 'top-right');
        fit(extent);
        map.on('error', event => {
            if (event.sourceId === 'streets') status.textContent = 'Street tiles unavailable. Loaded village shapes and search remain usable.';
        });
        map.on('load', async () => {
            try {
                const villages = await data;
                map.addSource('villages', {type: 'geojson', data: villages});
                map.addLayer({id: 'villages-fill', type: 'fill', source: 'villages', paint: {
                    'fill-color': ['case', ['boolean', ['feature-state', 'selected'], false], '#c4862b', '#267b58'], 'fill-opacity': 0.24}});
                map.addLayer({id: 'villages-line', type: 'line', source: 'villages', paint: {'line-color': '#246f52', 'line-width': 1}});
                updateLayer(); status.textContent = '';
                map.on('click', 'villages-fill', event => {
                    const feature = collection.features.find(f => f.id === event.features[0].id);
                    if (feature) selectVillage(feature);
                });
                map.on('mouseenter', 'villages-fill', () => {map.getCanvas().style.cursor = 'pointer';});
                map.on('mouseleave', 'villages-fill', () => {map.getCanvas().style.cursor = '';});
            } catch {status.textContent = 'Village layer unavailable. Open the official source or try again later.';}
        });
    } catch {
        status.textContent = 'Interactive rendering is unavailable in this browser. Use village search to explore the source details.';
        document.getElementById('map-reset').disabled = true;
        document.getElementById('map-expand').disabled = true;
    }
})();
