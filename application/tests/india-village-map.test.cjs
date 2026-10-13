const {test}=require('node:test');
const assert=require('node:assert/strict');
test('viewport loading bounds memory, respects state and excludes offscreen layers',async()=>{
    const {visibleLayers}=await import('../public/js/india-village-map.js');
    const layers=Array.from({length:8},(_,i)=>({id:String(i),state:i===7?'Other':'State',bbox:i===6?[20,20,21,21]:[0,0,1,1],bytes:3000000}));
    assert.equal(visibleLayers(layers,[0,0,2,2],'State').length,4);
    assert.equal(visibleLayers(layers,[0,0,2,2],'State',4,5000000).length,1);
    assert.equal(visibleLayers(layers,[30,30,31,31]).length,0);
});
test('source links reject executable protocols',async()=>{
    const {safeLink}=await import('../public/js/india-village-map.js');
    assert.equal(safeLink('javascript:alert(1)'),false);
    assert.equal(safeLink('https://pollmedia.org/india/map'),true);
    assert.equal(safeLink('http://untrusted.example'),false);
});
