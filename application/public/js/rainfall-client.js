export class RainfallClient {
    constructor(){this.cache=new WeakMap();this.pending=new Map();this.sequence=0;this.worker=null;}
    values(features){
        const fresh=[];
        for(const feature of features){const geometry=feature.geometry;if(geometry&&!this.cache.has(geometry)){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});this.cache.set(geometry,{promise,resolve,reject,value:null});fresh.push(geometry);}}
        const result=Promise.all(features.map(feature=>this.cache.get(feature.geometry)?.promise||Promise.resolve({})));
        if(fresh.length){
            try {
            this.worker??=new Worker(new URL('./rainfall-worker.js?v=20261014-1',import.meta.url),{type:'module'});
            this.worker.onmessage=({data})=>{const geometries=this.pending.get(data.id);if(!geometries)return;this.pending.delete(data.id);geometries.forEach((geometry,index)=>{const entry=this.cache.get(geometry);if(data.error){entry.reject(Error(data.error));this.cache.delete(geometry);}else{entry.value=data.values[index];entry.resolve(entry.value);}});};
            this.worker.onerror=()=>{for(const geometries of this.pending.values())for(const geometry of geometries){this.cache.get(geometry)?.reject(Error('Rainfall calculation unavailable'));this.cache.delete(geometry);}this.pending.clear();this.worker.terminate();this.worker=null;};
            const id=++this.sequence;this.pending.set(id,fresh);this.worker.postMessage({id,geometries:fresh});
            } catch(error) {for(const geometry of fresh){this.cache.get(geometry)?.reject(error);this.cache.delete(geometry);}}
        }
        return result;
    }
    value(feature,year){return this.cache.get(feature.geometry)?.value?.[year]??null;}
}
