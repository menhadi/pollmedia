import {pageSlice} from './compact-place-model.js?v=20261104-polish-4';

const paged=new WeakMap();
const text=(en,hi)=>document.documentElement.lang.startsWith('hi')?hi:en;
const make=(tag,cls='',value)=>{
    const node=document.createElement(tag);node.className=cls;
    if(value!==undefined)node.textContent=value;
    return node;
};

export function paginate(table, initialSize=50) {
    if (paged.has(table)) { paged.get(table)(); return; }
    if (table.querySelector('[rowspan]')) return;
    if (!table.tBodies.length || table.closest('[data-local-data-browser]') || table.parentElement.nextElementSibling?.matches('.compact-pager')) return;
    if ([...table.tBodies].reduce((sum,body)=>sum+body.rows.length,0)<=initialSize) return;
    const scoped=!!table.closest('[data-topic-body]');
    const url=new URL(location.href);url.searchParams.delete('pm_panel');
    const tableKey=table.id || (table.closest('section')?.id||'')+':'+[...document.querySelectorAll('[data-topic-body] table')].indexOf(table);
    const key='pm-directory-table:'+url.pathname+'?'+url.searchParams.toString()+':'+tableKey;
    const filter=table.closest('section')?.querySelector('#census-village-search,[data-area-table-search]');
    let page=1,size=initialSize;
    if(scoped){try{
        const saved=JSON.parse(sessionStorage.getItem(key));
        if(saved){
            if(filter && typeof saved.search==='string'){filter.value=saved.search;filter.dispatchEvent(new Event('input',{bubbles:true}));}
            page=Number(saved.page)||1;size=saved.size||initialSize;
        }
    }catch{}}
    const bar=make('nav','approved-pagination');bar.setAttribute('aria-label',text('Table pages','तालिका पृष्ठ'));
    const count=make('span');count.setAttribute('aria-live','polite');
    const select=make('select');select.setAttribute('aria-label',text('Rows per page','प्रति पृष्ठ पंक्तियाँ'));
    for(const number of (scoped?[initialSize,50,100,'All']:[50,100,'All'])){const option=make('option','',String(number));option.value=String(number);select.append(option);}
    select.value=String(size);
    const previous=make('button','',text('Previous','पिछला')),next=make('button','',text('Next','अगला')),position=make('span');previous.type=next.type='button';
    bar.append(count,select,previous,position,next);
    const container=table.closest('.table-scroll,.table-wrap,.place-table-scroll,.compact-table-wrap')||table;
    if(scoped)container.before(bar);else container.after(bar);
    const render=()=>{
        const rows=[...table.tBodies].flatMap(body=>[...body.rows]);
        const eligible=rows.filter(row=>!row.hidden&&row.style.display!=='none');
        const slice=pageSlice(eligible,page,size==='All'?Math.max(1,eligible.length):Number(size));page=slice.page;
        const shown=new Set(slice.rows);rows.forEach(row=>row.toggleAttribute('data-approved-page-hidden',!shown.has(row)));
        count.textContent=`${slice.start}–${slice.end} / ${slice.total}`;position.textContent=`${page} / ${slice.pages}`;previous.disabled=page===1;next.disabled=page===slice.pages;
        if(scoped){try{sessionStorage.setItem(key,JSON.stringify({page,size,search:filter?.value||''}));}catch{}}
    };
    previous.onclick=()=>{page--;render();};next.onclick=()=>{page++;render();};select.onchange=()=>{size=select.value;page=1;render();};
    table.tHead?.addEventListener('click',()=>setTimeout(render,0));table.closest('section')?.addEventListener('input',()=>{page=1;setTimeout(render,0);});
    paged.set(table,render);render();
}

