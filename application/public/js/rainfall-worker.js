import {rainfallForGeometry} from './rainfall-grid.js?v=20261014-1';
let gridPromise;
self.onmessage=async({data})=>{
    try{
        gridPromise??=fetch('/maps/imd-rainfall-annual-2023-2024.json').then(response=>{if(!response.ok)throw Error('IMD rainfall grid unavailable');return response.json();}).catch(error=>{gridPromise=null;throw error;});
        const grid=await gridPromise;
        self.postMessage({id:data.id,values:data.geometries.map(geometry=>rainfallForGeometry(geometry,grid))});
    }catch(error){self.postMessage({id:data.id,error:error.message});}
};
