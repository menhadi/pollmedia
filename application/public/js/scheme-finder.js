import {placeHierarchy} from './place-hierarchy.js?v=1253aea07152';
import {ageFromDate,checkScheme} from './scheme-rules.js?v=57b50a5883dc';
export function mountSchemeFinder(root){
if(!root||root.dataset.mounted)return;root.dataset.mounted='1';
    const lang=root.dataset.language,hi=lang==='hi',t=(en,hindi)=>hi?hindi:en;
    const catalogue=JSON.parse(root.querySelector('#scheme-catalogue').textContent),form=root.querySelector('[data-scheme-form]'),results=root.querySelector('[data-results]'),status=root.querySelector('[data-status]'),ocrStatus=root.querySelector('[data-ocr-status]');
    const fields=Object.fromEntries([...form.querySelectorAll('[data-field]')].map(el=>[el.dataset.field,el]));
    let hierarchy,upload=null,generation=0,profileVersion=0,pinGeneration=0,residenceVersion=0,pinRequests=0;
    const manualAge=root.querySelector('[data-age-manual]'),pinStatus=root.querySelector('[data-pin-status]');
    const syncAgeMode=()=>{fields.age.readOnly=!manualAge?.checked;fields.dob.required=!manualAge?.checked;};syncAgeMode();
    const profile=()=>Object.fromEntries(Object.entries(fields).map(([key,el])=>[key,el.value]));
    const fieldName=key=>catalogue.questions[key]?.[lang]||({age:t('Age','आयु'),state:t('State of residence','निवास का राज्य')})[key]||key;
    const valueLabel=(field,value)=>catalogue.question_fields?.[field]?.options?.[String(value)]?.[lang]??String(value);
    const describe=(rule,scheme)=>{
        if(rule.field==='state')return t('Residence','निवास')+': '+(String(rule.value)===String(scheme.scope)?scheme.scope_name?.[lang]||rule.value:rule.value);
        if(rule.op==='income_limit'){const format=n=>new Intl.NumberFormat(hi?'hi-IN':'en-IN').format(n);return t(`Annual family income: up to ₹${format(rule.value.rural)} rural / ₹${format(rule.value.urban)} urban`,`परिवार की सालाना आय: ग्रामीण ₹${format(rule.value.rural)} / शहरी ₹${format(rule.value.urban)} तक`);}
        const label=['in','not_in'].includes(rule.op)?rule.value.map(value=>valueLabel(rule.field,value)).join(', '):valueLabel(rule.field,rule.value);
        const relation={eq:': ',gte:t(': at least ',': कम से कम '),lte:t(': at most ',': अधिकतम '),in:t(': one of ',': इनमें से '),not_in:t(': excludes ',': इनमें नहीं ')}[rule.op]||': ';
        return fieldName(rule.field)+relation+label+(rule.field==='age'?t(' years',' वर्ष'):'');
    };
    const candidates=()=>{
        const p=profile();
        return catalogue.schemes.filter(s=>(p.interest==='all'||s.category===p.interest)&&(s.scope==='IN'||!p.state||s.scope===p.state));
    };
    const questions=()=>{
        const coverage=root.querySelector('[data-scheme-coverage]'),state=fields.state.value;
        if(coverage){const national=catalogue.schemes.filter(s=>s.scope==='IN').length,local=catalogue.schemes.filter(s=>s.scope===state).length;const place=[fields.state.selectedOptions?.[0]?.textContent,fields.district.value?fields.district.selectedOptions?.[0]?.textContent:null].filter(Boolean).join(' · ');coverage.textContent=state?t(`Residence: ${place}. This catalogue lists ${national} national and ${local} state programmes. It does not include every scheme.`,`निवास: ${place}। इस सूची में ${national} राष्ट्रीय और ${local} राज्य योजनाएँ हैं। इसमें सभी योजनाएँ शामिल नहीं हैं।`):t('Choose your residence to see the programmes covered by this catalogue.','इस सूची में उपलब्ध योजनाएँ देखने के लिए अपना निवास चुनें।');}
        const p=profile(),needed=new Set();
        for(const s of p.age!==''&&p.state?candidates():[]){
            if(['unavailable','needs_verification'].includes(checkScheme(s,p).status))continue;
            const basic=s.rules.filter(r=>['age','state'].includes(r.field));
            if(basic.length&&checkScheme({...s,rules:basic},p).status==='not_matched')continue;
            s.rules.forEach(r=>{needed.add(r.field);if(r.op==='income_limit')needed.add('residence_type');});
        }
        root.querySelectorAll('[data-question]').forEach(el=>{el.hidden=!needed.has(el.dataset.question);});
    };
    const el=(tag,text,cls)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(cls)node.className=cls;return node;};
    const link=(text,href)=>{const node=el('a',text,'place-button secondary');node.href=href;node.target='_blank';node.rel='noopener noreferrer';return node;};
    const lookupPin=async()=>{
        if(!hierarchy||!pinStatus)return;
        const pin=fields.pincode.value,current=++pinGeneration,revision=residenceVersion;
        root.querySelector('[data-confirm]').checked=false;results.hidden=true;
        if(!/^[1-9][0-9]{5}$/.test(pin)){pinStatus.textContent=t('Enter a six-digit PIN code.','छह अंकों का पिन कोड भरें।');return;}
        pinRequests++;checkButton.disabled=true;
        pinStatus.textContent=t('Finding state and district…','राज्य और जिला खोज रहे हैं…');
        try{
            const url=new URL(root.dataset.pincodeUrl,location.origin);url.searchParams.set('pincode',pin);
            const response=await fetch(url,{headers:{Accept:'application/json'}});if(!response.ok)throw new Error();const data=await response.json();
            if(current!==pinGeneration||pin!==fields.pincode.value)return;
            if(revision!==residenceVersion){pinStatus.textContent=t('Your edits were kept. Tap Find state & district to retry.','आपके बदलाव सुरक्षित हैं। फिर कोशिश करने के लिए राज्य और जिला खोजें दबाएँ।');return;}
            if(!data.reason&&data.matches?.length===1){await hierarchy.set(data.matches[0]);if(current!==pinGeneration||revision!==residenceVersion)return;questions();pinStatus.textContent=t('State and district filled from the PIN. Check them before continuing.','पिन से राज्य और जिला भर दिए गए हैं। आगे बढ़ने से पहले जाँचें।');}
            else{const regions=(data.postal_regions||[]).map(r=>r.state_name+' · '+r.district_name).join('; ');pinStatus.textContent=(data.reason==='ambiguous'?t('This PIN covers more than one district. Choose your residence below.','इस पिन में एक से अधिक जिले हैं। नीचे अपना निवास चुनें।'):t('A unique current district could not be confirmed. Choose state and district manually.','एकमात्र मौजूदा जिले की पुष्टि नहीं हुई। राज्य और जिला खुद चुनें।'))+(regions?' '+regions:'');}
        }catch(_){if(current===pinGeneration)pinStatus.textContent=t('PIN lookup is unavailable. Choose state and district manually.','पिन की खोज उपलब्ध नहीं है। राज्य और जिला खुद चुनें।');}
        finally{pinRequests--;checkButton.disabled=!hierarchy||pinRequests>0;}
    };
    root.querySelector('[data-pin-lookup]')?.addEventListener('click',lookupPin);
    manualAge?.addEventListener('change',()=>{syncAgeMode();if(manualAge.checked){fields.dob.value='';fields.age.value='';}else fields.age.value=ageFromDate(fields.dob.value)??'';root.querySelector('[data-confirm]').checked=false;results.hidden=true;questions();});
    form.addEventListener('input',event=>{
        if(event.target.dataset.field||event.target.hasAttribute('data-confirm'))profileVersion++;
        if(['state','district'].includes(event.target.dataset.field)){residenceVersion++;pinGeneration++;if(pinStatus)pinStatus.textContent='';}
        if(event.target===fields.dob){if(fields.dob.value&&manualAge)manualAge.checked=false;syncAgeMode();const age=ageFromDate(fields.dob.value);fields.age.value=age===null?'':age;}
        if(event.target===fields.age&&!fields.age.readOnly)fields.dob.value='';
        if(event.target===fields.pincode){++pinGeneration;if(pinStatus)pinStatus.textContent='';if(/^[1-9][0-9]{5}$/.test(fields.pincode.value))lookupPin();}
        if(['age','dob','state','district','pincode'].includes(event.target.dataset.field))root.querySelector('[data-confirm]').checked=false;
        results.hidden=true;questions();
    });
    form.addEventListener('change',event=>{if(event.target.dataset.field||event.target.hasAttribute('data-confirm'))profileVersion++;results.hidden=true;questions();});
    form.addEventListener('place-error',event=>{status.textContent=event.detail;});
    form.addEventListener('submit',event=>{
        event.preventDefault();if(!hierarchy||!form.reportValidity())return;
        if(fields.age.value===''||!Number.isInteger(Number(fields.age.value))||Number(fields.age.value)<0||Number(fields.age.value)>120){status.textContent=t('Check your date of birth, or choose unknown date and enter your completed age.','जन्म तिथि जाँचें, या तिथि पता नहीं चुनकर अपनी पूरी आयु भरें।');return;}
        const p=profile();results.replaceChildren(el('h2',t('Schemes worth checking','जाँचने योग्य योजनाएँ')));
        results.append(el('p',t('These are preliminary matches, not approval. The department or bank will check all current rules and documents.','ये शुरुआती मिलान हैं, स्वीकृति नहीं। विभाग या बैंक सभी मौजूदा नियम और दस्तावेज़ जाँचेगा।')));
        const outside=el('details'),outsideSummary=el('summary',t('Other programmes · reasons and availability','अन्य योजनाएँ · कारण और उपलब्धता'));outside.append(outsideSummary);
        let shown=0;
        for(const scheme of catalogue.schemes.filter(s=>p.interest==='all'||s.category===p.interest)){
            const match=checkScheme(scheme,p),card=el('article',undefined,'scheme-card');
            const badges={not_matched:t('Does not match these answers','इन उत्तरों से मिलान नहीं'),needs_information:t('More information needed','और जानकारी चाहिए'),needs_verification:t('Official rules need checking','आधिकारिक नियमों की जाँच चाहिए'),unavailable:t('Not currently available','फिलहाल उपलब्ध नहीं'),potential:t('Worth checking with the department','विभाग से जाँचने योग्य')};
            card.append(el('h3',scheme.name[lang]),el('span',badges[match.status],'scheme-badge'+(match.status==='potential'?'':' needs')));
            card.append(el('p',t('Coverage: ','क्षेत्र: ')+(scheme.scope_name?.[lang]||(scheme.scope==='IN'?t('India','भारत'):scheme.scope)),'place-note'));
            const benefit=el('div',undefined,'scheme-benefit');benefit.append(el('h4',t('What it offers','क्या सहायता मिलती है')),el('p',scheme.benefit[lang]));card.append(benefit);
            if(match.status==='unavailable')card.append(el('p',t('The record is closed, inactive or outside its published dates. It is not offered as a current match.','रिकॉर्ड बंद, निष्क्रिय या प्रकाशित अवधि से बाहर है। इसे मौजूदा मिलान के रूप में नहीं दिखाया जाता।')));
            if(match.status==='needs_verification')card.append(el('p',t('We cannot verify a match from the available rules or dates. Check the official guidance.','उपलब्ध नियमों या तारीखों से मिलान की पुष्टि नहीं हो सकी। आधिकारिक जानकारी देखें।')));
            if(match.missing.length){card.append(el('h4',t('Still needed','अभी चाहिए')));const list=el('ul');match.missing.forEach(key=>list.append(el('li',fieldName(key))));card.append(list);}
            const explanation=el('details');explanation.append(el('summary',t('Why this result?','यह परिणाम क्यों?')));
            const list=el('ul');
            for(const rule of match.failed)list.append(el('li',`${t('Not matched','मिलान नहीं')}: ${describe(rule,scheme)}`));
            for(const rule of match.passed)list.append(el('li',`${t('Matches your answer','आपके उत्तर से मिलान')}: ${describe(rule,scheme)}`));
            explanation.append(list);const documents=el('div',undefined,'scheme-documents');documents.append(el('h4',t('Documents to prepare','तैयार रखने वाले दस्तावेज़')),el('p',scheme.documents[lang],'scheme-help'));card.append(documents);
            if(scheme.application_note)explanation.append(el('p',scheme.application_note[lang],'scheme-help'));
            if(match.passed.length&&match.status==='potential'){const reason=el('p',t('Based on your answers: ','आपके उत्तरों के आधार पर: ')+match.passed.slice(0,2).map(rule=>describe(rule,scheme)).join(' · '),'scheme-reason');card.append(reason);}
            card.append(explanation);
            card.append(link(t('Official guidance & how to apply','आधिकारिक जानकारी और आवेदन का तरीका'),scheme.apply),el('small',`${t('Rules checked','नियम जाँचे गए')}: ${scheme.checked_on||catalogue.checked_on||t('Unavailable','उपलब्ध नहीं')}`,'place-note'));
            if(scheme.source_updated_at)card.append(el('small',t('Source updated: ','स्रोत अपडेट: ')+scheme.source_updated_at,'place-note'));
            if(scheme.valid_until)card.append(el('small',t('Valid until: ','इस तारीख तक मान्य: ')+scheme.valid_until,'place-note'));
            if(['not_matched','unavailable'].includes(match.status))outside.append(card);else{results.append(card);shown++;}
        }
        if(!shown)results.append(el('p',t('No programme in this small catalogue matches these answers yet. Review the reasons below or explore government myScheme.','इस छोटी सूची की कोई योजना अभी इन उत्तरों से नहीं मिलती। नीचे कारण देखें या सरकारी myScheme पर खोजें।')));
        if(outside.children.length>1)results.append(outside);
        results.append(link(t('Explore more on myScheme','myScheme पर और खोजें'),'https://www.myscheme.gov.in/'));
        results.hidden=false;status.textContent=t('Results are ready below. Your profile was not submitted.','परिणाम नीचे तैयार हैं। आपकी प्रोफ़ाइल जमा नहीं की गई।');
        results.tabIndex=-1;results.focus({preventScroll:true});results.scrollIntoView({block:'start',behavior:'auto'});
    });
    root.querySelector('[data-read]').addEventListener('click',async()=>{
        const photo=root.querySelector('[data-photo]'),consent=root.querySelector('[data-ocr-consent]'),button=root.querySelector('[data-read]');
        if(!consent.checked){ocrStatus.textContent=t('Please agree to temporary photo processing, or fill the details manually.','कृपया फोटो को अस्थायी रूप से पढ़ने की सहमति दें या जानकारी खुद भरें।');return;}
        if(!photo.files[0]||photo.files[0].size>5*1024*1024){ocrStatus.textContent=t('Choose a JPG, PNG or WebP photo up to 5 MB.','अधिकतम 5 MB की JPG, PNG या WebP फोटो चुनें।');return;}
        if(!hierarchy){ocrStatus.textContent=t('Wait for the place list to load, then retry.','क्षेत्र सूची लोड होने दें, फिर कोशिश करें।');return;}
        const current=++generation,revision=profileVersion;upload=new AbortController();button.disabled=true;
        const body=new FormData();body.append('photo',photo.files[0]);body.append('consent','1');
        ocrStatus.textContent=t('Reading the photo… You can edit every suggested field.','फोटो पढ़ रहे हैं… सुझाई गई हर जानकारी बदल सकते हैं।');
        try{
            const response=await fetch(root.dataset.autofill,{method:'POST',body,signal:upload.signal,headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}});
            if(!response.ok)throw new Error();
            const data=await response.json();if(current!==generation)return;
            if(revision!==profileVersion){ocrStatus.textContent=t('You edited your details while the photo was being read. Your edits have been kept. Read the photo again if needed.','फोटो पढ़े जाने के दौरान आपने जानकारी बदली। आपके बदलाव सुरक्षित रखे गए हैं। ज़रूरत हो तो फोटो फिर पढ़ें।');return;}
            ++residenceVersion;++pinGeneration;
            for(const key of ['name','dob','pincode'])if(data.fields[key])fields[key].value=data.fields[key];
            if(data.fields.dob){if(manualAge)manualAge.checked=false;syncAgeMode();fields.age.value=ageFromDate(data.fields.dob)??'';}
            if(data.fields.state)await hierarchy.set(data.fields);
            if(current!==generation)return;
            root.querySelector('[data-confirm]').checked=false;results.hidden=true;questions();if(data.fields.pincode)lookupPin();
            ocrStatus.textContent=Object.keys(data.fields).length?t('Suggested details filled. Check and correct them before continuing. This does not verify identity.','सुझाई जानकारी भर दी गई है। आगे बढ़ने से पहले जाँचें और सुधारें। इससे पहचान सत्यापित नहीं होती।'):t('No clear details were read. Please fill the fields manually.','स्पष्ट जानकारी नहीं पढ़ी जा सकी। कृपया जानकारी खुद भरें।');
            if(data.fields.birth_year)ocrStatus.textContent+=` ${t('Only birth year was found; enter your date of birth or choose the unknown-date option.','केवल जन्म वर्ष मिला; जन्म तिथि भरें या तिथि पता नहीं वाला विकल्प चुनें।')}`;
        }catch(error){if(error.name!=='AbortError')ocrStatus.textContent=t('The photo could not be read. Use a clearer image or fill the details manually.','फोटो नहीं पढ़ी जा सकी। साफ फोटो दें या जानकारी खुद भरें।');}
        finally{photo.value='';consent.checked=false;button.disabled=false;}
    });
    root.querySelector('[data-clear]').addEventListener('click',async()=>{
        ++generation;++pinGeneration;upload?.abort();form.reset();if(manualAge)manualAge.checked=false;syncAgeMode();if(pinStatus)pinStatus.textContent='';Object.values(fields).forEach(field=>{if(field.dataset.field!=='interest')field.value='';});
        if(hierarchy)await hierarchy.set({});results.replaceChildren();results.hidden=true;ocrStatus.textContent='';status.textContent=t('Your details have been cleared.','आपकी जानकारी हटा दी गई है।');questions();
    });
    questions();
    const checkButton=root.querySelector('[data-check]');checkButton.disabled=true;
    placeHierarchy(form,root.dataset.options,lang,JSON.parse(root.dataset.initial)).then(value=>{hierarchy=value;checkButton.disabled=false;status.replaceChildren();questions();if(/^[1-9][0-9]{5}$/.test(fields.pincode.value))lookupPin();}).catch(error=>{status.textContent=error.message+' '+t('Reload the page to retry the place list.','क्षेत्र सूची के लिए पेज फिर लोड करें।');});
}

if(!document.querySelector('[data-place-hub]'))mountSchemeFinder(document.querySelector('#scheme-finder'));
