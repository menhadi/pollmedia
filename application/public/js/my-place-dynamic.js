import './water-evidence.js?v=1';
import {mountTables} from './my-place-tables.js?v=20261029b';
import {placeHierarchy} from './place-hierarchy.js?v=1253aea07152';
import {mountHub} from './my-place-hub.js?v=ca931f86f8cd';
const root=document.querySelector('#my-place-dynamic');
if(root){
    mountTables(root);
    const language=root.dataset.language, hi=language==='hi', t=(en,hindi)=>hi?hindi:en;
    const status=root.querySelector('[data-status]'), form=root.querySelector('[data-hierarchy]'), result=root.querySelector('[data-place-result]'), choices=root.querySelector('[data-choices]');
    let hierarchy, hierarchyReady, pending=0, locationVersion=0, interacted=false, detectedPlace=null, locationRequest=null;
    const savedKey='pollmedia-my-place-v2',remember=root.querySelector('.hub-remember');
    const present=()=>{const heading=result.querySelector('h2');if(heading){root.dataset.selected='true';root.querySelector('h1').textContent=t('My Place','मेरा क्षेत्र');const placeLabel=root.querySelector('[data-selected-place-label]');if(placeLabel)placeLabel.textContent=heading.dataset.placeHeading||heading.textContent;document.title=t('My Place','मेरा क्षेत्र')+' · '+(heading.dataset.placeHeading||heading.textContent)+' | Pollmedia';form.hidden=false;if(remember)remember.hidden=false;root.querySelector('[data-manual]').textContent=t('Change place','क्षेत्र बदलें');}};
    const message=text=>{status.textContent=text;};
    const render=async(place=null)=>{
        if(!place&&!form.reportValidity())return;
        const token=++pending;
        message(t('Loading your place…','आपके क्षेत्र की जानकारी लोड हो रही है…'));
        const params=new URLSearchParams(place?Object.entries(place).filter(([key,value])=>['state','district','subdistrict','village','locality'].includes(key)&&value!=null):new FormData(form));params.set('lang',language);
        const url=new URL(root.dataset.summary);url.search=params;
        try{
            const response=await fetch(url,{headers:{Accept:'text/html'},signal:AbortSignal.timeout(30000)});
            if(!response.ok)throw new Error(t('This place could not be confirmed. Check the selection and retry.','क्षेत्र की पुष्टि नहीं हुई। चयन जाँचें और फिर कोशिश करें।'));
            const html=await response.text();if(token!==pending)return;
            result.querySelector('#farmer-app')?.dispatchEvent(new Event('my-place-dispose'));result.innerHTML=html;
            history.replaceState(null,'',`/my-place?${params}`);
            for(const link of document.querySelectorAll('.public-header .language-switch a, #my-place-dynamic > .place-hero .place-languages a')){
                const nextLanguage=new URL(link.href,location.origin).searchParams.get('lang');
                if(!['en','hi'].includes(nextLanguage))continue;
                const translated=new URLSearchParams(params);translated.set('lang',nextLanguage);link.href=`/my-place?${translated}`;
            }
            if(root.querySelector('[data-save-place]')?.checked&&interacted){try{localStorage.setItem(savedKey,JSON.stringify(Object.fromEntries(params)));}catch(_){}}
            initServices();
            present();
            message(t('Your place is ready.','आपके क्षेत्र की जानकारी तैयार है।'));
        }catch(error){if(token===pending)message(error.name==='TimeoutError'?t('This is taking longer than expected. Please retry Show my place.','जानकारी लोड होने में अधिक समय लग रहा है। मेरा क्षेत्र देखें फिर दबाएँ।'):error.message);}
    };
    form.addEventListener('submit',event=>{event.preventDefault();if(hierarchy)render();});
    form.addEventListener('change',()=>{interacted=true;++pending;++locationVersion;result.querySelector('#farmer-app')?.dispatchEvent(new Event('my-place-dispose'));result.replaceChildren();choices.replaceChildren();message(t('Choose your place, then tap Show my place.','क्षेत्र चुनें, फिर मेरा क्षेत्र देखें दबाएँ।'));});
    form.addEventListener('place-error',event=>message(event.detail));
    root.querySelector('[data-manual]').addEventListener('click',async()=>{interacted=true;++pending;const version=++locationVersion;locationRequest?.abort();root.querySelector('[data-detect]').disabled=false;form.hidden=false;if(remember)remember.hidden=false;choices.replaceChildren();message(t('Choose your state, district and village.','अपना राज्य, जिला और गाँव चुनें।'));try{const ready=hierarchy||await hierarchyReady;if(version!==locationVersion)return;if(detectedPlace){await ready.set(detectedPlace);detectedPlace=null;}if(version===locationVersion)ready.fields.state.focus();}catch(error){if(version===locationVersion)message(error.message);}});
    const applyLocation=async(choice,version)=>{
        if(version!==locationVersion)return;
        detectedPlace=choice;
        await render(choice);
    };
    const detect=(automatic=false)=>{
        const version=++locationVersion;++pending;form.hidden=true;if(remember)remember.hidden=true;locationRequest?.abort();
        if(!navigator.geolocation){message(t('Location is not supported here. Choose your place manually.','यहाँ लोकेशन समर्थित नहीं है। क्षेत्र खुद चुनें।'));return;}
        const button=root.querySelector('[data-detect]');button.disabled=true;
        message(t('Allow location access when your browser asks. Finding your location…','ब्राउज़र पूछे तो लोकेशन की अनुमति दें। आपकी लोकेशन खोज रहे हैं…'));choices.replaceChildren();
        navigator.geolocation.getCurrentPosition(async position=>{
            if(version!==locationVersion)return;
            try{
                message(t('Finding your area in existing maps…','मौजूदा नक्शों में आपका क्षेत्र खोज रहे हैं…'));
                locationRequest=new AbortController();
                const response=await fetch(root.dataset.locate,{method:'POST',signal:AbortSignal.any([locationRequest.signal,AbortSignal.timeout(25000)]),headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({latitude:position.coords.latitude,longitude:position.coords.longitude,accuracy:position.coords.accuracy})});
                if(response.status===419)throw new Error(t('Your session expired. Refresh this page, then tap Use my location again.','आपका सत्र समाप्त हुआ। पेज रीफ़्रेश करें, फिर मेरी लोकेशन इस्तेमाल करें दबाएँ।'));
                if(response.status===429)throw new Error(t('Too many location attempts. Wait a minute, then retry.','लोकेशन के कई प्रयास हुए। एक मिनट बाद फिर कोशिश करें।'));
                if(!response.ok)throw new Error(t('Location lookup failed. Retry or choose your place manually.','लोकेशन खोज असफल रही। फिर कोशिश करें या क्षेत्र खुद चुनें।'));
                const data=await response.json();
                if(version!==locationVersion)return;
                if(!data.choices.length){message(t('No unique area is available in these maps. Please choose manually.','इन नक्शों में क्षेत्र की स्पष्ट पहचान नहीं मिली। कृपया खुद चुनें।'));return;}
                if(automatic&&data.choices.length===1){await applyLocation(data.choices[0],version);return;}
                for(const choice of data.choices){
                    const confirm=document.createElement('button');confirm.type='button';confirm.textContent=`${t('Use this area','यह क्षेत्र चुनें')}: ${choice.name}${choice.village||choice.locality?'':t(' · district coverage',' · जिले की जानकारी')}`;
                    confirm.addEventListener('click',async()=>{confirm.disabled=true;try{await applyLocation(choice,version);if(version===locationVersion)choices.replaceChildren();}catch(_){if(version===locationVersion)message(t('Choose the area manually to continue.','आगे बढ़ने के लिए क्षेत्र खुद चुनें।'));}finally{confirm.disabled=false;}});choices.append(confirm);
                }
                message(t('Confirm your area below. A precise village is shown only when map links and location accuracy permit.','नीचे क्षेत्र की पुष्टि करें। सही नक्शा संबंध और पर्याप्त लोकेशन सटीकता होने पर ही गाँव दिखता है।'));
            }catch(error){if(version===locationVersion)message(error.name==='TimeoutError'?t('The area lookup timed out. Retry or choose your place manually.','क्षेत्र खोज का समय समाप्त हुआ। फिर कोशिश करें या क्षेत्र खुद चुनें।'):error.message);}finally{if(version===locationVersion)button.disabled=false;}
        },error=>{if(version!==locationVersion)return;button.disabled=false;message(error?.code===1?t('Location permission is blocked. Allow location in your browser site settings, then retry, or choose your place manually.','लोकेशन की अनुमति बंद है। ब्राउज़र की साइट सेटिंग में अनुमति देकर फिर कोशिश करें या क्षेत्र खुद चुनें।'):error?.code===3?t('Your device could not find a location in time. Turn on location services and retry, or choose your place manually.','डिवाइस समय पर लोकेशन नहीं खोज पाया। लोकेशन चालू करके फिर कोशिश करें या क्षेत्र खुद चुनें।'):t('Your device could not provide a location. Turn on location services, retry, or choose your place manually.','डिवाइस से लोकेशन नहीं मिली। लोकेशन चालू करें, फिर कोशिश करें या क्षेत्र खुद चुनें।'));},{enableHighAccuracy:true,timeout:20000,maximumAge:0});
    };
    root.querySelector('[data-detect]').addEventListener('click',()=>{interacted=true;detect(true);});
    root.querySelector('[data-forget-place]')?.addEventListener('click',()=>{try{localStorage.removeItem(savedKey);}catch(_){}root.querySelector('[data-save-place]').checked=false;message(t('Saved place removed from this device.','इस डिवाइस से सहेजा क्षेत्र हटा दिया गया।'));});
    function initServices(){
        mountHub(result);
        const tools=result.querySelector('[data-service-tools]');if(!tools)return;tools.hidden=false;
        const cards=[...result.querySelectorAll('[data-service-card]')], search=tools.querySelector('[data-service-search]'), count=tools.querySelector('[data-service-count]');let category='all';
        const filter=()=>{let shown=0;for(const card of cards){card.hidden=(category!=='all'&&!card.dataset.category.split(' ').includes(category))||!card.dataset.search.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase());if(!card.hidden)shown++;}count.textContent=count.dataset.template.replace(':count',shown);result.querySelector('[data-service-empty]').hidden=shown!==0;};
        tools.querySelectorAll('button[data-category]').forEach(button=>button.addEventListener('click',()=>{category=button.dataset.category;tools.querySelectorAll('button[data-category]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));filter();}));search.addEventListener('input',filter);filter();
    }
    initServices();
    present();
    const initial=JSON.parse(root.dataset.initial);
    hierarchyReady=placeHierarchy(form,root.dataset.options,language,initial);
    hierarchyReady.then(async value=>{
        hierarchy=value;if(initial.state||interacted)return;
        try{const permission=await navigator.permissions?.query({name:'geolocation'});if(interacted)return;if(permission?.state==='granted'){detect(true);return;}}catch(_){}
        try{const saved=JSON.parse(localStorage.getItem(savedKey)||'null');if(saved?.state&&saved?.district&&!interacted){await hierarchy.set(saved);await render(saved);}}catch(_){}
    }).catch(error=>message(error.message));
}
