(() => {
    const config = document.querySelector('[data-community-edit]');
    if (!config) return;
    const editLinks = [...document.querySelectorAll('a[href]')].filter(link => {
        const url = new URL(link.href, location.href);
        return url.origin === location.origin && /^Edit\b/i.test(link.textContent.trim()) && /^(?:\/contribute\/|\/data\/census\/records\/)/.test(url.pathname);
    });

    const activeForms = new Set(), loaded = new Map(), fullyLoaded = new Set();
    let banner, changeHistory, busy = false;
    const historyEntries = new Set();
    const returnPath = () => {
        const url = new URL(location.href); url.searchParams.set('edit', '1');
        return url.pathname + url.search + url.hash;
    };
    const showStatus = text => {
        if (!banner) {
            banner = document.createElement('aside'); banner.className = 'page-edit-status'; banner.setAttribute('role', 'status');
            const message = document.createElement('p'); banner.append(message);
            const close = document.createElement('button'); close.type = 'button'; close.textContent = 'Done editing';
            close.onclick = () => { const url = new URL(location.href); url.searchParams.delete('edit'); location.assign(url.pathname + url.search); };
            banner.append(close); (editLinks[0]?.closest('p') || document.querySelector('main h1') || document.querySelector('main')).after(banner);
        }
        banner.querySelector('p').textContent = text;
    };
    const metadata = row => ({record:row.dataset.sourceRecord, kind:row.dataset.sourceKind || 'census', year:row.dataset.sourceYear || '', residence:row.dataset.sourceResidence || '', field:row.dataset.sourceField});
    const attachedCells = new WeakSet();
    const cellEditor = (cell, sourceForm, meta) => {
        if (!sourceForm || attachedCells.has(cell) || !cell.closest('table')) return;
        const form = sourceForm.cloneNode(true);
        const fieldLabel = meta.field === 'listing_name' ? 'place name' : meta.field;
        const value = form.querySelector('[name=value]'), format = form.querySelector('[name=value_type]');
        const reason = form.querySelector('[name=reason]'), reference = form.querySelector('[name=reference_url]');
        if (!value || !reason) return;
        form.className = 'cell-correction-form';
        value.setAttribute('aria-label', `Edit ${fieldLabel}`);
        reason.setAttribute('aria-label', 'Reason for correction'); reason.placeholder = 'Reason for correction (required)'; reason.rows = 2;
        if (reference) { reference.setAttribute('aria-label', 'Supporting source URL'); reference.placeholder = 'Supporting source URL (optional)'; }
        const hidden = [...form.querySelectorAll('input[type=hidden]')];
        const back = document.createElement('input'); back.type = 'hidden'; back.name = 'return_to'; back.value = returnPath();
        const values = document.createElement('div'); values.className = 'cell-correction-value'; values.append(value);
        if (format) { format.setAttribute('aria-label', 'Value format'); values.append(format); }
        const actions = document.createElement('div'); actions.className = 'cell-correction-actions';
        const save = document.createElement('button'); save.type = 'submit'; save.textContent = 'Submit for review';
        const cancel = document.createElement('button'); cancel.type = 'button'; cancel.textContent = 'Cancel'; cancel.className = 'cell-correction-cancel'; actions.append(save, cancel);
        form.replaceChildren(...hidden, back, values, reason, ...(reference ? [reference] : []), actions);
        const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'cell-edit-button'; edit.textContent = 'Edit'; edit.setAttribute('aria-label', `Edit ${fieldLabel}`);
        Object.assign(edit.dataset, {sourceKind:meta.kind, sourceRecord:meta.record, sourceField:meta.field});
        let original;
        edit.onclick = () => {
            original = document.createDocumentFragment(); while (cell.firstChild) original.append(cell.firstChild);
            cell.append(form); cell.classList.add('cell-is-editing'); value.focus(); value.select();
        };
        const close = () => { form.reset(); cell.replaceChildren(original); cell.classList.remove('cell-is-editing'); edit.focus(); };
        cancel.onclick = close;
        form.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); close(); } });
        form.addEventListener('submit', () => { back.value = returnPath(); save.disabled = true; save.textContent = 'Submitting…'; });
        cell.append(edit); attachedCells.add(cell); activeForms.add(`${meta.kind}:${meta.record}:${meta.field}`);
    };
    const attach = (row, meta) => {
        const form = row.querySelector('form');
        if (!form || !meta.field || !meta.record) return;
        document.querySelectorAll('td[data-correction-record][data-correction-field],th[data-correction-record][data-correction-field]').forEach(cell => {
            if (cell.dataset.correctionRecord === meta.record && cell.dataset.correctionField === meta.field && (cell.dataset.correctionKind || 'census') === meta.kind) cellEditor(cell, form, meta);
        });
    };
    const adoptEmbedded = () => {
        document.querySelectorAll('tr[data-source-record][data-source-field]').forEach(row => {
            const details = row.querySelector('details.inline-correction');
            if (!details || !row.cells[1]) return;
            const meta = metadata(row), cell = row.cells[1];
            Object.assign(cell.dataset, {correctionRecord:meta.record, correctionField:meta.field, correctionKind:meta.kind});
            cellEditor(cell, details.querySelector('form'), meta); details.remove();
        });
        document.querySelectorAll('td[data-correction-record][data-correction-field]').forEach(cell => {
            const details = cell.querySelector('details.inline-correction'); if (!details) return;
            cellEditor(cell, details.querySelector('form'), {record:cell.dataset.correctionRecord, field:cell.dataset.correctionField, kind:cell.dataset.correctionKind || 'census'}); details.remove();
        });
    };
    const load = async (url, targetsOnly = false) => {
        const address = new URL(url, location.href);
        if (address.origin !== location.origin || !/^(?:\/contribute\/|\/data\/census\/records\/)/.test(address.pathname)) return;
        if (fullyLoaded.has(address.href)) return;
        let page = loaded.get(address.href);
        if (!page) {
            const response = await fetch(url, {credentials:'same-origin', cache:'no-store', headers:{'X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok) throw new Error('The edit controls could not be loaded. Please try again.');
            if (new URL(response.url).pathname === config.dataset.login) throw new Error('Please sign in again to edit data.');
            page = new DOMParser().parseFromString(await response.text(), 'text/html');
            loaded.set(address.href, page);
        }
        page.querySelectorAll('tr[data-source-record][data-source-field]').forEach(row => {
            const meta = metadata(row);
            if (targetsOnly && ![...document.querySelectorAll('[data-correction-record][data-correction-field]')].some(cell => cell.dataset.correctionRecord === meta.record && cell.dataset.correctionField === meta.field && (cell.dataset.correctionKind || 'census') === meta.kind)) return;
            attach(row, meta);
        });
        if (targetsOnly) return;
        fullyLoaded.add(address.href);
        page.querySelectorAll('#change-history article').forEach(entry => {
            const key = entry.textContent.trim();
            if (historyEntries.has(key)) return;
            historyEntries.add(key);
            if (!changeHistory) {
                changeHistory = document.createElement('section'); changeHistory.className = 'page-edit-history';
                const heading = document.createElement('h2'); heading.textContent = 'Published change history'; changeHistory.append(heading);
                (document.querySelector('main') || document.body).append(changeHistory);
            }
            changeHistory.append(entry);
        });
        const related = [...page.querySelectorAll('a[data-edit-related]')].map(link => link.href);
        for (const link of related) await load(link);

    };
    let loginDialog;
    const showLogin = () => {
        if (!loginDialog) {
            const hindi = document.documentElement.lang.startsWith('hi');
            loginDialog = document.createElement('dialog'); loginDialog.className = 'compact-login-dialog';
            loginDialog.setAttribute('aria-labelledby', 'community-login-title');
            const title = document.createElement('h2'); title.id = 'community-login-title'; title.textContent = hindi ? 'डेटा संपादित करने के लिए साइन इन करें' : 'Sign in to edit data';
            const message = document.createElement('p'); message.textContent = hindi ? 'बदलाव सुझाने के लिए अपने खाते में साइन इन करें।' : 'Sign in to suggest a correction. Your current page will be kept.';
            const actions = document.createElement('div'); actions.className = 'compact-dialog-actions';
            const signIn = document.createElement('a'); signIn.textContent = hindi ? 'साइन इन' : 'Sign in';
            const cancel = document.createElement('button'); cancel.type = 'button'; cancel.textContent = hindi ? 'वापस जाएँ' : 'Keep browsing'; cancel.onclick = () => loginDialog.close();
            actions.append(signIn,cancel); loginDialog.append(title,message,actions); document.body.append(loginDialog);
            loginDialog.addEventListener('click', event => { if (event.target === loginDialog) { const bounds=loginDialog.getBoundingClientRect(); if (event.clientX<bounds.left || event.clientX>bounds.right || event.clientY<bounds.top || event.clientY>bounds.bottom) loginDialog.close(); } });
        }
        loginDialog.querySelector('a').href = `${config.dataset.login}?next=${encodeURIComponent(returnPath())}`;
        if (!loginDialog.open) loginDialog.showModal();
    };
    document.addEventListener('community-edit-request', async event => {
        const {url,cell} = event.detail || {};
        const link = url ? {href:url} : editLinks[0];
        await start(link, cell);
        if (config.dataset.signedIn === '1') cell?.querySelector('.cell-edit-button')?.click();
    });
    document.addEventListener('click', event => {
        const link = event.target.closest('a.cell-sign-in');
        if (!link || config.dataset.signedIn === '1' || !document.body.classList.contains('compact-place')) return;
        event.preventDefault(); showLogin();
    });
    const start = async (link, targetCell = null) => {
        if (config.dataset.signedIn !== '1') {
            showLogin(); return;
        }
        if (busy) return;
        document.body.classList.add('page-data-editing');
        busy = true; history.replaceState(null, '', returnPath()); showStatus('Loading edit controls…');
        try {
            const cells = targetCell?.dataset.correctionRecord ? [targetCell] : [...document.querySelectorAll('[data-correction-record]')];
            const sources = new Set(cells.filter(cell => !attachedCells.has(cell)).map(cell => {
                const id = cell.dataset.correctionRecord;
                return /^[0-9]+$/.test(id) ? `${cell.dataset.correctionKind === 'election' ? '/contribute/election/' : '/data/census/records/'}${id}` : null;
            }).filter(Boolean));
            const sourceList = [...sources];
            for (let i = 0; i < sourceList.length; i += 3) await Promise.all(sourceList.slice(i, i + 3).map(source => load(source, true)));
            if (link && !targetCell?.dataset.correctionRecord) await load(link.href);
            showStatus(config.dataset.message || 'Click Edit in the table cell to change its value. Admin approval is required.');
            document.querySelectorAll('.chart-values').forEach(table => table.open = true);
            document.querySelectorAll('details:has(.cell-edit-button)').forEach(table => table.open = true);
            if (!activeForms.size) showStatus('These are calculated totals. Edit their underlying counts in the source tables.');
        } catch(error) { showStatus(error.message); }
        finally { busy = false; }
    };
    editLinks.forEach(link => {
        link.classList.add('place-edit-button');
        const source = new URL(link.href, location.href).pathname.match(/^\/data\/census\/records\/(\d+)/);
        if (source) {
            const cell = document.querySelector(`[data-correction-record="${source[1]}"]`);
            const table = cell?.closest('table');
            if (table) {
                const action = link.closest('p') || link;
                (table.closest('.place-table-scroll,.table-scroll,.table-wrap') || table).after(action);
            }
        }
        link.addEventListener('click', event => { if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); start(link); });
    });
    adoptEmbedded();
    if (new URL(location.href).searchParams.get('edit') === '1') start(editLinks[0]);
})();
