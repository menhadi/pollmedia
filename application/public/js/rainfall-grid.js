// Derived estimates only: source geometries, properties and identifiers are never edited.
const eccentricity = Math.sqrt(0.0066943799901413165);
function equalAreaY(latitude) {
    const sine = Math.sin(latitude * Math.PI / 180), e = eccentricity;
    return (1-e*e)*(sine/(1-e*e*sine*sine)-Math.log((1-e*sine)/(1+e*sine))/(2*e));
}
function ringArea(ring) {
    let area=0;
    for(let i=0,j=ring.length-1;i<ring.length;j=i++)area+=ring[j][0]*equalAreaY(ring[i][1])-ring[i][0]*equalAreaY(ring[j][1]);
    return Math.abs(area)/2;
}
function clip(ring,axis,edge,greater) {
    if(!ring.length)return [];
    const output=[];let previous=ring[ring.length-1],inside=greater?previous[axis]>=edge:previous[axis]<=edge;
    for(const point of ring){const next=greater?point[axis]>=edge:point[axis]<=edge;
        if(next!==inside){const t=(edge-previous[axis])/(point[axis]-previous[axis]);output.push([previous[0]+t*(point[0]-previous[0]),previous[1]+t*(point[1]-previous[1])]);}
        if(next)output.push(point);previous=point;inside=next;
    }return output;
}
function cellArea(polygon,west,south,east,north) {
    let area=0;
    polygon.forEach((ring,index)=>{let clipped=clip(ring,0,west,true);clipped=clip(clipped,0,east,false);clipped=clip(clipped,1,south,true);clipped=clip(clipped,1,north,false);area+=(index===0?1:-1)*ringArea(clipped);});
    return Math.max(0,area);
}
export function rainfallForGeometry(geometry,grid) {
    const years=Object.keys(grid.years),missing=Object.fromEntries(years.map(year=>[year,null]));
    const polygons=geometry?.type==='Polygon'?[geometry.coordinates]:geometry?.type==='MultiPolygon'?geometry.coordinates:null;
    if(!polygons?.length)return missing;
    const weights=new Map();
    for(const polygon of polygons){
        if(!polygon?.[0]?.length)return missing;
        let west=Infinity,south=Infinity,east=-Infinity,north=-Infinity;
        for(const ring of polygon){if(ring.length<4)return missing;for(const point of ring){const [x,y]=point;if(!Number.isFinite(x)||!Number.isFinite(y)||Math.abs(x)>180||Math.abs(y)>90)return missing;west=Math.min(west,x);east=Math.max(east,x);south=Math.min(south,y);north=Math.max(north,y);}}
        const area=polygon.reduce((sum,ring,index)=>sum+(index===0?1:-1)*ringArea(ring),0);if(area<=0)return missing;
        const size=grid.spacing,half=size/2;
        // The regular grid covers its entire rectangle. Reject any geometry outside it.
        // Comparing projected area before/after inserting clip vertices is not exact.
        if(west<grid.longitude[0]-half||east>grid.longitude.at(-1)+half||south<grid.latitude[0]-half||north>grid.latitude.at(-1)+half)return missing;
        const x0=Math.max(0,Math.floor((west-grid.longitude[0]+half)/size)),x1=Math.min(grid.longitude.length-1,Math.floor((east-grid.longitude[0]+half)/size));
        const y0=Math.max(0,Math.floor((south-grid.latitude[0]+half)/size)),y1=Math.min(grid.latitude.length-1,Math.floor((north-grid.latitude[0]+half)/size));
        for(let i=y0;i<=y1;i++)for(let j=x0;j<=x1;j++){
            const x=grid.longitude[j],y=grid.latitude[i],overlap=cellArea(polygon,x-half,y-half,x+half,y+half);
            if(overlap>area*1e-10){const key=i*grid.longitude.length+j;weights.set(key,(weights.get(key)||0)+overlap);}
        }
    }
    const covered=[...weights.values()].reduce((a,b)=>a+b,0);
    if(!covered)return missing;
    return Object.fromEntries(years.map(year=>{let total=0;for(const [key,weight]of weights){const value=grid.years[year].annual_mm[key];if(value==null||!Number.isFinite(value)||value<0)return [year,null];total+=value*weight;}return [year,Math.round(total/covered*100)/100];}));
}

export function mergeRainfallRecords(records,values,year) {
    const rows=new Map(records.map(row=>[row.feature_index,{...row,values:{...row.values}}]));
    values.forEach((series,index)=>{const row=rows.get(index)||{feature_index:index,values:{}};row.values.rainfall=series[year]??null;rows.set(index,row);});
    return [...rows.values()];
}
