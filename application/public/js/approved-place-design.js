import {placeHierarchy, placeOptions} from '/js/place-hierarchy.js';
import {pageSlice, uniquePlaceLinks, valueBarAppearance, compactCountValue} from './compact-place-model.js?v=20261104-polish-4';

const main = document.querySelector('main.dashboard-main, main');
const hi = document.documentElement.lang.startsWith('hi');
const text = (en,hindi) => hi ? hindi : en;
const make = (tag,cls='',value) => { const node=document.createElement(tag); node.className=cls; if(value!==undefined)node.textContent=value; return node; };
const paged = new WeakMap();
const barStyles = new WeakMap();

function compactCounts(root) {
    root.querySelectorAll('td').forEach(cell=>{
        const walker=document.createTreeWalker(cell,NodeFilter.SHOW_TEXT);
        while(walker.nextNode()){
            const node=walker.currentNode;if(node.parentElement.closest('script,style,textarea,code,form'))continue;
            const value=compactCountValue(node.textContent);if(value!==node.textContent)node.textContent=value;
        }
    });
}

function electoralPicker(root) {
    const form=make('form','approved-election-picker');
    const kind=make('select'),state=make('select'),seat=make('select');kind.name='kind';state.name='state';seat.name='seat';
    for(const [value,title] of [['pc','Lok Sabha (PC)'],['ac','State Assembly (AC)']]){const option=make('option','',title);option.value=value;kind.append(option);}
    state.append(make('option','',text('Loading states…','राज्य लोड हो रहे हैं…')));state.firstChild.value='';state.disabled=true;
    const button=make('button','',text('View results →','परिणाम देखें →'));button.type='submit';button.disabled=true;
    const status=make('p','approved-election-status');status.setAttribute('role','status');
    for(const [field,title] of [[kind,text('Election','चुनाव')],[state,text('State / Union Territory','राज्य / केंद्र शासित प्रदेश')],[seat,text('Constituency','निर्वाचन क्षेत्र')]]){const label=make('label','',title);field.setAttribute('aria-label',title);label.append(field);form.append(label);}
    form.append(button,status);
    let request;
    const reset=message=>{seat.replaceChildren(make('option','',message));seat.firstChild.value='';seat.disabled=true;button.disabled=true;};
    const load=async()=>{
        request?.abort();status.textContent='';reset(state.value?text('Loading constituencies…','निर्वाचन क्षेत्र लोड हो रहे हैं…'):text('Choose a state first','पहले राज्य चुनें'));
        if(!state.value)return;
        const controller=new AbortController();request=controller;
        const url=new URL('/',location.origin);url.search=new URLSearchParams({finder:'1',kind:kind.value,state:state.value}).toString();
        try{
            const response=await fetch(url,{signal:controller.signal,headers:{Accept:'application/json'}});if(!response.ok)throw Error();
            const data=await response.json();if(controller.signal.aborted)return;if(!Array.isArray(data.seats))throw Error();
            reset(data.seats.length?text('Choose a constituency','निर्वाचन क्षेत्र चुनें'):text('No constituencies available','निर्वाचन क्षेत्र उपलब्ध नहीं हैं'));
            for(const item of data.seats){const address=new URL(item.url,location.origin);if(address.origin!==location.origin)continue;const option=make('option','',item.name);option.value=address.href;seat.append(option);}
            seat.disabled=seat.options.length<=1;
        }catch(error){if(error.name!=='AbortError'){reset(text('Could not load constituencies','निर्वाचन क्षेत्र लोड नहीं हुए'));status.textContent=text('Choose the state again to retry.','फिर कोशिश करने के लिए राज्य चुनें।');}}
    };
    kind.onchange=state.onchange=load;seat.onchange=()=>button.disabled=!seat.value;
    form.onsubmit=event=>{event.preventDefault();if(!seat.value)return;const address=new URL(seat.value,location.origin);if(address.origin===location.origin)location.assign(address.href);};
    reset(text('Choose a state first','पहले राज्य चुनें'));
    placeOptions('/my-place/options',hi?'hi':'en').then(rows=>{
        state.replaceChildren(make('option','',text('Choose a state','राज्य चुनें')));state.firstChild.value='';
        for(const item of rows){const option=make('option','',item.label||item.name);option.value=item.name;state.append(option);}state.disabled=false;
        const trail=[...root.querySelectorAll('.location-breadcrumbs a,.breadcrumbs a,[aria-label="Location breadcrumb"] a')].map(link=>link.textContent.trim().toLowerCase());
        const matches=rows.filter(item=>trail.includes(item.name.toLowerCase()));if(matches.length===1){state.value=matches[0].name;load();}
    }).catch(()=>{state.replaceChildren(make('option','',text('States unavailable','राज्य उपलब्ध नहीं हैं')));state.firstChild.value='';status.textContent=text('Reload to try again.','फिर कोशिश करने के लिए पृष्ठ खोलें।');});
    return form;
}

