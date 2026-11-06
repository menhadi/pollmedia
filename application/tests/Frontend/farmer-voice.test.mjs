import test from 'node:test';
import assert from 'node:assert/strict';
import {bindFarmerVoice} from '../../public/js/farmer-voice.js';

class Element extends EventTarget{
    constructor(){super();this.dataset={};this.children=[];this.attributes={};this.value='';this.maxLength=-1;}
    setAttribute(key,value){this.attributes[key]=value;}
    replaceChildren(){this.children=[];this.textContent='';}
    append(child){this.children.push(child);}
    insertAdjacentElement(position,panel){this.panel=panel;}
    focus(){this.focused=true;}
    click(){this.dispatchEvent(new Event('click'));}
}
function fixture({supported=true,field=false,secure=true}={}){
    const input=new Element(),button=new Element();button.dataset.voice='story';if(field)input.dataset.field='crop';
    let instances=[];
    class Speech{
        constructor(){instances.push(this);}
        start(){this.started=true;this.onstart();}
        stop(){this.stopped=true;this.onend();}
        result(text){this.onresult({resultIndex:0,results:[Object.assign([{transcript:text}],{isFinal:true})]});}
        error(error){this.onerror({error});this.onend();}
    }
    const labels=Object.fromEntries(['speak','stop','not_supported','voice_consent','voice_allow','voice_starting','voice_listening','voice_added','voice_permission','voice_microphone','voice_network','voice_no_speech'].map(key=>[key,key]));
    const root={querySelectorAll:()=>[button],getElementById:()=>input,createElement:()=>new Element()};
    labels.voice_secure='Secure page required';labels.voice_secure_link='Open secure page';
    bindFarmerVoice(root,{labels:()=>labels,locale:()=>'hi-IN',Speech:supported?Speech:null,secure});
    return {button,input,instances,start:()=>{button.click();button.panel.children[0].click();}};
}
test('voice asks for explicit speech consent beside the button before starting',()=>{
    const f=fixture();f.button.click();assert.equal(f.instances.length,0);assert.equal(f.button.panel.textContent,'voice_consent');
    f.button.panel.children[0].click();assert.equal(f.instances.length,1);assert.equal(f.instances[0].lang,'hi-IN');assert.equal(f.button.panel.textContent,'voice_listening');assert.equal(f.button.attributes['aria-pressed'],'true');
});
test('dictation appends text and emits input; Stop restores the button and permits retry',()=>{
    const f=fixture();f.input.value='My field';let changed=0;f.input.addEventListener('input',()=>changed++);f.start();f.instances[0].result('has wheat');f.button.click();
    assert.equal(f.input.value,'My field has wheat');assert.equal(changed,1);assert.equal(f.button.textContent,'speak');assert.equal(f.button.attributes['aria-pressed'],'false');f.button.click();assert.equal(f.instances.length,2);
});
test('dictation replaces an editable suggested field and respects its length limit',()=>{
    const f=fixture({field:true});f.input.value='Rice';f.input.maxLength=5;f.start();f.instances[0].result('Wheat crop');assert.equal(f.input.value,'Wheat');
});
test('live partial words are retained and final updates do not duplicate dictated text',()=>{
    const f=fixture();f.input.value='My field';f.start();const speech=f.instances[0];
    assert.equal(speech.continuous,true);assert.equal(speech.interimResults,true);
    speech.onresult({resultIndex:0,results:[Object.assign([{transcript:'has rice'}],{isFinal:false})]});
    assert.equal(f.input.value,'My field has rice');speech.result('has rice today');assert.equal(f.input.value,'My field has rice today');
    speech.error('no-speech');assert.equal(f.input.value,'My field has rice today');assert.equal(f.button.panel.textContent,'voice_added');
});
for(const [error,label] of [['not-allowed','voice_permission'],['audio-capture','voice_microphone'],['network','voice_network'],['no-speech','voice_no_speech']]){
    test(error+' shows an actionable inline message and resets the control',()=>{const f=fixture();f.start();f.instances[0].error(error);assert.equal(f.button.panel.textContent,label);assert.equal(f.button.textContent,'speak');});
}
test('unsupported browsers show the fallback beside the button and focus the editable field',()=>{
    const f=fixture({supported:false});f.button.click();assert.equal(f.button.panel.textContent,'not_supported');assert.equal(f.input.focused,true);assert.equal(f.instances.length,0);
});
test('insecure pages never start the microphone and offer the canonical HTTPS link',()=>{
    const f=fixture({secure:false});f.button.click();assert.equal(f.instances.length,0);assert.equal(f.button.panel.textContent,'Secure page required');assert.equal(f.button.panel.children[0].href,'https://pollmedia.org/agri-insights');
});
test('spoken words open the optional typing panel for review',()=>{
    const f=fixture();const details={open:false};f.input.closest=()=>details;f.start();f.instances[0].result('Rice leaves are yellow');assert.equal(details.open,true);assert.equal(f.input.value,'Rice leaves are yellow');
});
