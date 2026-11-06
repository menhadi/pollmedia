const {test}=require('node:test');
const assert=require('node:assert/strict');
test('PC drill-down uses source parent numbers within the same state, never LGD codes',async()=>{
    const {electoralChildren}=await import('../public/js/india-village-map.js');
    const feature=(kind,state,code,parent)=>({properties:{kind,state,code,parent_pc_code:parent}});
    const parent=feature('pc','Uttar Pradesh',26,null);
    const child=feature('ac','Uttar Pradesh',127,26);
    assert.deepEqual(electoralChildren([child,feature('ac','Karnataka',127,26),feature('ac','Uttar Pradesh',128,137),feature('pc','Uttar Pradesh',26,26)],parent),[child]);
});
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

test('metric ranges preserve AC membership and distinguish zero from missing',async()=>{
 const {metricIndices}=await import('../public/js/india-village-map.js');
 const records=[{feature_index:0,values:{population:0}},{feature_index:1,values:{population:100}}];
 assert.deepEqual(metricIndices(4,records,'population','0','0',false),[0]);
 assert.deepEqual(metricIndices(4,records,'population','50','',false,[0,2,3]),[]);
 assert.deepEqual(metricIndices(4,records,'population','50','',true,[0,2,3]),[2,3]);
 assert.deepEqual(metricIndices(4,records,'','','',true,[1,3]),[1,3]);
});