function chartColours(root) {
    root.querySelectorAll('.trend-fill,.comparison-track > span,.history-bar-fill,progress').forEach(bar=>{
        const native=bar.matches('progress');
        let value=native ? bar.getAttribute('value') : bar.style.width;
        if(!native && (!value || value.includes('var(')))value=getComputedStyle(bar).getPropertyValue('--value').trim();
        const maximum=native ? (bar.getAttribute('max') || 1) : 100;
        if(!native && !/^[-+]?\d+(?:\.\d+)?%$/.test(value))return;
        const appearance=valueBarAppearance(native ? value : parseFloat(value),maximum);
        if(!appearance)return;
        const signature=JSON.stringify(appearance);if(barStyles.get(bar)===signature)return;
        barStyles.set(bar,signature);
        bar.classList.add(native?'approved-value-progress':'approved-value-bar');
        bar.style.setProperty('--approved-bar-colour',appearance.colour);
        bar.style.setProperty('--approved-bar-strength',appearance.strength+'%');
    });
}

function pageHeading(root) {
    root.querySelectorAll('table a[href]').forEach(link=>{if(/^Edit (?:values|data)$/i.test(link.textContent.trim()))link.remove();});
    const title=root.querySelector('h1'),edit=root.querySelector('.compact-heading-edit');
    if(!title||!edit)return;
    let row=title.closest('.approved-title-row');
    if(!row){row=make('div','approved-title-row');title.before(row);row.append(title);}
    if(edit.parentElement!==row)row.append(edit);
}

function placeLinkName(link) {
    let name=link.querySelector('strong')?.textContent||link.textContent;
    if(/^(?:View|Open|Results|Explore)\b/i.test(name.trim())){
        const row=link.closest('tr'),table=link.closest('table');
        const index=[...(table?.tHead?.rows[0]?.cells||[])].findIndex(cell=>/^(?:Constituency|Place|Village|Subdistrict|District|Name)\b/i.test(cell.textContent.trim()));
        if(row&&index>=0)name=row.cells[index]?.textContent||name;
    }
    if(/^(?:View results|Choose another district|Change district|District map, Census|State population, literacy|Historical graphs)/i.test(name.trim()))return '';
    return name.replace(/[↗→]/g,'').replace(/\s*·\s*(?:AC|PC)\s*\d+/gi,'').replace(/\s+/g,' ').trim();
}

function localSections(root) {
    const satellite=root.querySelector('#satellite-comparison,.satellite-comparison');
    const water=root.querySelector('.jjm-water')||root.querySelector('.water-evidence');
    const own=(node,owner)=>{if(!node||!owner||node===owner||node.contains(owner))return;node.dataset.approvedLocalSource='1';if(node.parentElement!==owner)owner.append(node);};
    own(root.querySelector('#satellite-disclaimer'),satellite);
    own(root.querySelector('#water-disclaimer'),water);
    root.querySelectorAll('.jjm-water > details').forEach(detail=>{if(!detail.querySelector('table')&&/source|dates|identifiers|disclaimer/i.test(detail.querySelector('summary')?.textContent||''))own(detail,water);});
    root.querySelectorAll('[data-page-market]').forEach(panel=>{
        const heading=panel.querySelector('h2');if(heading)heading.textContent=text('Mandi prices and trends','मंडी भाव और रुझान');
        const chooser=panel.querySelector('[data-market-page-district]');
        if(chooser&&[...chooser.options].filter(option=>option.value).length===1){
            const option=[...chooser.options].find(option=>option.value);
            if(chooser.value!==option.value){chooser.value=option.value;chooser.dispatchEvent(new Event('change',{bubbles:true}));}
            chooser.closest('label').hidden=true;
        }
    });
}

