import test from 'node:test';
import assert from 'node:assert/strict';
import {placeOptions} from '../../public/js/place-hierarchy.js';

test('concurrent place lists share a request but keep district and language boundaries',async()=>{
    const previousFetch=globalThis.fetch,previousLocation=globalThis.location;
    globalThis.location={origin:'https://pollmedia.org'};
    const urls=[];
    globalThis.fetch=async url=>{urls.push(String(url));return {ok:true,json:async()=>({options:[{code:new URL(url).searchParams.get('district')||'9',name:'Stored place'}]})};};
    try {
        const [first,second]=await Promise.all([placeOptions('/cache-test','en',{state:'9',district:'173'}),placeOptions('/cache-test','en',{district:'173',state:'9'})]);
        assert.equal(urls.length,1);assert.equal(first[0].code,'173');assert.deepEqual(first,second);
        await placeOptions('/cache-test','hi',{state:'9',district:'173'});
        const other=await placeOptions('/cache-test','en',{state:'9',district:'174'});
        assert.equal(urls.length,3);assert.equal(other[0].code,'174');
    }finally {globalThis.fetch=previousFetch;globalThis.location=previousLocation;}
});
test('failed place requests can retry instead of caching an empty list',async()=>{
    const previousFetch=globalThis.fetch,previousLocation=globalThis.location;
    globalThis.location={origin:'https://pollmedia.org'};
    let calls=0;
    globalThis.fetch=async()=>++calls===1?{ok:false}:{ok:true,json:async()=>({options:[{code:'173',name:'Pilibhit'}]})};
    try {
        await assert.rejects(placeOptions('/retry-cache-test','en',{state:'9'}));
        assert.equal((await placeOptions('/retry-cache-test','en',{state:'9'}))[0].code,'173');assert.equal(calls,2);
    }finally {globalThis.fetch=previousFetch;globalThis.location=previousLocation;}
});
