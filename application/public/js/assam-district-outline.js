const section = document.querySelector('[data-assam-outline]');
async function draw() {
    const response = await fetch(section.dataset.assamOutline);
    if (!response.ok) throw new Error('Unavailable');
    const map = await response.json(), svg = document.getElementById('district-outline');
    const rings = geometry => geometry.type === 'Polygon' ? geometry.coordinates : geometry.coordinates.flat();
    const polygons = map.features.flatMap(feature => rings(feature.geometry));
    const [west, south, east, north] = polygons.flat().reduce((b, [x,y]) => [Math.min(b[0],x), Math.min(b[1],y), Math.max(b[2],x), Math.max(b[3],y)], [Infinity,Infinity,-Infinity,-Infinity]);
    const cosine = Math.cos((north+south)*Math.PI/360), scale = Math.min(700/((east-west)*cosine),460/(north-south));
    const project = ([x,y]) => [(760-(east-west)*cosine*scale)/2+(x-west)*cosine*scale,(540-(north-south)*scale)/2+(north-y)*scale];
    const group = document.createElementNS(svg.namespaceURI,'g');
    const path = document.createElementNS(svg.namespaceURI,'path');
    path.setAttribute('d',polygons.map(ring => ring.map((p,i) => `${i?'L':'M'}${project(p).join(',')}`).join(' ')+'Z').join(' '));
    path.setAttribute('fill','#547ae5'); path.setAttribute('stroke','#243a74'); path.setAttribute('stroke-width','1'); path.setAttribute('fill-rule','evenodd');
    group.append(path); svg.append(group);
    document.getElementById('map-message').textContent = 'SOI district outline · source edition 2022–2025 · Census and electoral joins unverified';
    let zoom = 1;
    const change = value => { zoom=Math.max(1,Math.min(8,value)); group.setAttribute('transform',`translate(380 270) scale(${zoom}) translate(-380 -270)`); };
    document.getElementById('zoom-in').onclick = () => change(zoom*1.5);
    document.getElementById('zoom-out').onclick = () => change(zoom/1.5);
    document.getElementById('fit-map').onclick = () => change(1);
}
if (section) draw().catch(() => { document.getElementById('map-message').textContent='Map could not load. Download the source geometry above.'; });