function relatedPlaces(root) {
    const relatedCandidates = new Map();
    const selectors = '#elections a[href], #subdistricts a[href], #connections a[href], #related-places a[href], [id^="compact-related-pages-"] a[href], .breadcrumbs a[href], .location-breadcrumbs a[href], .village-breadcrumb-bar a[href], [aria-label="Location breadcrumb"] a[href], .approved-context-links a[href], .dashboard-sidebar [aria-label="Connected constituencies and districts"] a[href]';
    for (const link of [...root.querySelectorAll(selectors), ...document.querySelectorAll('.dashboard-sidebar [aria-label="Connected constituencies and districts"] a[href]')]) {
        if (link.closest('#approved-related-places,.approved-explorer')) continue;
        const label=placeLinkName(link);
        relatedCandidates.set(link.href, {href:link.href,label});
    }
    const priority={PC:0,AC:1,state:2,district:3,subdistrict:4,village:5,town:6};
    const links = uniquePlaceLinks([...relatedCandidates.values()], location.href).sort((a,b)=>(priority[a.type]??7)-(priority[b.type]??7));
    const signature = JSON.stringify(links);
    let section = root.querySelector('#approved-related-places');
    if (section?.dataset.links === signature || !links.length) return;
    if (!section) {
        section=make('section','panel approved-related-places');section.id='approved-related-places';section.dataset.compactGroup='Related';
        (root.querySelector('#compact-related') || root).append(section);
    }
    section.dataset.links=signature;
    const table=make('table');table.createTHead().insertRow().append(make('th','',text('Type','प्रकार')),make('th','',text('Place','स्थान')));
    const body=table.createTBody();
    const types={PC:text('Lok Sabha (PC)','लोकसभा (PC)'),AC:text('Assembly (AC)','विधानसभा (AC)'),state:text('State','राज्य'),district:text('District','जिला'),subdistrict:text('Subdistrict','तहसील'),village:text('Village','गाँव'),town:text('Town','नगर')};
    for (const item of links) {const row=body.insertRow();row.insertCell().textContent=types[item.type]||item.type;const link=make('a','',item.label);link.href=item.href;row.insertCell().append(link);}
    section.replaceChildren(make('h2','',text('Related places','संबंधित स्थान')),table);
    visibleTables(section);
}

