import { placeGroups, groupForSection, pageSlice, compactReference, editableNumber, panelCanShareRow, concisePageLink, summaryFingerprint, compactCountValue } from './compact-place-model.js?v=20261107-panchayats';



const main = document.querySelector('main.dashboard-main, main');

if (main && !main.matches('.my-place') && !/^\/issues(?:\/|$)/.test(location.pathname)) {

    document.body.classList.add('compact-place');
    const electionOnly = /\/constituencies\/(?:pc|ac)\//.test(location.pathname);
    const pageGroups = /\/constituencies\/(?:pc|ac)\//.test(location.pathname) ? ['Map','Overview','Authorities','Elections','Graphs','Places','Related','Sources'] : placeGroups;

    const hindi = document.documentElement.lang.startsWith('hi');

    const labels = hindi ? {'Panchayats':'ग्राम पंचायत',Overview:'सारांश',Map:'मानचित्र',People:'जनसंख्या',Water:'जल',Agriculture:'कृषि',Authorities:'प्रतिनिधि और अधिकारी',Related:'संबंधित स्थान',Services:'सेवाएँ',Governance:'शासन',Elections:'चुनाव',Sources:'स्रोत'} : {};

    const label = text => (text === 'Sources' ? (hindi ? 'कार्यप्रणाली और अस्वीकरण' : 'Methodology and disclaimer') : labels[text]) || ({Panchayats:'Gram Panchayat',Mandi:hindi?'मंडी भाव और रुझान':'Mandi prices and trends',Authorities:'Representatives & authorities',Related:'Related places',Governance:'Community & governance'}[text]) || text;

    const create = (tag, className, text) => {

        const node = document.createElement(tag);

        if (className) node.className = className;

        if (text !== undefined) node.textContent = text;

        return node;

    };

    const editLink = [...main.querySelectorAll('a[href]')].find(link => /^(?:Edit\b|डेटा संपादित)/i.test(link.textContent.trim()) && /\/(?:contribute|data\/census\/records)\//.test(link.pathname));

    const censusFilters = main.querySelector('form.filters:has(select[name="group"]):has(select[name="residence"])');

    const filteredData = main.querySelector('#area-tables') || main.querySelector('#area-state-data');

    if (censusFilters && filteredData) {

        const previous = censusFilters.parentElement;

        censusFilters.classList.add('compact-data-filters');

        const heading = filteredData.querySelector('h2');

        if (heading) heading.after(censusFilters); else filteredData.prepend(censusFilters);

        for (const [name, title] of [['group',hindi ? 'माप' : 'Measure'],['residence',hindi ? 'निवास' : 'Residence']]) {

            const fieldLabel = censusFilters.querySelector(`select[name="${name}"]`)?.closest('label');

            const text = [...(fieldLabel?.childNodes || [])].find(node => node.nodeType === 3 && node.textContent.trim());

            if (text) text.textContent = title;

        }

        const submit = censusFilters.querySelector('button'); if (submit) submit.textContent = hindi ? 'लागू करें' : 'Apply';

        if (previous !== filteredData && !previous.textContent.trim()) previous.hidden = true;

    }

    const sourceNotes = create('details', 'compact-source-notes');

    sourceNotes.id = 'compact-source-notes';

    sourceNotes.append(create('summary', '', hindi ? 'डेटा संदर्भ' : 'Data references'));



    // Keep descriptions available, but out of headings and repeated summary rows.

    main.querySelectorAll(':scope > p, .area-intro p, .place-intro p, .place-page-heading p, .district-title p, .authority-directory-heading p, .state-section-heading p, .place-facts-heading > p, #at-a-glance > p, #area-state-data > p, [data-methodology-note]').forEach(note => {

        if (note.matches('.place-type') || note.querySelector('a,button,input') || !note.textContent.trim()) return;

        sourceNotes.append(note);

    });

    main.querySelectorAll('p.small').forEach(note => {

        if (/Females per 1,000 males use the left axis; the purple line shows female share/i.test(note.textContent)) sourceNotes.append(note);

    });

    main.querySelectorAll('[data-history-chart]').forEach(chart => {

        const notes = [...chart.querySelectorAll(':scope > p.small')];

        if (!notes.length) return;

        const details = create('details','compact-chart-notes');

        details.append(create('summary','',hindi ? 'टिप्पणियाँ' : 'Notes'));

        notes.forEach(note => details.append(note)); chart.append(details);

    });



    const summaryContainers = '.metrics,.stats,.data-overview,.state-year-metrics,.water-cards,.snapshot-grid,.summary-grid,.stat-grid,.metric-grid';

    function convertSummaries(root) {

        const containers = [...root.querySelectorAll(summaryContainers)];

        if (root.matches?.(summaryContainers)) containers.unshift(root);

        containers.forEach(container => {

            if ((container.dataset.compactConverted && container.querySelector('table')) || container.closest('table')) return;

            if (container.closest('.authority-directory,.community-authorities')) return;

            const cards = [...container.children];

            if (!cards.length || !cards.every(card => card.querySelector('strong,.stat-value,.value') && !card.querySelector('canvas,svg,form,table'))) return;

            const table = create('table', 'compact-summary-table');

            const head = table.createTHead().insertRow();

            (hindi ? ['माप','मान','संदर्भ'] : ['Measure','Value','Reference']).forEach(text => head.append(create('th', '', text)));

            const body = table.createTBody();

            cards.forEach(card => {

                const value = card.querySelector('strong,.stat-value,.value');

                const title = card.querySelector('span,.stat-label,.label,h3,h4');

                const reference = card.querySelector('small,.stat-note,.stat-source');

                const row = body.insertRow();

                const measure = row.insertCell();

                if (title?.querySelector('a')) measure.append(...title.childNodes);

                else measure.textContent = title?.textContent.trim() || card.getAttribute('aria-label') || '';

                const cell = row.insertCell(); cell.className = 'compact-numeric';

                for (const [key, val] of Object.entries(value.dataset)) cell.dataset[key] = val;

                cell.append(value);

                const source = row.insertCell();

                if (reference?.children.length) source.append(...reference.childNodes);

                else source.textContent = compactReference(reference?.textContent.trim() || '');

                // Preserve any additional source links or metadata from the original card.

                value.removeAttribute('style');

                [...card.children].filter(node => node !== title && node !== reference && !node.contains(value)).forEach(node => {

                    if (node.textContent.trim()) row.cells[2].append(node);

                });

            });

            container.replaceChildren(table);

            container.classList.add('compact-converted-metrics', 'compact-table-wrap');

            container.dataset.compactConverted = '1';

        });

    }

    convertSummaries(main);



    function convertCardGroups(root) {

        root.querySelectorAll('.source-grid,.place-list,#connections > .grid,#related-places > .grid').forEach(container => {

            if ((container.dataset.compactConverted && container.querySelector('table')) || container.closest('table')) return;

            if (container.closest('.authority-directory,.community-authorities')) return;

            const cards = [...container.children];

            if (!cards.length || !cards.every(card => card.matches('article,a'))) return;

            const links = cards.every(card => card.matches('a'));

            const glance = container.closest('#at-a-glance');

            const settlements = container.closest('#rural-urban');

            const table = create('table');

            const head = table.createTHead().insertRow();

            (settlements ? (hindi ? ['निवास','जनसंख्या','विवरण'] : ['Residence','Population','Details']) : links ? [hindi ? 'स्थान' : 'Place'] : [hindi ? 'माप' : 'Measure', glance ? (hindi ? 'मान' : 'Value') : (hindi ? 'विवरण' : 'Details')]).forEach(text => head.append(create('th','',text)));

            const body = table.createTBody();

            cards.forEach(card => {

                const row = body.insertRow();

                if (links) { row.insertCell().append(card); return; }

                const title = card.querySelector('h3');

                if (settlements) {

                    row.insertCell().textContent = card.querySelector('.eyebrow')?.textContent.trim() || '';

                    row.insertCell().textContent = title?.textContent.trim() || '';

                    const details = row.insertCell();

                    card.querySelectorAll('p:not(.eyebrow)').forEach(note => details.append(note));

                    return;

                }

                if (glance) {

                    const description = card.textContent.toLowerCase();

                    const name = /rural/.test(description) ? 'Rural population' : /male.*female|literacy/.test(description) ? 'Male / female literacy' : /children|aged 0/.test(description) ? 'Children age 0–6' : /workers/.test(description) ? 'Worker participation' : title?.textContent || '';

                    row.insertCell().textContent = name;

                    const value = row.insertCell(); value.textContent = title?.textContent || ''; value.className = 'compact-numeric';

                    sourceNotes.append(card);

                    return;

                }

                const cell = row.insertCell(); if (title) cell.append(...title.childNodes);

                const details = row.insertCell();

                while (card.firstChild) details.append(card.firstChild);

                details.querySelectorAll('h3:empty').forEach(empty => empty.remove());

            });

            container.replaceChildren(table); container.classList.add('compact-converted-metrics','compact-table-wrap');

            container.dataset.compactConverted = '1';

        });

    }

    convertCardGroups(main);



    function convertRelatedLinks(root) {

        root.querySelectorAll('[id^="compact-related-pages-"] > nav').forEach(container => {

            if (container.querySelector('table')) return;

            const links = [...container.children];

            if (!links.length || !links.every(link => link.matches('a'))) return;

            const typed = links.some(link => link.querySelector('.kicker,.label'));

            const table = create('table'), head = table.createTHead().insertRow();

            (typed ? (hindi ? ['प्रकार','स्थान'] : ['Type','Place']) : [hindi ? 'पृष्ठ' : 'Page']).forEach(text => head.append(create('th','',text)));

            const body = table.createTBody();

            links.forEach(link => {

                const row = body.insertRow();

                if (typed) row.insertCell().textContent = link.querySelector('.kicker,.label')?.textContent.trim() || (hindi ? 'पृष्ठ' : 'Page');

                row.insertCell().append(link);

            });

            container.replaceChildren(table); container.classList.add('compact-related-links','compact-table-wrap');

        });

    }



    if (sourceNotes.children.length > 1) { sourceNotes.dataset.compactGroup = 'Sources'; main.append(sourceNotes); }



    const nav = create('nav', 'compact-nav'); nav.setAttribute('aria-label', hindi ? 'पृष्ठ के अनुभाग' : 'Page sections');

    const headingBlock = main.querySelector(':scope > header, :scope > .place-page-heading, :scope > .place-intro, :scope > .district-title, :scope > h1');

    if (headingBlock) headingBlock.after(nav); else main.prepend(nav);

    const moreLinks = create('details','compact-more-links');

    moreLinks.id = 'compact-other-links'; moreLinks.dataset.compactGroup = 'Sources';

    moreLinks.append(create('summary','',hindi ? 'अन्य पृष्ठ लिंक' : 'More page links'));

    main.querySelectorAll('.section-nav,.place-section-nav,.state-jump-links,.place-primary-nav,.place-more-sections,.intro-links,.area-section-links[aria-label],.seat-page-actions,nav.nav[aria-label="Page sections"],nav.breadcrumbs[aria-label="Related Census state"]').forEach(old => {

        old.hidden = false; old.classList.add('compact-secondary-links'); moreLinks.append(old);

        old.querySelectorAll('a').forEach(link => {

            if (link.origin === location.origin && link.pathname.replace(/\/$/,'') === location.pathname.replace(/\/$/,'')) { link.remove(); return; }

            const original = link.textContent.trim();

            const concise = concisePageLink(original);

            if (concise !== original) { link.title ||= original; link.textContent = concise; }

        });

    });

    if (moreLinks.querySelector('a')) main.append(moreLinks);

    moreLinks.querySelector(':scope > summary').textContent = hindi ? 'लिंक' : 'Links';

    main.querySelectorAll('#methodology > summary,#area-methodology > summary,#local-methodology > summary').forEach(summary => {

        summary.title ||= summary.textContent.trim();

        summary.textContent = hindi ? 'कार्यप्रणाली' : 'Methodology';

    });

    if (editLink) {

        editLink.textContent = hindi ? 'डेटा संपादित करें' : 'Edit data';

        editLink.classList.add('compact-heading-edit');

    }

    main.querySelectorAll(':scope > p').forEach(note => { if (!note.textContent.trim() && !note.children.length) sourceNotes.append(note); });



    const blocks = new Map(), sections = new Map();

    let snapshot;

    const ignored = '.page-edit-status,.compact-pager,.village-breadcrumb-bar,.location-breadcrumbs,.compact-group,.approved-mobile-representative';

    function assignBlocks() {

        blocks.forEach((group, block) => { if (!main.contains(block) || block.dataset.approvedLocalSource) blocks.delete(block); });

        [...main.children].forEach(block => {

            if (blocks.has(block) || block === nav || !block.matches('section,article,details,aside,div')) return;

            if (block.matches('[data-community-area]')) return;

            if (block.matches(ignored) || block.contains(main.querySelector('h1'))) return;

            if (block.querySelector('form.filters') && !block.querySelector('h2,h3,table,canvas,svg')) return;

            if (!block.textContent.trim() && !block.querySelector('canvas,svg,[data-election-map]')) return;

            const heading = block.querySelector('h2,h3,summary');

            let group = block.dataset.compactGroup || groupForSection(`${block.id} ${block.classList.contains('water-evidence') ? 'water-evidence' : ''}`, heading?.textContent || block.getAttribute('aria-label') || '');

            if (block.matches('[data-election-map]') || block.querySelector('[data-election-map],[data-district-map],[data-local-map]')) group = 'Map';

            if (block.id === 'history' && /constituenc|election|archive/.test(location.pathname)) group = 'Elections';

            if (block.querySelector('.population-donut,[data-history-chart]') && !block.matches('#year-detail')) group = 'Graphs';

            if (electionOnly && !pageGroups.includes(group)) { block.remove(); return; }
            blocks.set(block,group); block.classList.remove('compact-group-hidden','compact-subsection-hidden');

        });

    }

    function refreshOverview() {}

    function refreshGroups() {

        assignBlocks(); refreshOverview();

        blocks.forEach((group,block) => {

            let section = sections.get(group);

            if (!section) {

                section = create('section','compact-group'); section.id = `compact-${group.toLowerCase()}`;

                section.dataset.compactSection = group; section.setAttribute('aria-label',label(group));

                section.append(create('h2','compact-group-title',label(group)));

                sections.set(group,section); main.append(section);

            }

            const container=group==='Sources' && !block.matches('.approved-disclaimer') ? (section.querySelector('.approved-disclaimer-body')||section) : section;
            if (block.parentElement !== container) container.append(block);

        });

        sections.forEach(section=>{section.hidden=![...section.children].some(child=>!child.matches('.compact-group-title'));});
        const present = pageGroups.filter(group => sections.has(group) && !sections.get(group).hidden);

        const desired = present.map(group => sections.get(group));

        const actual = [...main.children].filter(child => child.matches('.compact-group'));

        if (desired.some((section,index) => section !== actual[index])) desired.forEach(section => main.append(section));

        desired.forEach(section => {

            const title = section.querySelector(':scope > .compact-group-title');

            const first = [...section.children].find(child => child !== title);

            const heading = first?.querySelector('h2');

            if (title) title.hidden = !!heading && heading.textContent.trim().toLowerCase() === title.textContent.trim().toLowerCase();

        });

        if (nav.dataset.groups !== present.join()) {

            nav.dataset.groups = present.join(); nav.replaceChildren();

            present.forEach(group => {

                const link = create('a','compact-section-link',group === 'Sources' ? (hindi ? 'कार्यप्रणाली' : 'Methodology') : group === 'Authorities' ? (hindi ? 'प्रतिनिधि' : 'Authorities') : label(group));

                link.href = `#${sections.get(group).id}`; nav.append(link);

            });

            if (editLink && !editLink.closest('.approved-title-row')) nav.append(editLink);

        }

        const water = sections.get('Water');

        if (water) {

            const ordered = [...water.children].filter(node => !node.matches('.compact-group-title'));

            const rank = node => node.matches('.water-evidence') || node.querySelector('[data-water-kind]') ? 0 : /groundwater-levels/.test(node.id) ? 1 : /chemistry/.test(node.id) ? 2 : /quality/.test(node.id) ? 3 : 4;

            const sorted = [...ordered].sort((a,b) => rank(a)-rank(b));

            if (sorted.some((node,index) => node !== ordered[index])) sorted.forEach(node => water.append(node));

        }

        updateOffsets(); updateActiveSection();

        arrangePanels();

    }

    function arrangePanels() {

        sections.forEach((section,group) => {

            section.classList.toggle('compact-paired-section',!['Map','Sources','Governance'].includes(group));

            [...section.children].filter(child => !child.matches('.compact-group-title')).forEach(panel => {

                const tables = [...panel.querySelectorAll('table')].filter(table => !table.closest('.chart-values'));

                const map = !!panel.querySelector('[data-election-map],[data-local-map],.leaflet-container,.district-polygons,.local-map-panel') || panel.matches('.water-evidence,.place-facts');

                const charts = panel.querySelectorAll('[data-history-chart]').length;

                panel.classList.toggle('compact-half-panel',panelCanShareRow({columns:tables.map(table => table.tHead?.rows[0]?.cells.length || table.rows[0]?.cells.length || 0),charts,map}));

            });

        });

        main.querySelectorAll('.place-facts').forEach(panel => panel.classList.add('compact-facts-grid'));

    }

    function updateOffsets() {

        const header = document.querySelector('.public-header');

        const fixedHeader = header && ['sticky','fixed'].includes(getComputedStyle(header).position);

        document.documentElement.style.setProperty('--compact-sticky-top',`${fixedHeader ? header.getBoundingClientRect().height : 0}px`);

        document.documentElement.style.setProperty('--compact-nav-height',`${nav.getBoundingClientRect().height}px`);

    }

    function updateActiveSection() {

        const offset = nav.getBoundingClientRect().height + parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--compact-sticky-top') || '0') + 16;

        const ordered = pageGroups.map(group => sections.get(group)).filter(Boolean);

        let active = ordered[0];

        for (const section of ordered) { if (section.getBoundingClientRect().top <= offset) active = section; }

        nav.querySelectorAll('.compact-section-link').forEach(link => {

            if (link.hash === `#${active?.id}`) link.setAttribute('aria-current','location'); else link.removeAttribute('aria-current');

        });

    }

    let scrolling = false;

    window.addEventListener('scroll',() => {

        if (scrolling) return; scrolling = true;

        requestAnimationFrame(() => { scrolling = false; updateActiveSection(); });

    },{passive:true});

    const layoutObserver = new ResizeObserver(updateOffsets); layoutObserver.observe(nav);

    const publicHeader = document.querySelector('.public-header'); if (publicHeader) layoutObserver.observe(publicHeader);



    function revealHash() {

        let id; try { id = decodeURIComponent(location.hash.slice(1)); } catch { return; }

        const target = document.getElementById(id); if (!target || !main.contains(target)) return;

        let parent = target.parentElement;

        while (parent && parent !== main) { if (parent.matches('details')) parent.open = true; parent = parent.parentElement; }

        if (target.matches('details')) target.open = true;

        requestAnimationFrame(() => target.scrollIntoView({block:'start'}));

    }

    window.addEventListener('hashchange', revealHash);

    main.addEventListener('click', event => {

        const link = event.target.closest('a[href]');

        if (link && link.pathname === location.pathname && link.hash) setTimeout(revealHash, 0);

    });



    const pagers = new WeakMap();

    function paginate(table) {

        if (pagers.has(table)) { pagers.get(table)(); return; }

        if (table.closest('.compact-section-catalogue') || !table.tBodies.length) return;

        const rows = [...table.tBodies].flatMap(body => [...body.rows]);

        if (rows.length <= 50 || rows.some(row => row.querySelector('[rowspan]'))) return;

        let page = 1, size = 50;

        const bar = create('div', 'compact-pager');

        const count = create('span', 'compact-page-count'); count.setAttribute('aria-live','polite');

        const controls = create('div', 'compact-page-controls');

        const select = create('select'); select.setAttribute('aria-label', hindi ? 'प्रति पृष्ठ पंक्तियाँ' : 'Rows per page');

        [50,100].forEach(number => { const option = create('option', '', String(number)); option.value = String(number); select.append(option); });

        const all = create('option', '', hindi ? 'सभी' : 'All'); all.value = 'all'; select.append(all);

        const pageLabel = create('span');

        const buttons = ['«','‹','›','»'].map((text,index) => {

            const button = create('button','',text); button.type = 'button';

            button.setAttribute('aria-label', ['First page','Previous page','Next page','Last page'][index]);

            controls.append(button); return button;

        });

        controls.insertBefore(pageLabel, buttons[2]); controls.prepend(select); bar.append(count, controls);

        const wrap = table.parentElement.matches('.place-table-scroll,.table-scroll,.table-wrap,.compact-table-wrap') ? table.parentElement : table;

        wrap.after(bar);

        const render = () => {

            const currentRows = [...table.tBodies].flatMap(body => [...body.rows]);

            const available = currentRows.filter(row => !row.hidden && row.style.display !== 'none');

            const slice = pageSlice(available, page, size === 'all' ? Math.max(1, available.length) : size);

            page = slice.page;

            const visible = new Set(slice.rows);

            currentRows.forEach(row => { row.classList.remove('place-preview-hidden'); row.classList.toggle('compact-row-hidden', !visible.has(row)); });

            count.textContent = `${slice.start}–${slice.end} / ${slice.total}`;

            pageLabel.textContent = `${page} / ${slice.pages}`;

            buttons[0].disabled = buttons[1].disabled = page === 1;

            buttons[2].disabled = buttons[3].disabled = page === slice.pages;

        };

        select.onchange = () => { size = select.value === 'all' ? 'all' : Number(select.value); page = 1; render(); };

        buttons.forEach((button,index) => button.onclick = () => { page = index === 0 ? 1 : index === 1 ? page - 1 : index === 2 ? page + 1 : Number.MAX_SAFE_INTEGER; render(); });

        table.tHead?.addEventListener('click', () => setTimeout(render, 0));

        // Existing search controls own their filtering; paginate their surviving rows.

        table.closest('section,details')?.addEventListener('input', () => { page = 1; setTimeout(render, 0); });

        pagers.set(table, render); render();

    }



    function valueActions(root) {
        root.querySelectorAll('.compact-value-button').forEach(button=>button.replaceWith(...button.childNodes));
        root.querySelectorAll('.compact-cell-edit').forEach(button=>button.remove());
    }

    function consolidateOverview() {

        const base = main.querySelector('#overview,#area-overview,#village-overview,#baseline');

        if (!base) return;

        const tables = [...base.querySelectorAll('table')];

        if (!tables.length) return;

        const table = tables[0];

        const water=main.querySelector('.jjm-water');
        const waterTable=water?.querySelector('.compact-summary-table');
        if(waterTable&&waterTable!==table){
            const source=water.querySelector('a[href*="ejalshakti.gov.in"]');
            const date=water.textContent.match(/retrieved\s+(\d{4}-\d{2}-\d{2})/i)?.[1]||'';
            for(const row of [...waterTable.tBodies[0].rows]){
                while(row.cells.length<3)row.insertCell();
                if(source){const reference=source.cloneNode(true);reference.textContent=`JJM${date?' · '+date:''} ↗`;row.cells[2].replaceChildren(reference);}
                else row.cells[2].textContent=`JJM${date?' · '+date:''}`;
                row.dataset.overviewJjm='1';table.tBodies[0].append(row);
            }
            const container=waterTable.closest('.water-cards,.compact-converted-metrics')||waterTable;
            container.remove();
            const footer=source?.closest('p');
            if(footer){
                const map=footer.querySelector('a[href="#map"]');
                if(map){const actions=create('nav','approved-water-actions');actions.setAttribute('aria-label','Water links');actions.append(map);(water.querySelector('h2')||water.firstElementChild).after(actions);}
                footer.remove();
            }
            water.querySelectorAll(':scope > p').forEach(note=>{if(/^Official JJM report snapshot retrieved/i.test(note.textContent.trim()))note.hidden=true;});
        }

        const glance = main.querySelector('#at-a-glance');

        if (glance && !base.contains(glance)) {

            const period = table.tBodies[0]?.rows[0]?.cells[2]?.textContent.trim() || '';

            glance.querySelectorAll('tbody tr').forEach(row => {

                if (row.cells.length < 3) row.insertCell().textContent = period;

                if (/rural population/i.test(row.cells[0].textContent) && /%/.test(row.cells[1].textContent)) row.cells[0].textContent = 'Rural population share';

                table.tBodies[0].append(row);

            });

            const alias = create('span','compact-anchor-alias'); alias.id = glance.id;

            glance.removeAttribute('id'); base.prepend(alias); glance.remove();

        }

        const scope = main.matches('[data-local-dashboard]') || /\/villages?\//.test(location.pathname) ? 'village' : main.querySelector('select[name="residence"]')?.value || 'Total';

        const settlement=main.querySelector('section#rural-urban');
        if(settlement){
            const period=table.tBodies[0]?.rows[0]?.cells[2]?.textContent.trim()||'';
            const names=new Set([...table.tBodies[0].rows].map(row=>row.cells[0]?.textContent.trim().toLowerCase()));
            const add=(name,value)=>{if(!value||names.has(name.toLowerCase()))return;const row=table.tBodies[0].insertRow();row.insertCell().textContent=name;row.insertCell().textContent=value;row.insertCell().textContent=period;names.add(name.toLowerCase());};
            const rows=[...settlement.querySelectorAll('tbody tr')];let complete=rows.length>0;
            for(const row of rows){
                const name=row.cells[0]?.textContent.trim();
                const population=compactCountValue(row.cells[1]?.textContent.trim()||'');
                const details=row.cells[2]?.textContent||'';
                const households=details.match(/([\d,]+)\s+households/i)?.[1];
                const literacy=details.match(/Literacy\s*7\+\s*:\s*([\d.]+%)/i)?.[1];
                const share=details.match(/([\d.]+%)\s+of district/i)?.[1];
                if(!name||!/^\d[\d,]*$/.test(population)||!households||!literacy||!share){complete=false;continue;}
                add(`${name} population`,population);add(`${name} households`,households);add(`${name} literacy age 7+`,literacy);add(`${name} population share`,share);
            }
            if(complete){const alias=create('span','compact-anchor-alias');alias.id=settlement.id;settlement.removeAttribute('id');base.prepend(alias);settlement.remove();}
        }

        const seen = new Map();

        base.querySelectorAll('tbody tr').forEach(row => {

            if (row.cells.length < 3) return;

            const value = row.cells[1].dataset.sortValue || row.cells[1].querySelector('.compact-value-button')?.textContent || row.cells[1].textContent;

            const key = summaryFingerprint(row.cells[0].textContent,value,row.cells[2].textContent,scope);

            if (key) seen.set(key,row);

        });

        main.querySelectorAll('.place-facts-topic').forEach(topic => {

            if (!/population.and.education/i.test(topic.id) || main.querySelector('#urban-overview-boundaries')) return;

            const factsScope = topic.closest('[data-facts-scope]')?.dataset.factsScope;

            const rowScope = factsScope === 'village' ? 'village' : 'Total';

            topic.querySelectorAll('tbody tr').forEach(row => {

                const key = summaryFingerprint(row.cells[0]?.textContent,row.cells[row.cells.length-1]?.textContent,row.dataset.factPeriod,rowScope);

                const existing = key && seen.get(key); if (!existing) return;

                if (row.dataset.factSource && !existing.cells[2].querySelector('a')) {

                    const source = create('a','compact-source-link',' ↗'); source.href = row.dataset.factSource;

                    source.setAttribute('aria-label',hindi ? 'आधिकारिक स्रोत' : 'Official source'); source.target = '_blank'; source.rel = 'noopener noreferrer'; existing.cells[2].append(source);

                }

                row.remove();

            });

            if (!topic.querySelector('tbody tr')) topic.remove();

        });

        main.querySelectorAll('.place-facts').forEach(panel => {

            if (!panel.querySelector('[id*="population-and-education"]') && panel.querySelector('.place-facts-topic')) {

                const heading = panel.querySelector('.place-facts-heading h2'); if (heading) heading.textContent = hindi ? 'तुलनाएँ' : 'Comparisons';

            }

        });

        main.querySelectorAll('.place-facts').forEach(panel => { if (!panel.querySelector('.place-facts-topic')) panel.hidden = true; });

    }

    function combinePopulationProfile() {

        const chart = main.querySelector('.population-donut')?.closest('section');

        const table = main.querySelector('#rural-urban');

        if (!chart || !table || chart.closest('.compact-population-profile')) return;

        const panel = create('section','panel compact-population-profile'); panel.id = 'compact-population-profile'; panel.dataset.compactGroup = 'People';

        panel.append(create('h2','',hindi ? 'जनसंख्या और साक्षरता' : 'Population & literacy'));

        const controls = create('div','compact-view-switch'); controls.setAttribute('role','group'); controls.setAttribute('aria-label','Population view');

        const chartButton = create('button','',hindi ? 'चार्ट' : 'Chart'),tableButton = create('button','',hindi ? 'तालिका' : 'Table');

        chartButton.type = tableButton.type = 'button'; chart.id ||= 'compact-population-chart'; chartButton.setAttribute('aria-controls',chart.id); tableButton.setAttribute('aria-controls',table.id);

        const show = useChart => { chart.hidden = !useChart; table.hidden = useChart; chartButton.setAttribute('aria-pressed',String(useChart)); tableButton.setAttribute('aria-pressed',String(!useChart)); };

        chartButton.onclick = () => show(true); tableButton.onclick = () => show(false);

        controls.append(chartButton,tableButton); panel.append(controls); chart.before(panel); panel.append(chart,table);

        chart.querySelector(':scope > h2')?.setAttribute('hidden',''); table.querySelector(':scope > h2')?.setAttribute('hidden','');

        chart.querySelectorAll('p.muted').forEach(note => sourceNotes.append(note)); show(true);

    }

    function cleanTechnicalCopy() {
        main.querySelectorAll('table').forEach(table => {
            [...(table.tHead?.rows[0]?.cells || [])].forEach((header,index) => {
                if (/Census code|LGD.*(?:code|reference|ID)/i.test(header.textContent)) header.dataset.placeIdentifierColumn = '1';
                if (!header.dataset.placeIdentifierColumn) return;
                [...table.tBodies].flatMap(body => [...body.rows]).forEach(row => {
                    const cell = row.cells[index];
                    if (cell?.querySelector('a')) cell.querySelectorAll('small,.small').forEach(note => {
                        if (/^\s*(?:(?:Census|LGD)\s*(?:code|ID)?\s*[:#]?\s*)?\d+\s*$/i.test(note.textContent)) note.hidden = true;
                    });
                });
                const walker = document.createTreeWalker(header,NodeFilter.SHOW_TEXT);
                while (walker.nextNode()) walker.currentNode.textContent = walker.currentNode.textContent.replace(/\s*[/·]\s*Census code/gi,'');
            });
        });

        main.querySelectorAll('table small,table .small,table p').forEach(note => {

            if (/^\s*(?:JJM\s*(?:(?:village|reference|ref|ID|code)\s*)?[:#-]?\s*\d+|Report row\s+\d+)\s*$/i.test(note.textContent)) note.hidden = true;

            if (/^\s*(?:Source locality|स्रोत स्थान)\s*:/i.test(note.textContent)) note.hidden = true;

        });

        const village = main.matches('[data-local-dashboard]') || /\/(?:villages?|census\/(?:villages|towns))\//.test(location.pathname);

        main.querySelectorAll('p,#coverage').forEach(node => {

            if (node.closest('.issue-post')) return;

            if (node.querySelector('table,form,canvas,section')) return;

            const text = node.textContent.trim();

            if (/published village records.*(?:Census records|LGD)|not an accepted LGD directory|LGD and village-to-AC\/PC links remain pending|polygon population estimates/i.test(text)) node.hidden = true;

            if (/^(?:Official\s+)?LGD(?: village)?(?:\s+code)?\s*[:#]?\s*\d|^LGD (?:district|subdistrict):|Matched by LGD code/i.test(text)) node.hidden = true;

        });

        if (!village) return;

        main.querySelectorAll('table').forEach(table => {

            if (table.closest('.issue-post')) return;

            [...(table.tHead?.rows[0]?.cells || [])].forEach((cell,index) => {

                if (!/LGD.*(?:code|ID)|^LGD\s*[▲▼↕\s]*$/i.test(cell.textContent)) return;

                [...table.rows].forEach(row => { if (row.cells[index]) row.cells[index].hidden = true; });

            });

        });

        main.querySelectorAll('table th,label').forEach(label => {

            for (const text of [...label.childNodes].filter(node => node.nodeType === 3)) text.textContent = text.textContent.replace(/Village name or JJM ID/gi,'Village name').replace(/Village\s*·\s*JJM ID/gi,'Village');

        });

        const walker = document.createTreeWalker(main,NodeFilter.SHOW_TEXT);

        while (walker.nextNode()) {

            const node = walker.currentNode;

            if (node.parentElement?.closest('script,style,textarea,.issue-post')) continue;

            const text = node.textContent.replace(/\s*[·,(–-]?\s*LGD(?:\s+(?:village|district|subdistrict|state))?(?:\s+(?:code|ID|reference))?\s*[:#]?\s*\d+\s*\)?/gi,'');

            if (text !== node.textContent) node.textContent = text;

        }

        document.title = document.title.replace(/LGD village/gi,'Village');

    }

    function prepareOrderedContent() {

        main.querySelectorAll('.population-donut').forEach(chart=>chart.closest('section')?.remove());

        main.querySelectorAll('.place-facts-topic').forEach(topic => {

            if (topic.closest('.compact-group')) return;

            if (/soil/i.test(topic.id)) { topic.dataset.compactGroup = 'Soil'; main.append(topic); }

            else if (/water/i.test(topic.id)) { topic.dataset.compactGroup = 'Water'; main.append(topic); }

        });



        main.querySelectorAll('details').forEach(detail => {

            if (detail.closest('.approved-disclaimer')) return;

            if (detail.querySelector('table') || detail.matches('[data-market-page-details]')) {

                detail.open = true; detail.classList.add('ordered-visible-table');

            }

        });

        main.querySelectorAll('.satellite-comparison').forEach(panel => {

            const stats = panel.querySelector('.satellite-statistics');

            const controls = panel.querySelector('.satellite-controls');

            if (stats && controls && !controls.closest('.ordered-ndvi-row')) {

                const row = create('div','ordered-ndvi-row'); stats.before(row); row.append(stats,controls);

            }

        });

        // The map and satellite comparison are independent sections even if a template nests them.

        main.querySelectorAll('.satellite-comparison').forEach(panel => {

            if (panel.parentElement !== main && !panel.parentElement.matches('.compact-group')) main.append(panel);

        });

    }

    function enhance(root) {

        prepareOrderedContent();

        convertSummaries(root);

        convertCardGroups(root);

        convertRelatedLinks(root);

        consolidateOverview();



        cleanTechnicalCopy();

        root.querySelectorAll('table').forEach(table => {

            if (!table.parentElement.matches('.place-table-scroll,.table-scroll,.table-wrap,.compact-table-wrap,.scroll')) {

                const wrap = create('div','compact-table-wrap'); table.before(wrap); wrap.append(table);

            }

            paginate(table);

        });

        valueActions(root);

        main.querySelectorAll('.place-table-toggle').forEach(button => button.remove());

        main.querySelectorAll('.place-preview-hidden').forEach(row => row.classList.remove('place-preview-hidden'));

        refreshGroups();

    }

    main.addEventListener('click',event => {

        if (event.target.closest('details.ordered-visible-table > summary')) event.preventDefault();

    });

    enhance(main); revealHash();

    let pending = false;

    const observer = new MutationObserver(records => {

        if (pending || !records.some(record => [...record.addedNodes].some(node => node.nodeType === 1 && (node.matches('section,article,table,tbody,tr') || node.querySelector('section,table') || /LGD|published village records|polygon population estimates/.test(node.textContent || ''))))) return;

        pending = true;

        requestAnimationFrame(() => {

            observer.disconnect();

            try { enhance(main); } finally { pending = false; observer.observe(main,{childList:true,subtree:true}); }

        });

    });

    observer.observe(main,{childList:true,subtree:true});

}

