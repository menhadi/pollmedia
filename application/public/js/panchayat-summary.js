import {paginate} from './place-table-pagination.js';
for (const table of document.querySelectorAll('[data-gp-paginate], .gp-dashboard table')) {
    paginate(table, 50);
}
