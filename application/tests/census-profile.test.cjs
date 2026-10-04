const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
test('selecting a Census year shows its cards and hides the previous year',()=>{
    const panels=[{dataset:{censusYearPanel:'2001'},hidden:false},{dataset:{censusYearPanel:'2011'},hidden:false}];
    const events={},section={querySelectorAll:()=>panels};
    const select={value:'2011',closest:()=>section,addEventListener:(name,callback)=>events[name]=callback};
    vm.runInNewContext(readFileSync(path.join(__dirname,'../public/js/census-profile.js'),'utf8'),{document:{querySelectorAll:()=>[select]}});
    assert.equal(panels[0].hidden,true);assert.equal(panels[1].hidden,false);
    select.value='2001';events.change();
    assert.equal(panels[0].hidden,false);assert.equal(panels[1].hidden,true);
});
