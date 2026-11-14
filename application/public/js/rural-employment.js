import {paginate} from './place-table-pagination.js';
for (const table of document.querySelectorAll('[data-rural-paginate]')) { paginate(table, 50); }
