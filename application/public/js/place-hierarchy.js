const optionRequests = new Map();
export async function placeOptions(endpoint, language, params = {}) {
    const url = new URL(endpoint, location.origin);
    Object.entries({...params, lang:language}).forEach(([key,value]) => {if (value) url.searchParams.set(key,value);});
    url.searchParams.sort();
    const key = url.href, existing = optionRequests.get(key);
    if (existing && existing.expires > Date.now()) return existing.promise;
    if (optionRequests.size >= 100) optionRequests.delete(optionRequests.keys().next().value);
    const promise = (async () => {
        const response = await fetch(url, {headers:{Accept:'application/json'}});
        if (!response.ok) throw new Error(language === 'hi' ? 'क्षेत्र सूची लोड नहीं हुई। फिर कोशिश करें।' : 'The place list could not load. Please retry.');
        const data = await response.json();
        if (!Array.isArray(data.options)) throw new Error(language === 'hi' ? 'क्षेत्र सूची उपलब्ध नहीं है।' : 'The place list is unavailable.');
        return data.options;
    })();
    optionRequests.set(key, {promise,expires:Date.now()+300000});
    try {return await promise;} catch(error) {if(optionRequests.get(key)?.promise===promise)optionRequests.delete(key);throw error;}
}
export async function placeHierarchy(form, endpoint, language, initial = {}) {
    const hi = language === 'hi';
    const t = (en, hindi) => hi ? hindi : en;
    const fields = Object.fromEntries(['state', 'district', 'subdistrict', 'village'].map(key => [key, form.querySelector(`[name="${key}"]`)]));
    let generation = 0;
    const replace = (field, rows, placeholder) => {
        if (!field) return;
        field.replaceChildren(new Option(placeholder, ''), ...rows.map(row => new Option(row.label || row.name, String(row.code))));
        field.disabled = !rows.length;
    };
    const request = params => placeOptions(endpoint,language,params);
    const change = async (level, selected = {}) => {
        const current = ++generation;
        if (level === 'state') {
            replace(fields.district, [], t('Choose a state first','पहले राज्य चुनें'));
            replace(fields.subdistrict, [], t('All subdistricts','सभी तहसीलें'));
            replace(fields.village, [], t('District overview','जिले की जानकारी'));
            if (!fields.state.value) return;
            const rows = await request({state:fields.state.value});
            if (current !== generation) return;
            replace(fields.district, rows, t('Choose a district','जिला चुनें'));
            fields.district.value = selected.district || '';
        }
        if ((level === 'state' || level === 'district') && fields.district.value && fields.village) {
            const params = {state:fields.state.value,district:fields.district.value};
            replace(fields.subdistrict, [], t('Loading…','लोड हो रहा है…'));
            replace(fields.village, [], t('Loading…','लोड हो रहा है…'));
            const rows = await request({...params, level:'subdistrict'});
            if (current !== generation) return;
            replace(fields.subdistrict, rows, t('All subdistricts','सभी तहसीलें'));
            const wrapper = form.querySelector('[data-subdistrict-field]');
            if (wrapper) wrapper.hidden = rows.length === 0;
            if (fields.subdistrict) fields.subdistrict.value = selected.subdistrict || '';
            const villages = await request({...params,level:'village',subdistrict:fields.subdistrict?.value});
            if (current !== generation) return;
            replace(fields.village, villages, t('District overview','जिले की जानकारी'));
            fields.village.value = selected.village || '';
        }
        if (level === 'subdistrict' && fields.village && fields.district.value) {
            replace(fields.village, [], t('Loading…','लोड हो रहा है…'));
            const rows = await request({state:fields.state.value,district:fields.district.value,level:'village',subdistrict:fields.subdistrict?.value});
            if (current !== generation) return;
            replace(fields.village, rows, t('District overview','जिले की जानकारी'));
        }
    };
    const clearDependents = level => {
        if (level === 'district') {
            replace(fields.subdistrict, [], t('All subdistricts','सभी तहसीलें'));
            replace(fields.village, [], t('District overview','जिले की जानकारी'));
        }
    };
    for (const key of ['state','district','subdistrict']) {
        fields[key]?.addEventListener('change', () => {
            clearDependents(key);
            change(key).catch(error => form.dispatchEvent(new CustomEvent('place-error',{detail:error.message,bubbles:true})));
        });
    }
    replace(fields.state, await request({}), t('Choose a state','राज्य चुनें'));
    const set = async selected => {
        fields.state.value = selected.state || '';
        await change('state', selected);
    };
    if (initial.state) await set(initial);
    return {fields,set};
}
