(() => {

    function setup() {
        if (document.querySelector('[data-compact-place-assets]')) return;

        const main = document.querySelector('body.place-layout main');

        if (!main || main.dataset.cleanLayoutReady) return;

        main.dataset.cleanLayoutReady = 'true';

        let methodology = main.querySelector('#area-methodology,#local-methodology,#methodology');

        if (!methodology) {

            methodology = document.createElement('details');

            methodology.id = 'place-methodology';

            methodology.className = 'panel place-methodology';

            const summary = document.createElement('summary');

            summary.textContent = 'Methodology, sources & notes';

            methodology.append(summary);

            main.append(methodology);

        }

        methodology.classList.add('place-methodology');

        const sourceNotes = document.createElement('section');

        const sourceTitle = document.createElement('h3');

        sourceTitle.textContent = 'Additional sources & coverage';

        sourceNotes.append(sourceTitle);

        function collectSources() {

            for (const item of [...main.querySelectorAll('[data-methodology-note],:scope > .notice,#sources,#coverage,#water-disclaimer,#rainfall-disclaimer,.district-map-disclaimer')]) {

                if (item !== methodology && !item.contains(methodology) && !methodology.contains(item)) methodology.append(item);

            }

            for (const link of [...main.querySelectorAll('a[href]')]) {

                if (link.closest('.authority-directory,.community-authorities') || methodology.contains(link) || !/official.*(?:source|workbook|catalogue|directory)|original source|original.*definitions/i.test(link.textContent)) continue;

                const paragraph = link.closest('p');

                const item = paragraph && !paragraph.querySelector('input,select,button,table') ? paragraph : link;

                if (!sourceNotes.contains(item) && !methodology.contains(item)) sourceNotes.append(item);

            }

            if (sourceNotes.children.length > 1 && !methodology.contains(sourceNotes)) methodology.append(sourceNotes);

        }

        collectSources();

        window.addEventListener('load', () => setTimeout(collectSources, 0), {once: true});



        const hasMobileMapControls = !!document.querySelector('script[src*="mobile-map-controls.js"]');

        for (const controls of main.querySelectorAll('.metric-controls,.polygon-filters,form.filters')) {

            if (controls.closest('details') || (hasMobileMapControls && !controls.matches('form'))) continue;

            const fold = document.createElement('details');

            fold.className = 'place-filter-fold';

            const wide = matchMedia('(min-width: 901px)');

            fold.open = wide.matches;

            wide.addEventListener('change', () => {fold.open = wide.matches;});

            const summary = document.createElement('summary');

            summary.textContent = controls.matches('form') ? 'Filter figures' : 'Filter map data';

            controls.before(fold);

            fold.append(summary, controls);

            const legend = controls.querySelector('#metric-legend');

            if (legend) fold.after(legend);

        }



        for (const root of main.querySelectorAll('.explorer,[data-polygon-map]')) {

            const legend = root.querySelector('#metric-legend');

            const canvas = root.querySelector('#india-map,.polygon-canvas');

            if (legend && canvas) canvas.before(legend);

        }

        for (const finder of main.querySelectorAll('.explorer > .finder,.polygon-finder')) {

            if (hasMobileMapControls) continue;

            const button = document.createElement('button');

            button.type = 'button';

            button.className = 'place-finder-toggle';

            button.textContent = 'Find a district or village';

            finder.id ||= 'place-location-finder';

            button.setAttribute('aria-controls', finder.id);

            const small = matchMedia('(max-width: 900px)');

            function updateFinder() {finder.hidden = small.matches; button.hidden = !small.matches; button.setAttribute('aria-expanded', String(!finder.hidden));}

            button.addEventListener('click', () => {finder.hidden = !finder.hidden; button.setAttribute('aria-expanded', String(!finder.hidden));});

            small.addEventListener('change', updateFinder);

            finder.before(button);

            updateFinder();

        }



        const overview = main.querySelector('#area-overview,#overview,#village-overview,#baseline');

        const map = main.querySelector('#area-map,#local-map,#map');

        const facts = main.querySelector('#place-facts');

        const factsFold = facts?.querySelector(':scope > details');

        if (factsFold) factsFold.open = true;

        const history = main.querySelector('#area-history,#local-history,#history');

        const tables = main.querySelector('#area-tables,#area-state-data,#subdistricts,#villages');

        const oldNavigation = main.querySelector('.district-title .section-nav,.area-section-links');

        const heading = main.querySelector('h1');

        const primary = document.createElement('nav');

        primary.className = 'place-primary-nav';

        primary.setAttribute('aria-label', 'Place dashboard');

        for (const [target, label] of [[overview, 'Overview'], [map, 'Map'], [main.querySelector('#groundwater-levels'), 'Groundwater levels'], [main.querySelector('#groundwater-chemistry'), 'Groundwater chemistry'], [main.querySelector('#drinking-water-quality'), 'Drinking-water quality'], [main.querySelector('#district-authority-contacts'), 'Authorities'], [facts, 'Highlights'], [history, 'Graphs'], [tables, 'Tables'], [main.querySelector('#mandi-prices'), main.querySelector('#mandi-prices')?.dataset.navLabel || 'Mandi prices & trends'], [methodology, 'Methodology']]) {

            if (!target) continue;

            const link = document.createElement('a');

            link.href = '#'+target.id;

            link.textContent = label;

            primary.append(link);

        }

        const edit = oldNavigation?.querySelector('.area-edit,.data-edit-listing') || [...main.querySelectorAll('a')].find(a => /^Edit data$/.test(a.textContent.trim()));

        if (edit) {edit.classList.add('place-edit-link'); primary.append(edit);}

        if (oldNavigation) {

            const more = document.createElement('details');

            more.className = 'place-more-sections';

            const summary = document.createElement('summary');

            summary.textContent = 'More sections';

            oldNavigation.before(more);

            more.append(summary, oldNavigation);

        }

        const intro = main.querySelector('.area-intro,.district-title');

        if (intro) intro.after(primary); else heading?.after(primary);

        if (overview && map && overview.parentElement === map.parentElement) map.before(overview);



        let tableNumber = 0;

        for (const table of main.querySelectorAll('table')) {

            if (methodology.contains(table) || table.closest('.explorer,.district-polygons,.local-map-panel,[data-election-map],.history-line,[data-history-chart]')) continue;

            const rows = [...table.querySelectorAll('tbody > tr')].filter(row => !row.hasAttribute('data-empty'));

            let container = table.closest('.table-wrap,.table-scroll,.place-facts-scroll');

            if (!container) {container = document.createElement('div'); table.before(container); container.append(table);}

            container.classList.add('place-table-scroll');

            if (rows.length <= 5) continue;

            table.id ||= 'place-table-'+(++tableNumber);

            const button = document.createElement('button');

            button.type = 'button';

            button.className = 'place-table-toggle';

            button.setAttribute('aria-controls', table.id);

            let expanded = false;

            function update() {

                let shown = 0;

                for (const row of rows) {

                    row.classList.toggle('place-preview-hidden', !expanded && !row.hidden && shown >= 5);

                    if (!row.hidden) shown++;

                }

                button.textContent = expanded ? 'Show first 5 rows' : 'View all '+rows.length.toLocaleString()+' rows';

                button.setAttribute('aria-expanded', String(expanded));

            }

            button.addEventListener('click', () => {expanded = !expanded; update();});

            const section = table.closest('section');

            for (const input of section?.querySelectorAll('input[type=search]') || []) input.addEventListener('input', () => {expanded = true; update();}, true);

            table.tHead?.addEventListener('click', () => {expanded = true; update();}, true);

            container.after(button);

            update();

        }

        function revealSection() {

            const id = decodeURIComponent(location.hash.slice(1));

            if (!id) return;

            const target = document.getElementById(id);

            if (!target) return;

            for (let item = target; item && item !== main; item = item.parentElement) if (item.matches('details')) item.open = true;

        }

        revealSection();

        window.addEventListener('hashchange', revealSection);

    }

    if (document.readyState === 'complete') setup(); else document.addEventListener('DOMContentLoaded', setup, {once: true});

})();