function methodology(root) {
    const title=text('Methodology and disclaimer','कार्यप्रणाली और अस्वीकरण');
    let section=root.querySelector('#compact-sources,#approved-methodology');
    const candidates=[...root.querySelectorAll('details.place-methodology,details.compact-chart-notes,details.compact-source-notes,details:has(>summary)')].filter(node=>!node.matches('.approved-disclaimer')&&!node.closest('#local-issues,.issue-post,.approved-office-directory,form,.jjm-water,.water-evidence,.satellite-comparison,[data-approved-local-source]')&&/methodolog|disclaim|sources|coverage|data references|notes|How these constituency links|कार्यप्रणाली|अस्वीकरण|स्रोत|टिप्पणियाँ|डेटा संदर्भ/i.test(node.querySelector(':scope > summary')?.textContent||'')&&!node.querySelector('table,form,input,canvas,svg,[data-hub-module],[data-market-source-status],[data-frame-status]'));
    const notes=[...root.querySelectorAll('.place-facts-heading > p,.hub-heading p,.panel > p.muted,.district-glance > p,.panel > p.notice,.place-overview > p.place-note,#sources > p:not(:has(a)),#elections > p:not(:has(a)),[data-methodology-note]')].filter(node=>!node.closest('#local-issues,.issue-post,.approved-office-directory,.approved-data-notes,table,form,.jjm-water,.water-evidence,.satellite-comparison,[data-approved-local-source]')&&!node.querySelector('input,button,canvas,table')&&!node.matches('[role=status],[aria-live]'));
    if (!section && (candidates.length || notes.length)) {section=make('section','place-panel approved-methodology');section.id='approved-methodology';section.dataset.compactGroup='Sources';section.append(make('h2','',title));root.append(section);}
    if (!section) return;
    const heading=section.querySelector(':scope > h2');if(heading)heading.textContent=title;
    section.querySelectorAll('#sources > h2').forEach(node=>node.textContent=text('Source records','स्रोत रिकॉर्ड'));
    let references=section.querySelector('.approved-source-links');
    if(!references){references=make('ul','approved-source-links');section.append(references);}
    const known=new Set([...references.querySelectorAll('a')].map(a=>a.href));
    const addSource=(href,label)=>{if(!href||known.has(href))return;known.add(href);const item=make('li'),link=make('a','',label||text('Official source','आधिकारिक स्रोत'));link.href=href;item.append(link);references.append(item);};
    root.querySelectorAll('[data-fact-source]').forEach(row=>addSource(row.dataset.factSource,text('Facts','तथ्य')+' · '+(row.cells?.[0]?.textContent||'')+' · '+(row.dataset.factPeriod||'')));
    root.querySelectorAll('a.compact-source-link').forEach(link=>{addSource(link.href,link.getAttribute('aria-label')||link.textContent);link.remove();});
    for(const node of [...candidates,...notes]){
        if(section.contains(node)&&node.matches('details'))continue;
        if(node.matches('details')){section.append(node);continue;}
        if(/^(?:What the figures mean|People · water · soil|Population, literacy, water and soil highlights)/i.test(node.textContent.trim())){node.remove();continue;}
        let details=section.querySelector('.approved-data-notes');if(!details){details=make('details','approved-data-notes');details.append(make('summary','',text('Data references','डेटा संदर्भ')));section.append(details);}details.append(node);
    }
    root.querySelectorAll('p.small,p.place-note,.hub-body > p,#sources p,.approved-data-notes p').forEach(note=>{
        if(note.closest('#local-issues,.issue-post,form,table')||note.matches('[role=status],[aria-live],[data-market-status],[data-frame-status]')||note.querySelector('a,button,input'))return;
        if(/^(?:Click|Tap|Scroll|Choose a crop|Open a constituency|Upload crop or field photos|Find programmes for your age)|The bars use the same|use the left axis;|This page (?:shows|uses)|Published database records are read on every request/i.test(note.textContent.trim()))note.hidden=true;
    });
    let disclosure=section.querySelector(':scope > .approved-disclaimer');
    if(!disclosure){
        disclosure=make('details','approved-disclaimer');
        disclosure.append(make('summary','',title),make('div','approved-disclaimer-body'));
        section.append(disclosure);
    }
    const disclosureHeading=disclosure.querySelector(':scope > summary');
    if(disclosureHeading&&disclosureHeading.textContent!==title)disclosureHeading.textContent=title;
    const content=disclosure.querySelector(':scope > .approved-disclaimer-body');
    for(const child of [...section.children]){
        if(child===disclosure||child.matches('.compact-group-title'))continue;
        if(child===heading){child.hidden=true;continue;}
        content.append(child);
    }
}

function paginate(table) {
    if (paged.has(table)) { paged.get(table)(); return; }
    if (table.querySelector('[rowspan]')) return;
    if (!table.tBodies.length || table.closest('[data-local-data-browser]') || table.parentElement.nextElementSibling?.matches('.compact-pager')) return;
    if ([...table.tBodies].reduce((sum,body)=>sum+body.rows.length,0)<=50) return;
    let page=1,size=50;
    const bar=make('nav','approved-pagination');bar.setAttribute('aria-label',text('Table pages','तालिका पृष्ठ'));
    const count=make('span');count.setAttribute('aria-live','polite');
    const select=make('select');select.setAttribute('aria-label',text('Rows per page','प्रति पृष्ठ पंक्तियाँ'));
    for(const number of [50,100,'All']){const option=make('option','',String(number));option.value=String(number);select.append(option);}
    const previous=make('button','',text('Previous','पिछला')),next=make('button','',text('Next','अगला')),position=make('span');previous.type=next.type='button';
    bar.append(count,select,previous,position,next);
    (table.closest('.table-scroll,.table-wrap,.place-table-scroll,.compact-table-wrap')||table).after(bar);
    const render=()=>{
        const rows=[...table.tBodies].flatMap(body=>[...body.rows]);
        const eligible=rows.filter(row=>!row.hidden&&row.style.display!=='none');
        const slice=pageSlice(eligible,page,size==='All'?Math.max(1,eligible.length):Number(size));page=slice.page;
        const shown=new Set(slice.rows);rows.forEach(row=>row.toggleAttribute('data-approved-page-hidden',!shown.has(row)));
        count.textContent=`${slice.start}–${slice.end} / ${slice.total}`;position.textContent=`${page} / ${slice.pages}`;previous.disabled=page===1;next.disabled=page===slice.pages;
    };
    previous.onclick=()=>{page--;render();};next.onclick=()=>{page++;render();};select.onchange=()=>{size=select.value;page=1;render();};
    table.tHead?.addEventListener('click',()=>setTimeout(render,0));table.closest('section')?.addEventListener('input',()=>{page=1;setTimeout(render,0);});
    paged.set(table,render);render();
}

