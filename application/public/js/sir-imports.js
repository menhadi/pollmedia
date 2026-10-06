(() => {
    const form = document.querySelector('[data-sir-upload]');
    if (!form) return;
    const button = form.querySelector('button'), progress = form.querySelector('[data-upload-progress]');
    const csrf = form.querySelector('[name=_token]').value;
    const request = async (url, body) => {
        const response = await fetch(url, {method:'POST', body, credentials:'same-origin', headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}});
        let result;
        try { result = await response.json(); } catch { throw new Error('The server could not receive this upload. Your records have not been imported.'); }
        if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Upload failed. Please try again.');
        return result;
    };
    const upload = async (kind, file) => {
        const hash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await file.arrayBuffer())),byte=>byte.toString(16).padStart(2,'0')).join('');
        if (kind === 'records' && hash !== form.elements.sha256.value.trim()) throw new Error('Records JSON checksum does not match the supplied SHA-256.');
        const start = new FormData(); start.set('kind',kind); start.set('size',String(file.size)); start.set('sha256',hash);
        const {token} = await request(form.dataset.start,start);
        let offset = 0;
        while (offset < file.size) {
            const end = Math.min(offset+524288,file.size), chunk = new FormData();
            chunk.set('offset',String(offset)); chunk.set('chunk',file.slice(offset,end),'chunk.bin');
            const result = await request(form.dataset.start+'/'+token,chunk);
            if (result.offset !== end) throw new Error('Upload progress did not match the file. Please start again.');
            offset = end; progress.textContent = (kind==='pdf'?'Official PDF':'Records')+': '+Math.round(offset/file.size*100)+'% uploaded';
        }
        return token;
    };
    form.addEventListener('submit',async event=>{
        event.preventDefault();
        if (button.disabled) return;
        button.disabled = true;
        try {
            for (const [kind,field,maximum] of [['records','records_file',50000000],['pdf','pdf_file',100000000]]) {
                const file = form.elements[field].files[0];
                if (!file || !file.size || file.size > maximum) throw new Error('Choose a '+kind+' file within the displayed size limit.');
                const token = await upload(kind,file);
                let hidden = form.querySelector('[name='+kind+'_token]');
                if (!hidden) { hidden=document.createElement('input');hidden.type='hidden';hidden.name=kind+'_token';form.append(hidden); }
                hidden.value=token;
            }
            progress.textContent='Files checked. Importing entries…';
            form.elements.records_file.disabled=true;form.elements.pdf_file.disabled=true;
            form.submit();
        } catch (error) { progress.textContent=error.message;button.disabled=false; }
    });
})();
