import {paginate} from './place-table-pagination.js';
for (const table of document.querySelectorAll('[data-gp-paginate]')) {
    paginate(table, 50);
}
