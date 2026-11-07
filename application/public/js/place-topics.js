(() => {
    'use strict';
    const doc=document, html=doc.documentElement;
    if(html.classList.contains('pm-js'))return;
    html.classList.add('pm-js');
    const hi=html.lang.startsWith('hi')||new URL(location.href).searchParams.get('lang')==='hi';
    const label=(en,hindi)=>hi?hindi:en;
    const clean=url=>{const u=new URL(url,location.href);u.searchParams.delete('pm_panel');return u;};
    const reduced=()=>matchMedia('(prefers-reduced-motion: reduce)').matches;
    const framed=window.parent!==window && window.frameElement?.classList.contains('pm-topic-frame');
    const primary=()=>doc.querySelector('[data-place-topics]:not([data-place-topics="member"])');
    const bodyFor=nav=>nav?.parentElement?.querySelector(':scope > [data-topic-body]');
    function emptyState(){
        const nav=primary(),panel=bodyFor(nav);
        if(!panel || panel.innerText.trim() || panel.querySelector('iframe,canvas,[role=status],svg'))return;
        const note=doc.createElement('p');note.textContent=label('No published records are available for this selection.','इस चयन के लिए प्रकाशित रिकॉर्ड उपलब्ध नहीं हैं।');panel.append(note);
    }
    async function pageLongTables(){
        const panel=bodyFor(primary());
        if(!panel)return;
        const tables=[...panel.querySelectorAll('table')].filter(t=>t.rows.length>21 && !t.closest('[data-local-data-browser]'));
        if(!tables.length)return;
        const {paginate}=await import('/js/place-table-pagination.js?v=1');
        for(const table of tables)paginate(table,20);
    }
    pageLongTables().catch(()=>{});
    doc.addEventListener('pm-topic-mounted',()=>{emptyState();pageLongTables().catch(()=>{});});
    async function overviewTables(){
        const nav=primary(),panel=bodyFor(nav);
        if(nav?.dataset.activeTopic!=='overview'||!panel || !panel.querySelector('.metrics,.place-facts,.stats,.snapshot-grid'))return;
        const {convertSummaries}=await import('/js/place-overview-tables.js?v=1');
        convertSummaries(panel);
    }
    overviewTables().catch(()=>{});
    doc.addEventListener('pm-topic-mounted',()=>overviewTables().catch(()=>{}));
    emptyState();
    if(framed){
        const body=bodyFor(primary());
        if(!body)return;
        html.classList.add('pm-framed');
        for(let el=body;el&&el!==doc.body;el=el.parentElement){
            for(const sib of el.parentElement.children){
                if(sib!==el&&!['SCRIPT','STYLE','LINK'].includes(sib.tagName))sib.dataset.pmHide='';
            }
            if(el!==body)el.dataset.pmPath='';
        }
        const size=()=>window.parent.postMessage({kind:'pm-topic-size',height:Math.ceil(body.getBoundingClientRect().height)+20},location.origin);
        new ResizeObserver(size).observe(body);
        const base=doc.createElement('base');base.target='_top';doc.head.append(base);
        doc.addEventListener('click',event=>{
            const link=event.target.closest('a[href]');if(!link||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
            if(link.closest('[data-place-topics]')){
                event.preventDefault();window.parent.postMessage({kind:'pm-topic-open',url:link.href},location.origin);
            }else if(link.getAttribute('href')?.startsWith('#')){
                const target=doc.getElementById(decodeURIComponent(link.hash.slice(1)));
                if(target){event.preventDefault();target.scrollIntoView({block:'start'});}
            }
        });
        doc.addEventListener('change',event=>{if(event.target.matches('.pm-topic-picker select'))window.parent.postMessage({kind:'pm-topic-open',url:event.target.value},location.origin);});
        addEventListener('wheel',e=>{if(e.deltaY>0&&!e.ctrlKey&&!e.target.closest?.('.maplibregl-map,input,select,textarea'))window.parent.postMessage({kind:'pm-topic-forward'},location.origin);},{passive:true});
        window.parent.postMessage({kind:'pm-topic-ready'},location.origin);
        return;
    }

    const top=doc.createElement('button');top.type='button';top.className='pm-top-button';top.hidden=true;
    top.setAttribute('aria-label',label('Back to top','पृष्ठ के शीर्ष पर जाएँ'));top.title=top.getAttribute('aria-label');
    top.innerHTML='<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 19V5m-7 7 7-7 7 7"/></svg>';
    doc.body.append(top);
    const showTop=()=>{top.hidden=scrollY<350;};
    addEventListener('scroll',showTop,{passive:true});addEventListener('pageshow',showTop);showTop();
    top.onclick=()=>{const first=doc.querySelector('h1')||doc.body;if(!first.hasAttribute('tabindex'))first.tabIndex=-1;first.focus({preventScroll:true});window.scrollTo({top:0,behavior:reduced()?'instant':'smooth'});};

    let current=null,pending=null;
    const appended=[];
    function clearAppended(){
        for(const item of appended)item.section.remove();
        appended.length=0;
    }
    function sizeFrame(frame,height){
        if(Number.isFinite(height))frame.style.height=Math.max(80,Math.min(50000,height))+'px';
    }
    function nextOnScroll(){
        if(doc.querySelector('[data-pilibhit-native]'))return;
        if(pending)return;
        const nav=primary(),body=bodyFor(nav);
        if(!body)return;
        const last=appended.at(-1);
        const tail=last?.section || current || body;
        if(!tail.isConnected || tail.getBoundingClientRect().bottom>innerHeight+180)return;
        const links=[...nav.querySelectorAll('a[data-topic]')];
        const key=last?.key || nav.querySelector('[aria-current=page]')?.dataset.topic;
        const index=links.findIndex(link=>link.dataset.topic===key);
        if(index>=0 && links[index+1])openTopic(links[index+1].href,false,true);
    }
    let scheduled=false,lastY=scrollY;
    function queueNext(){
        if(scheduled)return;
        scheduled=true;
        requestAnimationFrame(()=>{scheduled=false;nextOnScroll();});
    }
    addEventListener('scroll',()=>{const y=scrollY;if(y>lastY+0.5)queueNext();lastY=y;},{passive:true});
    // Short overview pages can fit entirely on a tall screen.
    addEventListener('wheel',e=>{if(e.deltaY>0&&!e.ctrlKey && !e.target.closest?.('input,select,textarea,[role=dialog],.maplibregl-map'))queueNext();},{passive:true});
    let touchY=null;
    addEventListener('touchstart',e=>{touchY=e.touches[0]?.clientY;},{passive:true});
    addEventListener('touchmove',e=>{if(touchY!=null && touchY-e.touches[0].clientY>12){touchY=e.touches[0].clientY;queueNext();}},{passive:true});
    addEventListener('keydown',e=>{if(['ArrowDown','PageDown','End',' '].includes(e.key)&&!e.shiftKey&&!e.target.closest?.('input,button,select,textarea,[contenteditable=true]'))queueNext();});

    function stopPending(){
        if(!pending)return;
        clearTimeout(pending.delay);clearTimeout(pending.deadline);pending.frame.remove();pending.status.remove();pending.section?.remove();pending.nav.removeAttribute('aria-busy');pending=null;
    }
    doc.addEventListener('pm-place-changing',()=>{
        stopPending();
        clearAppended();current?.remove();current=null;
        const body=bodyFor(primary());if(body)body.hidden=false;
    });
    function mark(nav,url){
        const key=url.searchParams.get('topic')||'overview';
        for(const a of nav.querySelectorAll('a[data-topic]')){if(a.dataset.topic===key)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');}
        const select=nav.querySelector('select');if(select)select.value=nav.querySelector('[aria-current=page]')?.href||'';
    }
    function openTopic(raw,historyAction=true,append=false){
        const url=clean(raw),nav=primary(),body=bodyFor(nav);
        if(doc.querySelector('[data-pilibhit-native]')||!body||url.origin!==location.origin||url.pathname!==location.pathname){location.assign(url);return;}
        stopPending();
        const status=doc.createElement('div');status.hidden=true;status.className='pm-topic-state';status.setAttribute('role','status');
        const key=url.searchParams.get('topic')||'overview';
        const title=nav.querySelector('a[data-topic="'+CSS.escape(key)+'"]')?.textContent.trim()||label('Records','रिकॉर्ड');
        const frame=doc.createElement('iframe');frame.className='pm-topic-frame';frame.title=title;frame.hidden=true;
        const section=append?doc.createElement('section'):null;
        if(section){
            section.className='pm-scroll-section';section.dataset.scrollTopic=key;
            const heading=doc.createElement('h2');heading.textContent=title;section.setAttribute('aria-label',title);section.append(heading);
        }
        const src=new URL(url);src.searchParams.set('pm_panel','1');frame.src=src;
        const fail=()=>{
            if(pending?.frame!==frame)return;
            clearTimeout(pending.delay);clearTimeout(pending.deadline);frame.remove();nav.removeAttribute('aria-busy');status.hidden=false;status.dataset.error='';
            status.textContent=label('Could not load ','लोड नहीं हुआ · ')+title+'. ';
            const retry=doc.createElement('button');retry.type='button';retry.textContent=label('Retry','फिर कोशिश करें');retry.onclick=()=>openTopic(url,historyAction,append);status.append(retry);
        };
        const ready=()=>{
            if(pending?.frame!==frame)return;
            const child=frame.contentDocument;
            if(!child?.documentElement.classList.contains('pm-framed')||child.querySelector('[data-place-topics]')?.dataset.activeTopic!==key){fail();return;}
            clearTimeout(pending.delay);clearTimeout(pending.deadline);status.remove();nav.removeAttribute('aria-busy');
            pending=null;
            if(append){
                appended.push({key,section,frame});
                frame.hidden=false;
                sizeFrame(frame,child.querySelector('[data-topic-body]').getBoundingClientRect().height+20);
                return;
            }
            clearAppended();current?.remove();current=frame;body.hidden=true;frame.hidden=false;
            frame.style.height=Math.ceil(child.querySelector('[data-topic-body]').getBoundingClientRect().height+20)+'px';
            if(historyAction)history.pushState({pmTopic:true},'',url);
            mark(nav,url);
            for(const a of doc.querySelectorAll('.public-header .language-switch a')){const lang=new URL(a.href).searchParams.get('lang');if(lang){const dest=new URL(url);dest.searchParams.set('lang',lang);a.href=dest;}}
        };
        pending={frame,status,ready,nav,section,delay:setTimeout(()=>{status.textContent=label('Loading ','लोड हो रहा है · ')+title+'…';status.hidden=false;},220),deadline:setTimeout(fail,45000)};
        nav.setAttribute('aria-busy','true');frame.onerror=fail;frame.onload=()=>{if(pending?.frame===frame)ready();};
        if(append){
            const tail=appended.at(-1)?.section || current || body;
            tail.after(section);section.append(status,frame);
        }else{
            nav.after(status);body.after(frame);
        }
    }
    addEventListener('message',event=>{
        if(event.origin!==location.origin)return;
        if(event.source===pending?.frame.contentWindow && event.data?.kind==='pm-topic-ready')pending.ready();
        else{
            const sender=[current,...appended.map(item=>item.frame)].find(frame=>frame && frame.contentWindow===event.source);
            if(!sender)return;
            if(event.data?.kind==='pm-topic-size')sizeFrame(sender,event.data.height);
            if(event.data?.kind==='pm-topic-open')openTopic(event.data.url);
            if(event.data?.kind==='pm-topic-forward')queueNext();
        }
    });
    doc.addEventListener('click',event=>{
        const a=event.target.closest('[data-place-topics] a[data-topic]');
        if(!a||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
        event.preventDefault();openTopic(a.href);
    });
    doc.addEventListener('change',event=>{if(event.target.matches('.pm-topic-picker select'))openTopic(event.target.value);});
    addEventListener('popstate',()=>{if(primary()&&!doc.querySelector('[data-pilibhit-native]'))openTopic(location.href,false);});
    // Old bookmarks open the equivalent topic on the new, smaller initial document.
    const anchors={'compact-panchayats':'panchayats','gram-panchayats':'panchayats','compact-places':'places','compact-water':'water','compact-soil':'soil','compact-rainfall':'rainfall','compact-map':'map','compact-satellite':'map','compact-graphs':'history','compact-elections':'elections','compact-mandi':'markets','compact-authorities':'authorities','compact-sources':'sources','member-villages':'villages','panchayat-records':'records','linked-village-data':'village-data','villages':'places','subdistricts':'places','groundwater-levels':'water','place-facts':'people'};
    const target=anchors[location.hash.slice(1)]||location.hash.slice(1);
    if(target&&!location.search.includes('topic=')){
        const nav=primary(),a=nav?.querySelector('a[data-topic="'+CSS.escape(target)+'"]');
        if(a && a.dataset.topic!==nav.dataset.activeTopic)location.replace(a.href);
    }
})();