function visibleTables(root) {
    root.querySelectorAll('details').forEach(detail=>{
        if(detail.closest('.approved-disclaimer'))return;
        if(detail.querySelector('table')||detail.matches('[data-market-page-details]')){detail.open=true;detail.classList.add('ordered-visible-table');}
    });
    root.querySelectorAll('table').forEach(table=>{
        for(let parent=table.parentElement;parent&&parent!==root&&!parent.matches('.compact-group,section,article');parent=parent.parentElement){parent.classList.add('approved-table-flow');}
        const columns=Math.max(1,...[...(table.tHead?.rows||[])].map(row=>[...row.cells].filter(cell=>!cell.hidden).reduce((total,cell)=>total+cell.colSpan,0)),table.rows[0]?.cells.length||0);
        const minimum=`${Math.max(300,columns*135)}px`;
        if(table.style.getPropertyValue('--approved-table-min-width')!==minimum)table.style.setProperty('--approved-table-min-width',minimum);
        if(!table.parentElement.matches('.table-scroll,.table-wrap,.place-table-scroll,.compact-table-wrap,.scroll')){const wrap=make('div','compact-table-wrap');table.before(wrap);wrap.append(table);}
        if(main.matches('.my-place'))paginate(table);
    });
}

function linkList(links,searchable=false) {
    const list=make('ul','approved-tree-links');
    const seen=new Set();
    for(const original of links){
        if(!original.href||seen.has(original.href)||original.hash)continue;
        const name=placeLinkName(original);if(!name)continue;
        seen.add(original.href);const item=make('li'),link=make('a','',name);link.href=original.href;item.append(link);list.append(item);
    }
    if(!list.children.length)return null;
    let page=1;const search=make('input');search.type='search';search.placeholder=text('Search places','क्षेत्र खोजें');search.setAttribute('aria-label',search.placeholder);
    const wrap=make('div','approved-tree-branch');const bar=make('div','approved-pagination');const count=make('span'),prev=make('button','',text('Previous','पिछला')),next=make('button','',text('Next','अगला'));prev.type=next.type='button';
    const update=()=>{const rows=[...list.children].filter(row=>row.textContent.toLocaleLowerCase().includes(search.value.toLocaleLowerCase()));const slice=pageSlice(rows,page,8);page=slice.page;const shown=new Set(slice.rows);[...list.children].forEach(row=>row.hidden=!shown.has(row));count.textContent=`${slice.start}–${slice.end} / ${slice.total}`;prev.disabled=page===1;next.disabled=page===slice.pages;bar.hidden=list.children.length<=8;};
    search.oninput=()=>{page=1;update();};prev.onclick=()=>{page--;update();};next.onclick=()=>{page++;update();};bar.append(count,prev,next);if(searchable)wrap.append(search);wrap.append(list,bar);update();return wrap;
}

