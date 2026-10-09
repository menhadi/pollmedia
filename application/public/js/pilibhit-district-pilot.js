export function filterPlaces(rows, filters) {
    const q = (filters.q || '').trim().toLocaleLowerCase();
    return rows.filter(row => `${row.name} ${row.code}`.toLocaleLowerCase().includes(q) &&
        (!filters.subdistrict || filters.subdistrict === 'all' || row.subdistrict_code === filters.subdistrict) &&
        (!filters.kind || filters.kind === 'all' || row.kind === filters.kind) &&
        (!filters.status || filters.status === 'all' || row.status === filters.status)).sort((a,b) => a.name.localeCompare(b.name));
}
const el = (tag, text) => {const node = document.createElement(tag); if (text !== undefined) node.textContent = text; return node;};
const number = value => new Intl.NumberFormat('en-IN').format(value);
async function start(root) {
    const response = await fetch(root.dataset.source);
    if (!response.ok) throw new Error('District evidence unavailable');
    const data = await response.json(), svg = document.getElementById('village-map'), detail = document.getElementById('place-detail');
    const controls = Object.fromEntries(['q','subdistrict','kind','status'].map(key => [key,document.getElementById(key)]));
    const paths = new Map(), byCode = new Map(data.rows.map(row => [row.code,row]));
    const rings = geometry => geometry.type === 'Polygon' ? geometry.coordinates : geometry.coordinates.flat();
    const coordinates = data.rows.filter(row => row.geometry).flatMap(row => rings(row.geometry)).flat();
    const xs = coordinates.map(point => point[0]), ys = coordinates.map(point => point[1]);
    const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
    const cosine = Math.cos((minY+maxY)*Math.PI/360), scale = Math.min(700/((maxX-minX)*cosine),460/(maxY-minY));
    const offsetX = (760-(maxX-minX)*cosine*scale)/2, offsetY = (540-(maxY-minY)*scale)/2;
    const project = ([x,y]) => [offsetX+(x-minX)*cosine*scale,offsetY+(maxY-y)*scale];
    const group = document.createElementNS('http://www.w3.org/2000/svg','g'); svg.append(group);
    for (const row of data.rows) {
        if (!row.geometry) continue;
        const path = document.createElementNS(svg.namespaceURI,'path');
        path.setAttribute('d',rings(row.geometry).map(ring => ring.map((point,i)=>`${i?'L':'M'}${project(point).join(',')}`).join(' ')+'Z').join(' '));
        path.setAttribute('fill',{'00790':'#388565','00791':'#d5a35b','00792':'#789ab8'}[row.subdistrict_code]);
        path.setAttribute('fill-rule','evenodd'); path.setAttribute('tabindex','0'); path.setAttribute('role','button'); path.setAttribute('aria-label',`${row.name}, ${row.subdistrict}`); path.classList.add('village-shape');
        const title = document.createElementNS(svg.namespaceURI,'title'); title.textContent=row.name; path.append(title);
        path.addEventListener('click',()=>select(row.code));
        path.addEventListener('keydown',event=>{if (event.key==='Enter'||event.key===' ') {event.preventDefault();select(row.code);}});
        group.append(path); paths.set(row.code,path);
    }
    document.getElementById('map-message').textContent='Colours show 2011 subdistricts. Select a place to inspect its records.';
    function select(code) {
        const row = byCode.get(code); if (!row) return;
        for (const [key,path] of paths) {path.classList.toggle('selected',key===code);path.setAttribute('aria-pressed',String(key===code));}
        detail.replaceChildren(el('p','Census 2011 place'),el('h3',row.name),el('p',`${row.code} · ${row.kind} · ${row.subdistrict}`));
        const dl=el('dl');
        for (const [label,value] of Object.entries({'Population':number(row.census.population),'Households':number(row.census.households),'Literacy · age 7+':row.census.literacy_rate===null?'Unavailable':`${row.census.literacy_rate}%`,'Workbook row':row.census.source_row,'Map code link':row.geometry_status.replaceAll('_',' ')})) dl.append(el('dt',label),el('dd',value));
        detail.append(dl,el('h3','Newer LGD directory'));
        if (!row.directory_links.length) detail.append(el('p','No accepted current directory link. Census district membership and counts remain available.'));
        for (const link of row.directory_links) detail.append(el('p',`LGD ${link.code} · ${link.name} · ${link.district_name} / ${link.subdistrict_name}`));
        detail.append(el('p',`Directory checked ${data.sources.lgd_checked_on}. Geometry edition correspondence remains unverified.`));
        const source=el('a','Inspect official Census workbook ↗');source.href=data.sources.census;detail.append(source);
        const url=new URL(location.href);url.hash=`place-${code}`;history.replaceState(null,'',url);
    }
    let page=Math.max(1,Number(new URL(location.href).searchParams.get('page'))||1), zoom=1;
    function render() {
        const filters=Object.fromEntries(Object.entries(controls).map(([key,node])=>[key,node.value]));
        const matches=filterPlaces(data.rows,filters), pages=Math.max(1,Math.ceil(matches.length/25));page=Math.min(page,pages);
        const tbody=document.getElementById('village-rows');tbody.replaceChildren();
        for (const row of matches.slice((page-1)*25,page*25)) {
            const tr=el('tr'), cell=el('td'),button=el('button',row.name);button.type='button';button.className='village-link';button.addEventListener('click',()=>select(row.code));cell.append(button,el('small',row.code));
            tr.append(cell,el('td',`${row.kind} · ${row.subdistrict}`),el('td',number(row.census.population)),el('td',row.census.literacy_rate===null?'Unavailable':`${row.census.literacy_rate.toFixed(1)}%`),el('td',row.status==='linked'?'Unique code link':'Link needs review'));tbody.append(tr);
        }
        if (!matches.length) {const tr=el('tr'),td=el('td','No places match these filters.');td.colSpan=5;tr.append(td);tbody.append(tr);}
        document.getElementById('result-count').textContent=`${matches.length} matching places · page ${page} of ${pages}`;
        const pager=document.getElementById('pagination');pager.replaceChildren();
        for (const [label,step] of [['Previous',-1],['Next',1]]) {const button=el('button',label);button.type='button';button.disabled=step<0?page===1:page===pages;button.addEventListener('click',()=>{page+=step;render();});pager.append(button);}
        const codes=new Set(matches.map(row=>row.code));for (const [code,path] of paths) {path.classList.toggle('dimmed',!codes.has(code));path.setAttribute('tabindex',codes.has(code)?'0':'-1');}
        const url=new URL(location.href);for (const [key,value] of Object.entries(filters)) {if (value&&value!=='all') url.searchParams.set(key,value);else url.searchParams.delete(key);}if(page>1)url.searchParams.set('page',page);else url.searchParams.delete('page');history.replaceState(null,'',url);
        const download=new URL(url);download.searchParams.delete('page');download.searchParams.set('format','csv');document.getElementById('download').href=download.href;
    }
    document.getElementById('filters').addEventListener('submit',event=>{event.preventDefault();page=1;render();});
    for (const node of Object.values(controls)) node.addEventListener(node.tagName==='INPUT'?'input':'change',()=>{page=1;render();});
    document.getElementById('reset').addEventListener('click',()=>{for(const [key,node] of Object.entries(controls))node.value=key==='q'?'':'all';page=1;render();});
    function setZoom(value) {zoom=Math.max(1,Math.min(8,value));group.setAttribute('transform',`translate(380 270) scale(${zoom}) translate(-380 -270)`);}
    document.getElementById('zoom-in').addEventListener('click',()=>setZoom(zoom*1.5));document.getElementById('zoom-out').addEventListener('click',()=>setZoom(zoom/1.5));document.getElementById('fit-map').addEventListener('click',()=>setZoom(1));
    render();if(location.hash.startsWith('#place-'))select(location.hash.slice(7));
}
if(typeof document!=='undefined') {const root=document.getElementById('district-pilot');if(root)start(root).catch(()=>{document.getElementById('map-message').textContent='Interactive map unavailable. Use the directory, search and CSV export below.';});}
