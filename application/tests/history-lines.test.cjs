const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');

class Element {
    constructor(tag='div'){this.tag=tag;this.children=[];this.attributes={};this.events={};this.style={};this.value='';this.textContent='';this.clientWidth=600;}
    setAttribute(key,value){this.attributes[key]=value;}
    append(...children){this.children.push(...children);}
    replaceChildren(...children){this.children=[...children];}
    addEventListener(key,callback){this.events[key]=callback;}
}

test('history chart joins years with straight lines and retains invisible keyboard targets',()=>{
    const data={unit:'%',rows:[{year:2020,turnout:50},{year:2021,turnout:60},{year:2022,turnout:70}],series:[{key:'turnout',label:'Turnout'}]};
    const nodes={'.history-chart-data':{textContent:JSON.stringify(data)},'[data-chart-from]':new Element('select'),'[data-chart-to]':new Element('select'),'.history-plot':new Element(),'.history-readout':new Element(),'.history-legend':new Element(),'h3':{textContent:'Voter turnout'}};
    nodes['[data-chart-from]'].value='2020';nodes['[data-chart-to]'].value='2022';
    const chart={querySelector:selector=>nodes[selector]};
    const document={documentElement:{lang:'en'},querySelectorAll:()=>[chart],createElementNS:(_namespace,tag)=>new Element(tag),createElement:tag=>new Element(tag),createTextNode:text=>({textContent:text})};
    vm.runInNewContext(readFileSync(path.join(__dirname,'../public/js/history-lines.js'),'utf8'),{document,ResizeObserver:class{observe(){}},Intl});

    const svg=nodes['.history-plot'].children[0];
    const line=svg.children.find(child=>child.tag==='path');
    assert.match(line.attributes.d,/^M[^C]*L[^C]*L/);
    assert.equal(svg.children.some(child=>child.tag==='circle'),false);
    const targets=svg.children.filter(child=>child.tag==='rect');
    assert.equal(targets.length,3);
    assert.equal(targets[0].attributes.fill,'transparent');
    targets[1].events.focus();
    assert.match(nodes['.history-readout'].textContent,/2021.*60/);
    const tooltip=nodes['.history-plot'].children[1];
    assert.equal(tooltip.hidden,false);
    assert.match(tooltip.textContent,/2021.*60/);
    assert.match(tooltip.style.left,/px$/);
    targets[2].events.focus();
    assert.ok(parseFloat(tooltip.style.top) >= 4);
    assert.equal(tooltip.style.transform,'none');
    targets[1].events.pointerleave();
    assert.equal(tooltip.hidden,true);
    targets[1].events.click();
    assert.equal(tooltip.hidden,false);
    targets[1].events.keydown({key:'Escape'});
    assert.equal(tooltip.hidden,true);
});

test('printing reveals chart values and restores previously closed tables afterwards',()=>{
    const closed={open:false}, alreadyOpen={open:true}, events={};
    const document={querySelectorAll:selector=>selector==='[data-history-chart]'?[]:[closed,alreadyOpen]};
    const window={addEventListener:(name,callback)=>events[name]=callback};
    vm.runInNewContext(readFileSync(path.join(__dirname,'../public/js/history-lines.js'),'utf8'),{document,window});
    events.beforeprint();
    assert.equal(closed.open,true);
    assert.equal(alreadyOpen.open,true);
    events.afterprint();
    assert.equal(closed.open,false);
    assert.equal(alreadyOpen.open,true);
});

test('a Census chart with one year draws a visible value bar',()=>{
    const data={unit:'people',singleValueBar:true,autoScale:true,rows:[{year:2011,population:2031007}],series:[{key:'population',label:'Population'}]};
    const nodes={'.history-chart-data':{textContent:JSON.stringify(data)},'[data-chart-from]':new Element('select'),'[data-chart-to]':new Element('select'),'.history-plot':new Element(),'.history-readout':new Element(),'.history-legend':new Element(),'h3':{textContent:'Population'}};
    nodes['[data-chart-from]'].value='2011';nodes['[data-chart-to]'].value='2011';
    const chart={querySelector:selector=>nodes[selector]};
    const document={documentElement:{lang:'en'},querySelectorAll:()=>[chart],createElementNS:(_namespace,tag)=>new Element(tag),createElement:tag=>new Element(tag),createTextNode:text=>({textContent:text})};
    vm.runInNewContext(readFileSync(path.join(__dirname,'../public/js/history-lines.js'),'utf8'),{document,ResizeObserver:class{observe(){}},Intl});
    const svg=nodes['.history-plot'].children[0];
    const bar=svg.children.find(child=>child.attributes.class==='history-single-value');
    assert.ok(bar.attributes.height>100);
    const hit=svg.children.find(child=>child.attributes.class==='history-hit-target');
    hit.events.focus();
    assert.match(nodes['.history-readout'].textContent,/20,31,007/);
});
