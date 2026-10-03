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
    targets[1].events.pointerleave();
    assert.equal(tooltip.hidden,true);
    targets[1].events.click();
    assert.equal(tooltip.hidden,false);
    targets[1].events.keydown({key:'Escape'});
    assert.equal(tooltip.hidden,true);
});
