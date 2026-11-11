(() => {
    const PALETTE = ['#cfe3d8', '#e6dcc3', '#d6e0ee', '#e9d6d6', '#dfe6cf', '#d9d3e8', '#cfe0e0', '#e8e0d0', '#d3e4dd', '#e4d8e6', '#dfe3c9', '#d8dfe8'];

    const slug = (value) => String(value ?? '')
        .normalize('NFKD')
        .toLowerCase()
        .replace(/&/g, 'and')
        .replace(/^the\s+/, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    const colourFor = (value) => {
        let hash = 0;
        for (const character of String(value ?? '')) {
            hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
        }

        return PALETTE[hash % PALETTE.length];
    };

    const ringsOf = (geometry) => (geometry.type === 'Polygon' ? geometry.coordinates : geometry.coordinates.flat());

    const label = (value) => String(value ?? '').normalize('NFKD').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

    const villageDirectoryLink = (name) => {
        const wanted = label(name);
        for (const link of document.querySelectorAll('a[href*="/census/villages/"]')) {
            if (label(link.textContent) === wanted) return link.getAttribute('href');
        }

        return null;
    };

    async function build(container) {
        const status = container.querySelector('[data-map-status]');
        const tooltip = container.querySelector('[data-map-tooltip]');
        const svg = container.querySelector('svg');
        const stateFilter = container.dataset.state || '';
        const linkTemplate = container.dataset.link || '/india/districts/{state}/{district}';

        let data;
        try {
            const response = await fetch(container.dataset.src, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('map data unavailable');
            data = await response.json();
        } catch (error) {
            if (status) status.textContent = 'The district map could not be loaded. District links remain available below.';
            return;
        }

        const wanted = stateFilter ? slug(stateFilter) : '';
        let features = (data.features || []).filter((feature) => ['Polygon', 'MultiPolygon'].includes(feature.geometry?.type));
        if (wanted) features = features.filter((feature) => slug(feature.properties.state) === wanted);
        if (!features.length) {
            if (status) status.textContent = 'No district boundaries are available for this selection.';
            return;
        }

        let west = Infinity, south = Infinity, east = -Infinity, north = -Infinity;
        for (const feature of features) {
            for (const ring of ringsOf(feature.geometry)) {
                for (const [x, y] of ring) {
                    west = Math.min(west, x); east = Math.max(east, x);
                    south = Math.min(south, y); north = Math.max(north, y);
                }
            }
        }

        const width = 600;
        const cosine = Math.cos(((south + north) / 2) * Math.PI / 180);
        const aspect = ((north - south) || 1) / (((east - west) * cosine) || 1);
        const height = Math.max(220, Math.min(760, Math.round(width * aspect)));
        const scale = Math.min((width - 24) / ((east - west) * cosine || 1), (height - 24) / (north - south || 1));
        const offsetX = (width - (east - west) * cosine * scale) / 2;
        const offsetY = (height - (north - south) * scale) / 2;
        const project = ([x, y]) => [offsetX + (x - west) * cosine * scale, offsetY + (north - y) * scale];
        const pathFor = (geometry) => ringsOf(geometry)
            .map((ring) => ring.map((point, index) => (index ? 'L' : 'M') + project(point).map((value) => value.toFixed(1)).join(' ')).join('') + 'Z')
            .join('');

        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.replaceChildren();
        const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        svg.append(group);

        const showTooltip = (feature, event) => {
            if (!tooltip) return;
            const state = feature.properties.state || '';
            const name = feature.properties.name || '';
            tooltip.innerHTML = '';
            const strong = document.createElement('strong');
            strong.textContent = name;
            const small = document.createElement('small');
            small.textContent = container.dataset.mode === 'village' ? (feature.properties.subdistrict || state) : state;
            tooltip.dataset.kind = container.dataset.mode || 'district';
            tooltip.append(strong, small);
            tooltip.hidden = false;
            const bounds = container.getBoundingClientRect();
            const x = event.clientX ?? bounds.left + bounds.width / 2;
            const y = event.clientY ?? bounds.top + bounds.height / 2;
            tooltip.style.left = `${Math.max(60, Math.min(bounds.width - 60, x - bounds.left))}px`;
            tooltip.style.top = `${Math.max(40, y - bounds.top)}px`;
        };
        const hideTooltip = () => { if (tooltip) tooltip.hidden = true; };

        const pagesNode = container.querySelector('[data-map-pages]');
        let knownPages = new Set();
        if (pagesNode) {
            try { knownPages = new Set(JSON.parse(pagesNode.textContent || '[]')); } catch (error) { knownPages = new Set(); }
        }
        const linksNode = container.querySelector('[data-map-links]');
        let villageLinks = {};
        if (linksNode) {
            try { villageLinks = JSON.parse(linksNode.textContent || '{}'); } catch (error) { villageLinks = {}; }
        }
        const villageBase = container.dataset.villageBase || '';
        let featureIndex = -1;

        for (const feature of features) {
            featureIndex += 1;
            const state = feature.properties.state || '';
            const name = feature.properties.name || '';
            const fill = colourFor(container.dataset.mode === 'village' ? (feature.properties.subdistrict || name) : state);
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', pathFor(feature.geometry));
            path.setAttribute('fill', fill);
            path.setAttribute('stroke', fill);
            path.setAttribute('role', 'link');
            path.setAttribute('tabindex', '0');
            path.setAttribute('aria-label', `${name}, ${state}`);

            const districtPath = linkTemplate.replace('{state}', slug(state)).replace('{district}', slug(name));
            const linkedLgd = villageLinks[featureIndex] || null;
            const directoryLink = container.dataset.mode === 'village' ? villageDirectoryLink(name) : null;
            const href = container.dataset.mode === 'village'
                ? (linkedLgd
                    ? villageBase + slug(name) + '-' + linkedLgd
                    : (directoryLink || window.location.pathname + '?q=' + encodeURIComponent(name) + '#places'))
                : (knownPages.size === 0 || knownPages.has(districtPath)
                    ? districtPath
                    : (container.dataset.stateLink || '/india/state/{state}').replace('{state}', slug(state)));
            const open = () => { window.location.href = href; };

            path.addEventListener('pointerenter', (event) => showTooltip(feature, event));
            path.addEventListener('pointermove', (event) => showTooltip(feature, event));
            path.addEventListener('pointerleave', hideTooltip);
            path.addEventListener('click', open);
            path.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
            });
            group.append(path);
        }

        if (status) {
            status.textContent = `${features.length} districts shown. Select a district to open its summary page.`;
        }

        const select = container.querySelector('[data-map-state-select]');
        const reset = container.querySelector('[data-map-reset]');
        if (select) {
            const states = [...new Set((data.features || []).map((feature) => feature.properties.state || ''))].filter(Boolean)
                .sort((a, b) => a.localeCompare(b));
            for (const state of states) {
                const option = document.createElement('option');
                option.value = state;
                option.textContent = state.replace(/^The /, '');
                select.append(option);
            }
            select.addEventListener('change', () => {
                if (!select.value) return;
                window.location.href = container.dataset.stateLink.replace('{state}', slug(select.value));
            });
            if (stateFilter) select.value = stateFilter;
        }
        if (reset) reset.addEventListener('click', () => { window.location.href = container.dataset.homeLink || '/'; });
    }

    const start = () => document.querySelectorAll('[data-static-map]').forEach((container) => build(container));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
