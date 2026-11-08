// Use intersecting bounding boxes as a conservative neighbour test. This also
// separates colours across small gaps between independently simplified borders.
window.constituencyMapColours = function (features, tone = 'reference') {
    const dark = tone === 'dark';
    const palette = dark
        ? ['#185749','#82451e','#56385e','#234c70','#505c27','#853956','#126467','#634832']
        : ['#86bca8','#dca46f','#a79bc5','#84aec7','#bdc783','#d99caa','#9cbbb9','#c4a789'];
    const nodes = features.map(feature => {
        let west=Infinity, east=-Infinity, south=Infinity, north=-Infinity;
        const polygons=feature.geometry.type==='Polygon' ? [feature.geometry.coordinates] : feature.geometry.coordinates;
        for(const polygon of polygons) for(const ring of polygon) for(const [x,y] of ring) {
            west=Math.min(x,west);east=Math.max(x,east);south=Math.min(y,south);north=Math.max(y,north);
        }
        return {feature,west,east,south,north,neighbours:[],colour:-1};
    }).sort((a,b)=>a.west-b.west || String(a.feature.id).localeCompare(String(b.feature.id)));
    const tolerance=.003;
    for(let i=0;i<nodes.length;i++){
        const a=nodes[i];
        for(let j=i+1;j<nodes.length && nodes[j].west<=a.east+tolerance;j++){
            const b=nodes[j];
            if(b.south<=a.north+tolerance && a.south<=b.north+tolerance){
                a.neighbours.push(b);b.neighbours.push(a);
            }
        }
    }
    const result=new Map();
    for(const node of [...nodes].sort((a,b)=>b.neighbours.length-a.neighbours.length)){
        const taken=new Set(node.neighbours.map(n=>n.colour));
        let colour=0;while(taken.has(colour))colour++;
        node.colour=colour;
        result.set(node.feature.id,palette[colour] ?? ('hsl('+Math.round((colour*137.5)%360)+(dark?' 48% 32%)':' 37% 65%)')));
    }
    return result;
};