function hierarchyPicker(panel,root) {
    const form=make('form','approved-hierarchy');form.action='/my-place';form.method='get';
    const status=make('p','approved-hierarchy-status');status.setAttribute('role','status');
    for(const [name,title] of [['state',text('State','राज्य')],['district',text('District','जिला')],['subdistrict',text('Subdistrict','तहसील')],['village',text('Village','गाँव')]]){
        const label=make('label','',title),field=make('select');field.name=name;field.setAttribute('aria-label',title);field.disabled=true;field.append(make('option','',text('Loading…','लोड हो रहा है…')));field.required=['state','district'].includes(name);label.append(field);form.append(label);
    }
    const open=make('button','',text('Open selected place →','चुना क्षेत्र खोलें →'));open.type='submit';form.append(open,status);panel.prepend(form);
    let touched=false;form.addEventListener('change',()=>{touched=true;});form.addEventListener('place-error',event=>{status.textContent=event.detail;});
    const normalized=value=>value.toLocaleLowerCase().replace(/\b(?:state|district|dashboard)\b|→/g,'').replace(/\s+/g,' ').trim();
    const exact=(field,name)=>{const found=[...field.options].filter(option=>option.value&&normalized(option.textContent)===normalized(name));return found.length===1?found[0].value:null;};
    placeHierarchy(form,'/my-place/options',hi?'hi':'en').then(async hierarchy=>{
        const trail=[...root.querySelectorAll('[aria-label=Breadcrumb] a,[aria-label=\"Location breadcrumb\"] a,.location-breadcrumbs a,.breadcrumbs a')];
        const state=trail.find(link=>/\/india\/(?:state|states|census\/states)\//.test(link.pathname)&&exact(hierarchy.fields.state,link.textContent));
        const district=trail.find(link=>/\/india\/(?:districts|district|census\/districts)\//.test(link.pathname));
        const stateName=state?.textContent||(/\/(?:state|states)\//.test(location.pathname)?root.querySelector('h1')?.textContent:'');
        const stateCode=stateName&&exact(hierarchy.fields.state,stateName);if(!stateCode||touched)return;
        await hierarchy.set({state:stateCode});
        const districtName=district?.textContent||(/\/districts\//.test(location.pathname)?root.querySelector('h1')?.textContent:'');
        const districtCode=districtName&&exact(hierarchy.fields.district,districtName);if(districtCode&&!touched)await hierarchy.set({state:stateCode,district:districtCode});
    }).catch(error=>{status.textContent=error.message;});
}

function explorer(root,scopeMain=true) {
    if(root.querySelector('#approved-place-explorer'))return;
    const section=make('section','panel approved-explorer');section.id='approved-place-explorer';section.dataset.compactGroup='Places';section.append(make('h2','',text('Explore this place','इस क्षेत्र को खोजें')));
    const controls=make('div','approved-explorer-tabs');controls.setAttribute('role','tablist');controls.setAttribute('aria-label',text('Place connections','क्षेत्र संबंध'));
    const administrative=make('button','',text('Administrative places','प्रशासनिक क्षेत्र')),electoral=make('button','',text('Electoral areas','निर्वाचन क्षेत्र'));administrative.type=electoral.type='button';
    const adminPanel=make('div','approved-tree-panel'),electoralPanel=make('div','approved-tree-panel');adminPanel.id='approved-administrative';electoralPanel.id='approved-electoral';
    const all=[...root.querySelectorAll('a[href]'),...document.querySelectorAll('.dashboard-sidebar a[href]')].filter(link=>!link.closest('.public-header,.approved-explorer,.issue-post'));
    const adminGroups=[
        [text('State','राज्य'),all.filter(link=>/\/india\/(?:census\/states|state|states)\//.test(link.pathname))],
        [text('Districts','जिले'),all.filter(link=>/\/india\/(?:districts|district|census\/districts)\//.test(link.pathname))],
        [text('Subdistricts','तहसीलें'),[...root.querySelectorAll('#subdistricts tbody a[href]')]],
        [text('Panchayats','पंचायतें'),all.filter(link=>/panchayat/.test(link.pathname))],
        [text('Villages and towns','गाँव और कस्बे'),[...root.querySelectorAll('#villages tbody a[href],#towns tbody a[href]'),...all.filter(link=>/\/india\/(?:villages?|towns|census\/(?:villages|towns))\//.test(link.pathname))]],
    ];
    for(const [title,links] of adminGroups){const list=linkList(links,title===text('Villages and towns','गाँव और कस्बे'));if(!list)continue;const branch=make('details','approved-tree-node');branch.open=true;branch.append(make('summary','',title),list);adminPanel.append(branch);}
    electoralPanel.append(electoralPicker(root));
    for(const [kind,title] of [['pc','Lok Sabha (PC)'],['ac','State Assembly (AC)']]){
        const links=all.filter(link=>new RegExp(`/india/(?:constituencies/${kind}|${kind})/`).test(link.pathname));
        const list=linkList(links);if(!list)continue;
        const branch=make('details','approved-tree-node approved-electoral-connections');branch.open=true;
        branch.append(make('summary','',title),list);electoralPanel.append(branch);
    }
    hierarchyPicker(adminPanel,root);
    if(!adminPanel.children.length)adminPanel.append(make('p','',text('Related places are listed below.','संबंधित क्षेत्र नीचे दिए गए हैं।')));
    if(!electoralPanel.children.length)electoralPanel.append(make('p','',text('No verified constituency link is available for this place.','इस क्षेत्र के लिए पुष्ट निर्वाचन संबंध उपलब्ध नहीं है।')));
    const select=useAdmin=>{adminPanel.hidden=!useAdmin;electoralPanel.hidden=useAdmin;administrative.setAttribute('aria-selected',String(useAdmin));electoral.setAttribute('aria-selected',String(!useAdmin));};
    for(const [button,panel] of [[administrative,adminPanel],[electoral,electoralPanel]]){button.setAttribute('role','tab');button.setAttribute('aria-controls',panel.id);panel.setAttribute('role','tabpanel');}
    administrative.onclick=()=>select(true);electoral.onclick=()=>select(false);controls.append(administrative,electoral);section.append(controls,adminPanel,electoralPanel);select(true);
    if(scopeMain&&/\/constituencies\/(?:pc|ac)\//.test(location.pathname)&&document.querySelector('.dashboard-sidebar')) {
        administrative.textContent=text('Places','स्थान');electoral.textContent='PC / AC';
        administrative.setAttribute('aria-label',text('Administrative places','प्रशासनिक क्षेत्र'));electoral.setAttribute('aria-label',text('Electoral areas','निर्वाचन क्षेत्र'));
        document.querySelector('.dashboard-sidebar').append(section);
    }
    else if(scopeMain)root.append(section);else(root.querySelector('.place-overview')||root).append(section);
}

function mobileJump(root) {
    let select=root.querySelector(':scope > .approved-section-jump');
    if(!select){select=make('select','approved-section-jump');select.setAttribute('aria-label',text('Jump to section','अनुभाग पर जाएँ'));select.onchange=()=>{const target=document.getElementById(select.value);if(target){target.scrollIntoView({block:'start'});history.replaceState(null,'',location.pathname+location.search+'#'+target.id);}};const nav=root.querySelector('.compact-nav');if(nav)nav.after(select);else root.querySelector('header')?.after(select);}
    const targets=[...root.querySelectorAll(':scope > .compact-group,[data-place-result] .hub-section,[data-place-result] .place-overview,[data-place-result] #approved-methodology')];
    const signature=targets.map(target=>target.id).join();if(select.dataset.signature===signature)return;select.dataset.signature=signature;select.replaceChildren(make('option','',text('Jump to section','अनुभाग पर जाएँ')));select.firstChild.value='';
    targets.forEach((target,index)=>{target.id||=`my-place-section-${index}`;const title=target.querySelector('h2,h3')?.textContent.trim();if(title){const option=make('option','',title);option.value=target.id;select.append(option);}});
}

function myPlace(root) {
    document.body.classList.add('compact-place','approved-my-place');root.classList.add('approved-my-place-main');
    const header=root.querySelector(':scope > header');
    const views=make('nav','community-tabs approved-my-place-tabs');views.setAttribute('aria-label',text('Place views','क्षेत्र दृश्य'));
    const overview=make('button','',text('Overview & data','जानकारी और आँकड़े')),issues=make('button','',text('Community issues','सामुदायिक समस्याएँ'));overview.type=issues.type='button';views.append(overview,issues);header?.after(views);
    const show=issueView=>{
        root.dataset.placeView=issueView?'issues':'overview';overview.setAttribute('aria-pressed',String(!issueView));issues.setAttribute('aria-pressed',String(issueView));
        root.querySelectorAll('[data-approved-concealed]').forEach(node=>node.removeAttribute('data-approved-concealed'));
        const panel=root.querySelector('#local-issues');if(!panel)return;
        panel.hidden=!issueView;
        if(issueView){for(let node=panel;node&&node!==root;node=node.parentElement){for(const sibling of node.parentElement.children)if(sibling!==node&&sibling!==header&&sibling!==views)sibling.setAttribute('data-approved-concealed','');}}
    };
    overview.onclick=()=>show(false);issues.onclick=()=>show(true);
    root.addEventListener('click',event=>{if(event.target.closest('a[href="#local-issues"]')){event.preventDefault();show(true);}if(event.target.closest('details.ordered-visible-table > summary'))event.preventDefault();});
    const enhance=()=>{
        root.querySelectorAll('.place-facts').forEach(facts=>{
            if(facts.dataset.approvedTable||!facts.querySelector(':scope > article'))return;
            const table=make('table');table.createTHead().insertRow().append(make('th','',text('Measure','माप')),make('th','',text('Value','मान')));const body=table.createTBody();
            [...facts.children].forEach(card=>{if(!card.matches('article'))return;const row=body.insertRow(),name=card.querySelector(':scope > span'),value=card.querySelector('strong');row.append(make('th','',name?.textContent||''));row.firstChild.scope='row';const cell=row.insertCell();if(value)cell.append(value);card.querySelectorAll('small').forEach(note=>cell.append(note));});
            facts.replaceChildren(table);facts.dataset.approvedTable='1';
        });
        const result=root.querySelector('[data-place-result]')||root;
        if(header){
            let actions=header.querySelector('.approved-my-place-actions');
            if(!actions){actions=make('div','place-actions approved-my-place-actions');header.append(actions);const change=root.querySelector('[data-manual]');if(change)actions.append(change);}
            const original=result.querySelector('.place-overview .place-actions a[href]');
            if(original&&actions.querySelector('a')?.href!==original.href){actions.querySelector('a')?.remove();const open=original.cloneNode(true);open.textContent=/villages?/.test(original.pathname)?text('Open village page →','गाँव का पृष्ठ खोलें →'):text('Open place page →','क्षेत्र का पृष्ठ खोलें →');actions.append(open);}
        }
        if(result.querySelector('.place-overview')&&!result.querySelector('.approved-context-links'))explorer(result,false);
        if(!result.querySelector('.approved-context-links'))relatedPlaces(result);
        localSections(result);methodology(result);compactCounts(root);chartColours(root);visibleTables(root);mobileJump(root);show(root.dataset.placeView==='issues');
    };
    enhance();let scheduled=false;
    const observer=new MutationObserver(records=>{if(scheduled||!records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1&&(node.matches('section,table,tr,details')||node.querySelector('section,table')))))return;scheduled=true;requestAnimationFrame(()=>{observer.disconnect();try{enhance();}finally{scheduled=false;observer.observe(root,{subtree:true,childList:true});}});});observer.observe(root,{subtree:true,childList:true});
}

function constituencyLayout(root) {
    if(!/\/constituencies\/(?:pc|ac)\//.test(location.pathname))return;
    root.querySelectorAll('a[href="#census-context"]').forEach(link=>link.remove());
    const analysis=root.querySelector('#constituency-analysis');
    const table=analysis?.querySelector('.compact-summary-table');
    if(table){
        const summary=make('section','panel');summary.id='approved-election-overview';summary.dataset.compactGroup='Overview';summary.append(make('h2','',text('Election overview','चुनाव सारांश')));
        const wrap=table.closest('.compact-table-wrap')||table;summary.append(wrap);root.append(summary);analysis.querySelector(':scope > h2')?.remove();
    }
    const directory=document.querySelector('.approved-office-directory'),sidebar=document.querySelector('.dashboard-sidebar');
    if(directory&&sidebar){
        const portal=make('div','approved-mobile-representative');
        const heading=root.querySelector('.compact-nav');if(heading)heading.after(portal);else root.prepend(portal);
        const small=matchMedia('(max-width:800px)');
        const place=()=>{if(small.matches)portal.append(directory);else sidebar.prepend(directory);};small.addEventListener('change',place);place();
    }
}

if(main){
    document.body.classList.add('approved-place-design');
    if(main.matches('.my-place'))myPlace(main);
    else{
        constituencyLayout(main);explorer(main);relatedPlaces(main);localSections(main);methodology(main);compactCounts(main);chartColours(main);pageHeading(main);mobileJump(main);visibleTables(main);
        let pending=false;
        const relevant=record=>record.type==='attributes' ? record.target.matches('.trend-fill,.comparison-row,.comparison-track > span,.history-bar-fill,progress') : [...record.addedNodes].some(node=>node.nodeType===1&&(node.matches('section,table,tbody,tr,details,.compact-group,.trend-row,.comparison-row,.history-bar-row,progress')||node.querySelector('.trend-fill,.comparison-track > span,.history-bar-fill,progress')));
        const watch={childList:true,subtree:true,attributes:true,attributeFilter:['style','value','max']};
        const observer=new MutationObserver(records=>{if(pending||!records.some(relevant))return;pending=true;requestAnimationFrame(()=>{observer.disconnect();try{relatedPlaces(main);localSections(main);methodology(main);compactCounts(main);chartColours(main);pageHeading(main);mobileJump(main);visibleTables(main);}finally{pending=false;observer.observe(main,watch);}});});observer.observe(main,watch);
    }
}
