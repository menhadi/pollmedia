(() => {
    const PALETTE = ['#cfe3d8', '#e6dcc3', '#d6e0ee', '#e9d6d6', '#dfe6cf', '#d9d3e8', '#cfe0e0', '#e8e0d0', '#d3e4dd', '#e4d8e6', '#dfe3c9', '#d8dfe8'];
    const AC_PC_PALETTE = ['#8fbfaa', '#a896c6', '#93bacf', '#d9a063', '#b0a2cc', '#a3c6d6'];

    const slug = (value) => String(value ?? '')
        .normalize('NFKD')
        .toLowerCase()
        .replace(/&/g, 'and')
        .replace(/^the\s+/, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    const colourFor = (value, palette) => {
        let hash = 0;
        for (const character of String(value ?? '')) {
            hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
        }

        return palette[hash % palette.length];
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
        const layout = container.closest('.static-map-layout') || container;
        const status = layout.querySelector('[data-map-status]');
        const tooltip = container.querySelector('[data-map-tooltip]');
        const svg = container.querySelector('svg');
        const stateFilter = container.dataset.state || '';
        const mode = container.dataset.mode || 'district';
        const isVillage = mode === 'village';
        const palette = isVillage ? AC_PC_PALETTE : PALETTE;
        const linkTemplate = container.dataset.link || '/india/districts/{state}/{district}';

        let data;
        try {
            const response = await fetch(container.dataset.src, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('map data unavailable');
            data = await response.json();
        } catch (error) {
            if (status) status.textContent = 'The map could not be loaded. Place links remain available below.';
            return;
        }

        const wanted = stateFilter ? slug(stateFilter) : '';
        let features = (data.features || []).filter((feature) => ['Polygon', 'MultiPolygon'].includes(feature.geometry?.type));
        if (wanted) features = features.filter((feature) => slug(feature.properties.state) === wanted);
        if (!features.length) {
            if (status) status.textContent = 'No boundaries are available for this selection.';
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

        const fullViewBox = `0 0 ${width} ${height}`;
        svg.setAttribute('viewBox', fullViewBox);
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
            small.textContent = isVillage ? (feature.properties.subdistrict || state) : state;
            tooltip.dataset.kind = mode;
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

        const entries = features.map((feature, index) => {
            const state = feature.properties.state || '';
            const name = feature.properties.name || '';
            const subdistrict = feature.properties.subdistrict || feature.properties.source_subdistrict || '';
            const linkedLgd = villageLinks[index] || null;
            const districtPath = linkTemplate.replace('{state}', slug(state)).replace('{district}', slug(name));
            const directoryLink = isVillage ? villageDirectoryLink(name) : null;
            const href = isVillage
                ? (linkedLgd
                    ? villageBase + slug(name) + '-' + linkedLgd
                    : (directoryLink || window.location.pathname + '?q=' + encodeURIComponent(name) + '#places'))
                : (knownPages.size === 0 || knownPages.has(districtPath)
                    ? districtPath
                    : (container.dataset.stateLink || '/india/state/{state}').replace('{state}', slug(state)));

            let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
            for (const ring of ringsOf(feature.geometry)) {
                for (const point of ring) {
                    const [px, py] = project(point);
                    x0 = Math.min(x0, px); x1 = Math.max(x1, px);
                    y0 = Math.min(y0, py); y1 = Math.max(y1, py);
                }
            }

            return { feature, index, state, name, subdistrict, href, linked: Boolean(linkedLgd), bounds: [x0, y0, x1, y1] };
        });

        let onHover = null;
        const subdistrictOrder = [...new Set(entries.map((entry) => entry.subdistrict).filter(Boolean))]
            .sort((a, b) => a.localeCompare(b));
        const colourForEntry = (entry) => {
            if (!isVillage) return colourFor(entry.state, palette);
            const position = subdistrictOrder.indexOf(entry.subdistrict);
            return palette[(position < 0 ? 0 : position) % palette.length];
        };

        for (const entry of entries) {
            const fill = colourForEntry(entry);
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', pathFor(entry.feature.geometry));
            path.setAttribute('fill', fill);
            path.setAttribute('stroke', fill);
            path.setAttribute('role', 'link');
            path.setAttribute('tabindex', '0');
            path.setAttribute('aria-label', isVillage ? `${entry.name}, ${entry.subdistrict || entry.state}` : `${entry.name}, ${entry.state}`);

            const open = () => { window.location.href = entry.href; };

            path.addEventListener('pointerenter', (event) => { showTooltip(entry.feature, event); if (onHover) onHover(entry); });
            path.addEventListener('pointermove', (event) => showTooltip(entry.feature, event));
            path.addEventListener('pointerleave', hideTooltip);
            path.addEventListener('focus', () => { if (onHover) onHover(entry); });
            path.addEventListener('click', open);
            path.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
            });
            group.append(path);
        }

        const zoomTo = (subset) => {
            hideTooltip();
            if (!subset || !subset.length) {
                svg.setAttribute('viewBox', fullViewBox);
                return;
            }

            let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
            for (const entry of subset) {
                x0 = Math.min(x0, entry.bounds[0]); y0 = Math.min(y0, entry.bounds[1]);
                x1 = Math.max(x1, entry.bounds[2]); y1 = Math.max(y1, entry.bounds[3]);
            }

            const pad = Math.max((x1 - x0), (y1 - y0)) * 0.06 + 8;
            svg.setAttribute('viewBox', `${(x0 - pad).toFixed(1)} ${(y0 - pad).toFixed(1)} ${(x1 - x0 + pad * 2).toFixed(1)} ${(y1 - y0 + pad * 2).toFixed(1)}`);
        };

        if (isVillage) {
            const subdistrictSelect = layout.querySelector('[data-map-subdistrict]');
            const villageSelect = layout.querySelector('[data-map-village]');
            const districtSelect = layout.querySelector('[data-map-district]');
            const shortcuts = layout.querySelector('[data-map-shortcuts]');
            const subdistricts = subdistrictOrder;

            const subdistrictPagesNode = container.querySelector('[data-map-subdistrict-pages]');
            let subdistrictPages = [];
            if (subdistrictPagesNode) {
                try { subdistrictPages = JSON.parse(subdistrictPagesNode.textContent || '[]'); } catch (error) { subdistrictPages = []; }
            }

            const shortcutLink = (text, href) => {
                const link = document.createElement('a');
                link.href = href;
                link.textContent = text;
                return link;
            };

            let activeSubdistrict = '';

            const renderShortcuts = (subdistrict, hovered) => {
                if (!shortcuts) return;
                shortcuts.replaceChildren();
                const districtName = container.dataset.districtName || 'district';
                shortcuts.append(shortcutLink(`Open ${districtName} district`, container.dataset.districtPath || window.location.pathname));

                if (subdistrict) {
                    const match = subdistrictPages.find((row) => label(row.name) === label(subdistrict));
                    if (match) shortcuts.append(shortcutLink(`Open ${match.name} subdistrict`, match.url));
                }

                if (hovered) shortcuts.append(shortcutLink(`Open ${hovered.name}`, hovered.href));
            };

            const describe = (shown) => {
                const linked = shown.filter((entry) => entry.linked).length;
                return `${shown.length} villages shown, ${linked} with a village page. Select a sub-district or village, or click a polygon.`;
            };

            const fillVillages = (subdistrict) => {
                if (!villageSelect) return;
                villageSelect.replaceChildren();
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = subdistrict ? 'Choose a village' : 'Choose a sub-district first';
                villageSelect.append(placeholder);

                if (!subdistrict) {
                    villageSelect.disabled = true;
                    return;
                }

                const matching = entries
                    .filter((entry) => entry.subdistrict === subdistrict)
                    .sort((a, b) => a.name.localeCompare(b.name));
                for (const entry of matching) {
                    const option = document.createElement('option');
                    option.value = String(entry.index);
                    option.textContent = entry.name + (entry.linked ? '' : ' (listing)');
                    option.dataset.href = entry.href;
                    villageSelect.append(option);
                }
                villageSelect.disabled = matching.length === 0;

                const shown = entries.filter((entry) => entry.subdistrict === subdistrict);
                if (status) status.textContent = describe(shown);
            };

            if (districtSelect) {
                districtSelect.addEventListener('change', () => {
                    if (districtSelect.value) window.location.href = districtSelect.value;
                });
            }

            onHover = (entry) => renderShortcuts(activeSubdistrict, entry);

            if (subdistrictSelect) {
                for (const subdistrict of subdistricts) {
                    const option = document.createElement('option');
                    option.value = subdistrict;
                    option.textContent = subdistrict;
                    subdistrictSelect.append(option);
                }

                subdistrictSelect.addEventListener('change', () => {
                    const value = subdistrictSelect.value;
                    activeSubdistrict = value;
                    const subset = value ? entries.filter((entry) => entry.subdistrict === value) : [];
                    zoomTo(subset);
                    fillVillages(value);
                    renderShortcuts(value);
                    if (!value && status) status.textContent = describe(entries);
                });
            }

            if (villageSelect) {
                villageSelect.addEventListener('change', () => {
                    const option = villageSelect.selectedOptions[0];
                    const href = option?.dataset.href;
                    if (href) window.location.href = href;
                });
            }

            if (status) status.textContent = describe(entries);
            renderShortcuts('');
        } else if (status) {
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
