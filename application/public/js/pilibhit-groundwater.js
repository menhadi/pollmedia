'use strict';
(() => {
    const data = JSON.parse(document.getElementById('groundwater-data').textContent);
    const kind = document.getElementById('groundwater-kind');
    const search = document.getElementById('groundwater-search');
    const selector = document.getElementById('groundwater-station');
    const parameter = document.getElementById('groundwater-parameter');
    const sources = new Map(data.sources.map(s => [s.id, s]));
    let map;
    const dateRange = k => {
        const dates = data.stations.filter(s => s.kind === k).flatMap(s => s.observations.map(o => o.observed_at.slice(0, 10))).sort();
        return dates.length ? `${dates[0]} to ${dates.at(-1)}` : 'unavailable';
    };
    document.getElementById('pilot-dates').textContent = `Actual observation periods · Levels: ${dateRange('level')} · Chemistry: ${dateRange('chemistry')}. The downloaded 2026–2030 files contain no observations.`;
    const features = stations => ({type: 'FeatureCollection', features: stations.map(s => ({type: 'Feature', properties: {id: s.id, kind: s.kind, station: s.station}, geometry: {type: 'Point', coordinates: [s.longitude, s.latitude]}}))});
    function filtered() {
        const term = search.value.trim().toLowerCase();
        return data.stations.filter(s => (!kind.value || s.kind === kind.value) && `${s.station} ${s.village} ${s.block}`.toLowerCase().includes(term));
    }
    function filter() {
        const previous = selector.value;
        const rows = filtered();
        selector.replaceChildren(...rows.map(s => new Option(`${s.station} · ${s.kind === 'level' ? 'levels' : 'chemistry'}`, s.id)));
        if (rows.some(s => s.id === previous)) selector.value = previous;
        document.getElementById('station-count').textContent = `${rows.length} monitoring series match your filters.`;
        if (map?.getSource('stations')) map.getSource('stations').setData(features(rows));
        document.getElementById('station-detail').hidden = !rows.length;
        if (rows.length) select();
    }
    function select() {
        const station = data.stations.find(s => s.id === selector.value);
        if (!station) return;
        document.getElementById('station-title').textContent = station.station;
        document.getElementById('station-meta').textContent = `Source locality: ${station.village} · Block: ${station.block} · Tehsil: ${station.tehsil} · ${station.latitude}, ${station.longitude}`;
        const matches = station.village_match.candidates;
        const link=station.location_link;
        const linked=document.getElementById('station-link');linked.replaceChildren();
        if(link?.status==='supported_location'){
            linked.textContent=`Supported monitoring-location association: ${link.name} · LGD ${link.lgd}. Source locality, containing polygon and LGD name agree. Boundary edition remains unverified; this does not establish household supply. `;
            const a=document.createElement('a');a.href=station.village_url||`/india/elections/village-map/villages/${link.lgd}`;a.textContent='Village profile →';linked.append(a);
            const b=document.createElement('a');b.href=station.my_place_url||`/my-place?state=${link.state}&district=${link.district}&village=${link.lgd}`;b.textContent=' My Place →';linked.append(b);
        }else linked.textContent=(link?.reason?link.reason+'. ':'')+(matches.length ? `${matches.length} name candidate(s): ${matches.map(m => `${m.name} (LGD ${m.lgd}; ${m.subdistrict})`).join('; ')}. Name agreement alone does not establish a village link.` : 'No exact village-name candidate in the stored district directory. No village value is assigned.');
        const previous = parameter.value;
        const keys = [...new Set(station.observations.flatMap(o => Object.keys(o.values)))];
        parameter.replaceChildren(...keys.map(k => new Option(k, k)));
        if (keys.includes(previous)) parameter.value = previous;
        if (map) map.easeTo({center: [station.longitude, station.latitude], duration: 300});
        draw();
    }
    function draw() {
        const station = data.stations.find(s => s.id === selector.value);
        const key = parameter.value;
        const rows = station.observations;
        document.getElementById('measurement-note').textContent = station.kind === 'level' ? 'Source unit: metres. Depth/elevation convention and well construction need verification; no rise, fall or water-availability conclusion is calculated.' : 'Original parameter and unit retained. Blank means not reported. No drinking-water safety classification is calculated.';
        document.getElementById('groundwater-observations').replaceChildren(...[...rows].reverse().map(o => {
            const tr = document.createElement('tr');
            [o.observed_at.replace('T', ' '), o.values[key] === null ? (o.qualifiers[key] ? `Unparsed source value: ${o.qualifiers[key]}` : 'Not reported') : String(o.values[key]), String(o.source_row)].forEach(text => {const td = document.createElement('td'); td.textContent = text; tr.append(td);});
            const td = document.createElement('td'); const a = document.createElement('a'); a.href = sources.get(o.source_id).url; a.textContent = o.source_id.replaceAll('_', ' '); a.target = '_blank'; a.rel = 'noopener'; td.append(a); tr.append(td); return tr;
        }));
        const valid = rows.filter(o => typeof o.values[key] === 'number');
        document.getElementById('observation-count').textContent = `${valid.length} reported values out of ${rows.length} observation rows for this measurement. Missing values are excluded from the plot.`;
        const chart = document.getElementById('groundwater-chart'); chart.replaceChildren();
        if (!valid.length) {chart.textContent = 'No reported values for this parameter at this monitoring location.'; return;}
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg'); svg.setAttribute('viewBox', '0 0 900 280'); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', `${key} by observation date for ${station.station}`);
        const make = (tag, attrs, text) => {const node = document.createElementNS(ns, tag); Object.entries(attrs).forEach(([k,v]) => node.setAttribute(k, v)); if (text !== undefined) node.textContent = text; svg.append(node); return node;};
        const times = valid.map(o => Date.parse(o.observed_at+'Z')); const values = valid.map(o => o.values[key]);
        const minT = Math.min(...times), maxT = Math.max(...times); let min = Math.min(...values), max = Math.max(...values);
        const pad = (max-min || Math.max(Math.abs(max)*.1, 1))*.12; min-=pad; max+=pad;
        const x = t => 70+(t-minT)/(maxT-minT || 1)*800; const y = v => 230-(v-min)/(max-min)*205;
        for (let i=0;i<5;i++) {const value=min+(max-min)*i/4; make('line',{x1:70,x2:870,y1:y(value),y2:y(value),stroke:'#dce5e8'}); make('text',{x:60,y:y(value)+4,'text-anchor':'end'},value.toFixed(2));}
        valid.forEach((o,i) => {const circle=make('circle',{cx:x(times[i]),cy:y(o.values[key]),r:4,fill:'#4285f4'}); const title=document.createElementNS(ns,'title'); title.textContent=`${o.observed_at}: ${o.values[key]}`; circle.append(title);});
        make('text',{x:70,y:260},valid[0].observed_at.slice(0,10)); make('text',{x:870,y:260,'text-anchor':'end'},valid.at(-1).observed_at.slice(0,10)); chart.append(svg);
    }
    kind.addEventListener('change', filter); search.addEventListener('input', filter); selector.addEventListener('change', select); parameter.addEventListener('change', draw);
    if (window.maplibregl) {
        map = new maplibregl.Map({container:'groundwater-map',center:[79.85,28.55],zoom:8,style:{version:8,sources:{osm:{type:'raster',tiles:['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],tileSize:256,attribution:'© OpenStreetMap contributors'}},layers:[{id:'base',type:'raster',source:'osm'}]}});
        map.addControl(new maplibregl.NavigationControl());
        map.on('load',() => {map.addSource('stations',{type:'geojson',data:features(filtered())}); map.addLayer({id:'stations',type:'circle',source:'stations',paint:{'circle-radius':7,'circle-color':['match',['get','kind'],'chemistry','#bc6326','#00798a'],'circle-stroke-width':2,'circle-stroke-color':'#fff'}}); map.on('click','stations',e => {selector.value=e.features[0].properties.id; select();});});
    } else document.getElementById('groundwater-map').textContent = 'Map unavailable. All observations remain accessible using the station selector.';
    filter();
    const requested=new URLSearchParams(location.search).get('station');if(data.stations.some(s=>s.id===requested)){selector.value=requested;select();}
})();
