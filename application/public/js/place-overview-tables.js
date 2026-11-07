import {compactReference} from './compact-place-model.js?v=20261107-panchayats';
const hindi=document.documentElement.lang.startsWith('hi')||new URL(location.href).searchParams.get('lang')==='hi';
const create=(tag,cls='',text)=>{const node=document.createElement(tag);node.className=cls;if(text!==undefined)node.textContent=text;return node;};
const summaryContainers = '.metrics,.place-overview .place-facts,.stats,.data-overview,.state-year-metrics,.water-cards,.snapshot-grid,.summary-grid,.stat-grid,.metric-grid';

export function convertSummaries(root) {

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
