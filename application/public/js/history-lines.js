(() => {
    if (typeof window !== 'undefined') {
        const closedTables = new Set();
        window.addEventListener('beforeprint', () => {
            document.querySelectorAll('.chart-values, .home-chart-table').forEach(table => {
                if (!table.open) { closedTables.add(table); table.open = true; }
            });
        });
        window.addEventListener('afterprint', () => {
            closedTables.forEach(table => table.open = false); closedTables.clear();
        });
    }
    const ns = 'http://www.w3.org/2000/svg';
    const colors = ['var(--palette-315d91)', 'var(--site-accent)', 'var(--site-primary)', 'var(--site-muted)', '#8b5c9e'];
    const element = (tag, attributes = {}, text = '') => {
        const node = document.createElementNS(ns, tag);
        Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
        if (text) node.textContent = text;
        return node;
    };
    document.querySelectorAll('[data-history-chart]').forEach(chart => {
        const data = JSON.parse(chart.querySelector('.history-chart-data').textContent);
        const from = chart.querySelector('[data-chart-from]'), to = chart.querySelector('[data-chart-to]');
        const plot = chart.querySelector('.history-plot'), status = chart.querySelector('.history-readout');
        const enabled = new Set(data.series.map(series => series.key));
        const format = value => new Intl.NumberFormat(document.documentElement.lang === 'hi' ? 'hi-IN' : 'en-IN', {maximumFractionDigits: data.unit === '%' ? 2 : 0}).format(value);
        const render = () => {
            plot.replaceChildren();
            status.style.visibility = '';
            const rows = data.rows.filter(row => row.year >= Number(from.value) && row.year <= Number(to.value));
            if (!rows.length) { status.textContent = 'No years in this range.'; return; }
            const series = data.series.filter(item => enabled.has(item.key));
            const values = rows.flatMap(row => series.map(item => row[item.key])).filter(value => value !== null && Number.isFinite(value));
            if (!values.length) { status.textContent = 'No available values for this selection.'; return; }
            status.textContent = 'Hover or focus a year to see the value. † indicates a source note.';
            const max = data.unit === '%' ? 100 : Math.max(1, ...values) * 1.08;
            const width = Math.max(240, plot.clientWidth), height = 270, left = width < 500 ? 42 : 62, right = width - (width < 500 ? 8 : 18), top = 16, bottom = 228;
            const first = rows[0].year, last = rows[rows.length - 1].year;
            const x = year => first === last ? (left + right) / 2 : left + (year - first) / (last - first) * (right - left);
            const y = value => bottom - value / max * (bottom - top);
            const tooltip = document.createElement('div');
            tooltip.className = 'history-tooltip'; tooltip.hidden = true; tooltip.setAttribute('role', 'tooltip');
            const svg = element('svg', {viewBox: `0 0 ${width} ${height}`, role: 'group', 'aria-label': chart.querySelector('h3').textContent + ', ' + data.unit});
            for (let i = 0; i <= 4; i++) {
                const value = max * i / 4, yy = y(value);
                svg.append(element('line', {x1:left,x2:right,y1:yy,y2:yy,class:'history-grid'}));
                const tick = (value >= 10000000 ? (value/10000000).toLocaleString('en-IN',{maximumFractionDigits:1})+' Cr.' : value >= 100000 ? (value/100000).toLocaleString('en-IN',{maximumFractionDigits:1})+' L' : value.toLocaleString('en-IN',{maximumFractionDigits:1})) + (data.unit === '%' ? '%' : '');
                svg.append(element('text',{x:left-9,y:yy+4,'text-anchor':'end'},tick));
            }
            const step = Math.max(1, Math.ceil(rows.length / Math.max(2, Math.floor((right-left)/62))));
            rows.forEach((row,index) => {
                if (index % step === 0 || index === rows.length-1) {
                    if (index !== rows.length-1 && rows.length>1 && x(last)-x(row.year)<45) return;
                    svg.append(element('text',{x:x(row.year),y:bottom+24,'text-anchor':'middle'},String(row.year)));
                }
            });
            series.forEach(item => {
                const index = data.series.indexOf(item), color = item.key === 'others_share' ? colors[3] : colors[index];
                let path = '', area = '', previous = null, start = null;
                const closeArea = () => { if (previous) area += `L${previous.x},${bottom} L${start},${bottom} Z `; };
                rows.forEach(row => {
                    const value = row[item.key];
                    if (value === null || !Number.isFinite(value)) { closeArea(); previous=null; start=null; return; }
                    const xx = x(row.year), yy = y(value);
                    if (previous) { path += `L${xx},${yy} `; area += `L${xx},${yy} `; }
                    else { path += `M${xx},${yy} `; area += `M${xx},${yy} `; start = xx; }
                    previous = {x:xx,y:yy};
                });
                closeArea();
                svg.append(element('path',{d:area,fill:color,'fill-opacity':0.09,stroke:'none','pointer-events':'none'}));
                svg.append(element('path',{d:path,fill:'none',stroke:color,'stroke-width':2.5,'stroke-dasharray':item.key==='others_share'?'6 4':'none'}));
                rows.forEach(row => {
                    const value = row[item.key];
                    if (value === null || !Number.isFinite(value)) return;
                    const partyName = item.name_key && row[item.name_key] ? ` (${row[item.name_key]})` : '';
                    const votes = item.votes_key ? ` · ${format(row[item.votes_key])} votes` : '';
                    const label = `${row.year} · ${item.label}${partyName}: ${format(value)} ${data.unit}${votes}${row.review?' † — source note':''}`;
                    const hit = element('rect',{x:x(row.year)-12,y:y(value)-12,width:24,height:24,fill:'transparent',tabindex:0,role:'img','aria-label':label,class:'history-hit-target'});
                    const show = () => {
                        status.textContent = label;
                        status.style.visibility = 'hidden';
                        tooltip.textContent = label; tooltip.hidden = false;
                        tooltip.style.left = `${Math.max(12, Math.min(width - 12, x(row.year)))}px`;
                        tooltip.style.top = `${Math.max(4, y(value) - 12)}px`;
                        tooltip.style.transform = x(row.year) > width * .65 ? 'translate(-100%, -100%)' : x(row.year) < width * .35 ? 'translate(0, -100%)' : 'translate(-50%, -100%)';
                    };
                    ['focus','pointerenter','click'].forEach(event => hit.addEventListener(event, show));
                    const hide = () => { tooltip.hidden = true; status.style.visibility = ''; status.textContent = 'Hover or focus a year to see the value. † indicates a source note.'; };
                    ['blur','pointerleave'].forEach(event => hit.addEventListener(event, hide));
                    hit.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
                    svg.append(hit);
                });
            });
            plot.append(svg, tooltip);
        };
        data.series.forEach((series,index) => {
            const button = document.createElement('button'); button.type='button'; button.setAttribute('aria-pressed','true');
            const swatch=document.createElement('span'); swatch.style.background=series.key==='others_share'?colors[3]:colors[index]; swatch.setAttribute('aria-hidden','true');
            button.append(swatch,document.createTextNode(series.label));
            button.addEventListener('click',()=>{ enabled.has(series.key)?enabled.delete(series.key):enabled.add(series.key); button.setAttribute('aria-pressed',String(enabled.has(series.key))); render(); });
            chart.querySelector('.history-legend').append(button);
        });
        from.addEventListener('change',()=>{if(Number(from.value)>Number(to.value))to.value=from.value;render();});
        to.addEventListener('change',()=>{if(Number(to.value)<Number(from.value))from.value=to.value;render();});
        new ResizeObserver(render).observe(plot);
        render();
    });
})();
