import {loadMarkets} from './market-prices.js?v=041db146335b';
const tFor=language=>(en,hi)=>language==='hi'?hi:en;
export function mountHub(container){
    const hub=container.querySelector('[data-place-hub]');if(!hub)return;
    const t=tFor(hub.dataset.language);
    const open=id=>{const panel=hub.querySelector(`#${id}`);if(panel){panel.scrollIntoView({block:'start',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}};
    container.querySelectorAll('[data-hub-jump]').forEach(link=>link.addEventListener('click',()=>open(link.dataset.hubJump)));
    hub.querySelectorAll('[data-hub-panel]').forEach(panel=>{
        const module=panel.querySelector('[data-hub-module]'),form=panel.querySelector('[data-hub-form]');
        if(form)form.addEventListener('toggle',()=>{if(form.open&&module&&!module.dataset.loaded){module.dataset.loaded='1';loadModule(module,panel,t);}});
        if(panel.hasAttribute('data-market-panel')&&!panel.dataset.loaded){panel.dataset.loaded='1';loadMarkets(panel,hub,t);}
    });
    const hash=location.hash.slice(1);if(['local-issues','agri-insight','government-schemes','schools-services','local-markets','gram-panchayat'].includes(hash))open(hash);
    hub.querySelectorAll('[data-local-data-browser]').forEach(browser=>{
        let version=0;
        const load=async({page=1,browse=null,reset=false}={})=>{
            const form=browser.querySelector('[data-local-data-form]');if(form&&!form.reportValidity())return;
            const token=++version,params=reset?new URLSearchParams():new URLSearchParams(form?new FormData(form):undefined);
            if(browse==='district')params.set('browse','district');else if(browse==='village')params.delete('browse');
            params.set('page',String(page));const url=new URL(browser.dataset.url,location.origin);for(const [key,value] of params)url.searchParams.set(key,value);
            const status=browser.parentElement.querySelector('[data-local-data-status]');status.textContent=t('Loading records…','रिकॉर्ड लोड हो रहे हैं…');browser.setAttribute('aria-busy','true');
            try{const response=await fetch(url,{headers:{Accept:'text/html'},signal:AbortSignal.timeout(20000)});if(!response.ok)throw new Error();const html=await response.text();if(token!==version)return;browser.innerHTML=html;status.textContent='';}
            catch(_){if(token===version)status.textContent=t('Records could not be loaded. Please retry.','रिकॉर्ड लोड नहीं हुए। फिर कोशिश करें।');}
            finally{if(token===version)browser.removeAttribute('aria-busy');}
        };
        browser.addEventListener('submit',event=>{if(event.target.matches('[data-local-data-form]')){event.preventDefault();load();}});
        browser.addEventListener('change',event=>{if(event.target.matches('select')){if(event.target.name==='block'){const village=browser.querySelector('[name=source_village]');if(village)village.value='';}load();}});
        browser.addEventListener('click',event=>{const page=event.target.closest('[data-local-page]'),browse=event.target.closest('[data-local-browse]');if(page&&!page.disabled)load({page:Number(page.dataset.localPage)});if(browse)load({browse:browse.dataset.localBrowse,reset:true});});
    });
    const form=hub.querySelector('[data-hub-report]');
    if(form)form.addEventListener('submit',async event=>{
        event.preventDefault();if(!form.reportValidity())return;
        const button=form.querySelector('[type=submit]'),status=form.querySelector('[data-report-status]');if(button.disabled)return;
        button.disabled=true;status.textContent=t('Posting your report…','आपकी रिपोर्ट प्रकाशित हो रही है…');
        try{
            const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json','X-CSRF-TOKEN':form.querySelector('[name=_token]').value}});
            const data=await response.json();if(!response.ok)throw new Error();
            const url=new URL(data.url,location.origin);if(url.origin!==location.origin||!/^\/issues\/[0-9A-HJKMNP-TV-Z]{26}$/.test(url.pathname))throw new Error();
            status.replaceChildren(document.createTextNode(t('Report published. ','रिपोर्ट प्रकाशित हो गई। ')));
            const link=document.createElement('a');link.href=url.href;link.textContent=t('View report','रिपोर्ट देखें');status.append(link);
            const post=document.createElement('p'),postLink=link.cloneNode(true);postLink.textContent=form.querySelector('[name=title]').value;post.append(postLink);hub.querySelector('[data-hub-feed]').prepend(post);
            form.querySelector('[name=title]').value='';form.querySelector('[name=description]').value='';form.querySelector('[name="media[]"]').value='';form.querySelector('[name=consent]').checked=false;form.querySelector('[name=place_confirmed]').checked=false;
        }catch(_){status.textContent=t('The report was not posted. Check the required details and file sizes, then retry.','रिपोर्ट प्रकाशित नहीं हुई। ज़रूरी जानकारी और फ़ाइल का आकार जाँचकर फिर कोशिश करें।');}finally{button.disabled=false;}
    });
}
async function loadModule(target,panel,t){
    const status=panel.querySelector('[data-frame-status]');status.textContent=t('Loading the form…','फॉर्म लोड हो रहा है…');
    try{
        const url=new URL(target.dataset.src,location.origin);if(url.origin!==location.origin)throw new Error();
        const response=await fetch(url,{headers:{Accept:'text/html'}});if(!response.ok)throw new Error();
        const parsed=new DOMParser().parseFromString(await response.text(),'text/html');
        const kind=panel.id==='agri-insight'?'farmer-assistant':'scheme-finder';
        const source=parsed.querySelector(kind==='farmer-assistant'?'#farmer-app':'#scheme-finder');if(!source)throw new Error();
        const main=document.createElement('div');for(const attr of source.attributes)main.setAttribute(attr.name,attr.value);
        main.append(...source.childNodes);main.querySelectorAll('script:not([type="application/json"])').forEach(script=>script.remove());
        if(!target.isConnected)return;
        parsed.querySelectorAll('link[rel="stylesheet"]').forEach(link=>{const style=new URL(link.href,location.origin);if(style.origin===location.origin&&['/css/farmer-assistant.css','/css/agri-examples.css'].includes(style.pathname)&&![...document.querySelectorAll('link[rel="stylesheet"]')].some(item=>item.href===style.href)){const own=document.createElement('link');own.rel='stylesheet';own.href=style.href;document.head.append(own);}});
        const script=parsed.querySelector(`script[type="module"][src*="/js/${kind}.js"]`);if(!script)throw new Error();
        const scriptUrl=new URL(script.src,location.origin);if(scriptUrl.origin!==location.origin||scriptUrl.pathname!==`/js/${kind}.js`)throw new Error();
        target.replaceChildren(main);
        const module=await import(scriptUrl.href);if(!target.isConnected)return;
        if(kind==='farmer-assistant')await module.mountFarmerAssistant(main);else module.mountSchemeFinder(main);
        status.textContent='';
    }catch(_){delete target.dataset.loaded;status.textContent=t('The form could not load. Close and reopen this section to retry.','फॉर्म लोड नहीं हुआ। फिर कोशिश करने के लिए इस खंड को बंद करके खोलें।');}
}
