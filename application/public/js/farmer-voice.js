export function bindFarmerVoice(root,{labels,locale,Speech=globalThis.SpeechRecognition||globalThis.webkitSpeechRecognition,secure=globalThis.isSecureContext!==false}){
    let active=null,allowed=false;
    const say=(panel,text)=>{panel.replaceChildren();panel.textContent=text;panel.hidden=false;};
    for(const button of root.querySelectorAll('[data-voice]')){
        const input=root.getElementById(button.dataset.voice),panel=root.createElement('div');
        panel.className='farmer-voice-status';panel.hidden=true;panel.setAttribute('role','status');panel.setAttribute('aria-live','polite');
        button.insertAdjacentElement('afterend',panel);button.setAttribute('aria-pressed','false');
        function start(){
            if(active){active.stop();return;}
            const words=labels();
            if(!secure){say(panel,words.voice_secure);const link=root.createElement('a');link.href='https://pollmedia.org/agri-insights';link.textContent=words.voice_secure_link;panel.append(link);return;}
            if(!Speech){say(panel,words.not_supported);input.focus();return;}
            if(!allowed){
                say(panel,words.voice_consent);
                const allow=root.createElement('button');allow.type='button';allow.textContent=words.voice_allow;
                allow.addEventListener('click',()=>{allowed=true;start();});panel.append(allow);panel.scrollIntoView?.({block:'nearest',behavior:'smooth'});allow.focus();return;
            }
            const recognition=new Speech();let received=false,failed=false;active=recognition;
            const original=input.dataset.field?'':input.value.trim();
            recognition.lang=locale();recognition.interimResults=true;recognition.continuous=true;
            button.textContent=words.stop;button.setAttribute('aria-pressed','true');say(panel,words.voice_starting);
            recognition.onstart=()=>say(panel,labels().voice_listening);
            recognition.onresult=event=>{
                const text=Array.from(event.results).map(result=>result[0].transcript).join(' ').trim();
                if(!text)return;
                received=true;
                const value=(original?original+' ':'')+text;
                input.value=input.maxLength>0?value.trim().slice(0,input.maxLength):value.trim();
                const details=input.closest?.('details');if(details)details.open=true;
                input.dispatchEvent(new Event('input',{bubbles:true}));say(panel,labels().voice_added);
            };
            recognition.onerror=event=>{
                failed=true;
                if(received&&event.error==='no-speech'){say(panel,labels().voice_added);return;}
                const key=['not-allowed','service-not-allowed'].includes(event.error)?'voice_permission':event.error==='audio-capture'?'voice_microphone':event.error==='network'?'voice_network':event.error==='no-speech'?'voice_no_speech':'not_supported';
                say(panel,labels()[key]);
            };
            recognition.onend=()=>{
                if(active===recognition)active=null;
                button.textContent=labels().speak;button.setAttribute('aria-pressed','false');
                if(!received&&!failed)say(panel,labels().voice_no_speech);
            };
            try{recognition.start();}catch{active=null;button.textContent=labels().speak;button.setAttribute('aria-pressed','false');say(panel,labels().voice_permission);}
        }
        button.addEventListener('click',start);
    }
    return ()=>{active?.stop();};
}
