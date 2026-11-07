(() => {
 const summary=Boolean(document.querySelector('[data-district-summary],[data-district-source-summary],[data-village-summary],[data-gp-summary]'));
 const localeRoot=summary?(document.querySelector('.public-header')||document.body):document.body;
 if(!document.documentElement.lang.startsWith('hi')&&!/^\/issues(?:\/|$)/.test(location.pathname)){
  const replace=value=>value.includes('Subdistrict/Taluka/Tehsil')?value:value.replace(/\bSubdistricts?\b(?:\s*\/\s*(?:Talukas?\s*\/\s*)?Tehsils?)?/gi,'Subdistrict/Taluka/Tehsil');
  const labels='label,option,summary,h1,h2,h3,caption,thead,nav a,.place-type,.eyebrow,.kicker,.approved-context-links';
  const apply=root=>{
   if(root.nodeType===Node.TEXT_NODE){
    if(!/\bsubdistrict/i.test(root.textContent))return;
    const parent=root.parentElement;if(!parent?.closest(labels)||parent.closest('script,style,textarea,code,pre,[contenteditable],.issue-post,.hub-issue,[data-hub-feed]'))return;
    const value=replace(root.textContent);if(value!==root.textContent)root.textContent=value;return;
   }
   if(root.nodeType!==Node.ELEMENT_NODE)return;
   const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);while(walker.nextNode())apply(walker.currentNode);
   for(const element of [root,...root.querySelectorAll('[aria-label],[placeholder]')])for(const attr of ['aria-label','placeholder']){const value=element.getAttribute(attr);if(value&&replace(value)!==value)element.setAttribute(attr,replace(value));}
  };
  if(!summary)apply(localeRoot);
  new MutationObserver(records=>{for(const record of records){if(record.type==='characterData')apply(record.target);else for(const node of record.addedNodes)apply(node);}}).observe(localeRoot,{childList:true,subtree:true,characterData:true});
 }
 const header=document.querySelector('.public-header'),toggle=header?.querySelector('.public-menu-toggle'),nav=header?.querySelector('#public-navigation');
 if(toggle&&nav){
  const mobile=matchMedia('(max-width:1100px)');
  const setOpen=open=>{nav.hidden=mobile.matches&&!open;toggle.setAttribute('aria-expanded',String(open));};
  const layout=()=>setOpen(!mobile.matches);header.dataset.menuReady='true';layout();mobile.addEventListener('change',layout);
  toggle.addEventListener('click',()=>setOpen(nav.hidden));
  header.addEventListener('keydown',event=>{if(event.key==='Escape'&&mobile.matches){setOpen(false);toggle.focus();}});
 }
 const source=document.getElementById('public-hindi-dictionary');if(!source)return;
 const dictionary=JSON.parse(source.textContent),keys=Object.keys(dictionary).filter(key=>key&&typeof dictionary[key]==='string'&&dictionary[key].trim()).sort((a,b)=>b.length-a.length);
 const escape=value=>value.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
 const pattern=new RegExp('(?<![\\p{L}\\p{N}_-])(?:'+keys.map(escape).join('|')+')(?![\\p{L}\\p{N}_-])','gu');
 const address=text=>/^(?:https?:\/\/|www\.)\S+$|^[^\s@]+@[^\s@]+\.[^\s@]+$/i.test(text.trim());
 const translate=text=>{if(address(text))return text;const normalized=text.trim().replace(/\s+/gu,' ');if(Object.hasOwn(dictionary,normalized)&&typeof dictionary[normalized]==='string')return text.replace(/\S(?:[\s\S]*\S)?/u,()=>dictionary[normalized]);return text.replace(pattern,match=>dictionary[match]);};

 const nameCache=new Map(),pending=new Map(),attempts=new Map();let lookupTimer=null,active=0,blockedUntil=0;
 const endpoint=source.dataset.placeNamesUrl;
 const queue=(raw,apply)=>{
  if(!endpoint||address(raw)||raw.trim().length<2||raw.length>255||!/[a-z]/i.test(raw))return;
  if(nameCache.has(raw)){apply(nameCache.get(raw));return;}
  if(!pending.has(raw))pending.set(raw,[]);pending.get(raw).push(apply);
  if(!lookupTimer)lookupTimer=setTimeout(flush,80);
 };
 const flush=async()=>{
  lookupTimer=null;if(Date.now()<blockedUntil){lookupTimer=setTimeout(flush,blockedUntil-Date.now());return;}if(active>=2){lookupTimer=setTimeout(flush,100);return;}
  const names=[];let bytes=0;
  for(const name of pending.keys()){const length=encodeURIComponent(name).length;if(names.length&&(names.length>=60||bytes+length>4500))break;names.push(name);bytes+=length+8;}
  if(!names.length)return;
  const callbacks=names.map(name=>{const list=pending.get(name);pending.delete(name);return list;});active++;
  try{
   const url=new URL(endpoint,location.origin);url.searchParams.set('names',JSON.stringify(names));
   const response=await fetch(url,{credentials:'same-origin'});if(!response.ok){const error=Error('Hindi name lookup unavailable');error.delay=response.status===429?Math.max(1000,Number(response.headers?.get('Retry-After')||60)*1000):1000;throw error;}
   const data=await response.json();
   names.forEach((name,index)=>{const text=Object.hasOwn(data.names||{},name)?data.names[name]:name;nameCache.set(name,text);for(const apply of callbacks[index])apply(text);});
  }catch(error){
   names.forEach((name,index)=>{const count=(attempts.get(name)||0)+1;attempts.set(name,count);if(count<=3)pending.set(name,[...callbacks[index],...(pending.get(name)||[])]);});
   blockedUntil=Date.now()+(error.delay||1000);console.warn('Hindi place names: retrying temporary lookup failure');
  }
  finally{active--;if(pending.size&&!lookupTimer)lookupTimer=setTimeout(flush,30);}
 };

 const ambiguousWords=text=>/\b(?:male|female|total|saline|name|soil|ha|min|max|mm|km|ph|ec|np|npp|ct|og)\b/i.test(text);
 const ambiguous=text=>/^(?:male|female|total|saline|name|soil|ha|min|max|mm|km|ph|ec|np|npp|ct|og)$/i.test(text.trim());
 const placeNode=node=>{
  const parent=node.parentElement;if(!parent)return false;
  const select=parent.closest('select');if(select&&/state|district|village|place/i.test(select.id+' '+select.name))return true;
  if(parent.closest('h1,[data-place-name],.map-tooltip strong,#polygon-tooltip strong,#place-details h2,#place-details h3,.maplibregl-popup-content strong'))return true;
  const link=parent.closest('a');return !!link&&/\/(?:india\/(?:districts|villages?|census\/places|state)|data\/census\/records)\//.test(link.getAttribute('href')||'');
 };
 const textNode=node=>{
  if(skip(node.parentElement))return;
  const option=node.parentElement?.closest('option');if(option&&!option.hasAttribute('value'))option.setAttribute('value',option.text??option.textContent.trim().replace(/\s+/g,' '));
  const raw=node.nodeValue,place=placeNode(node),text=place&&ambiguous(raw)?raw:translate(raw);if(text!==raw)node.nodeValue=text;
  if((place||!Object.hasOwn(dictionary,raw))&&(place||!ambiguousWords(raw)))queue(raw,value=>{if(node.isConnected&&node.nodeValue===text&&value!==raw&&value!==text)node.nodeValue=value;});
 };
 const skip=element=>element?.closest('script,style,textarea,code,pre,#colour-metric,#polygon-metric,[translate="no"],[contenteditable]');
 const localize=root=>{
  if(root.nodeType===Node.TEXT_NODE){textNode(root);return;}
  if(root.nodeType!==Node.ELEMENT_NODE||skip(root))return;
  const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);let node;while(node=walker.nextNode())textNode(node);
  for(const element of [root,...root.querySelectorAll('[placeholder],[aria-label],[title],[alt]')])if(!skip(element))for(const name of ['placeholder','aria-label','title','alt'])if(element.hasAttribute(name)){const value=element.getAttribute(name),text=translate(value);if(text!==value)element.setAttribute(name,text);if(!Object.hasOwn(dictionary,value)&&!ambiguousWords(value))queue(value,result=>{if(element.isConnected&&element.getAttribute(name)===text&&result!==value&&result!==text)element.setAttribute(name,result);});}
 };
 if(!summary)localize(localeRoot);
 const note=document.createElement('p');note.className='hindi-place-name-note';note.textContent='स्थान नाम उपलब्ध मूल देवनागरी वर्तनी या स्वचालित हिन्दी लिप्यंतरण से दिखाए गए हैं। लिप्यंतरण आधिकारिक नाम-संशोधन नहीं है; मूल नाम, स्रोत कोड और पहचान सुरक्षित हैं। संपादित हिन्दी वर्तनी को प्राथमिकता मिलती है।';
 const credit=document.createElement('a');credit.href='https://huggingface.co/datasets/ai4bharat/Aksharantar';credit.target='_blank';credit.rel='noopener';credit.textContent=' AI4Bharat IndicXlit / Aksharantar · Madhani और सहयोगी (2022)';note.append(credit);
 const container=document.querySelector('#coverage,.district-map-disclaimer,#sources');
 if(container)container.append(note);else{const details=document.createElement('details'),summary=document.createElement('summary');summary.textContent='भाषा और नामों की जानकारी';details.append(summary,note);document.querySelector('main')?.append(details);}

 new MutationObserver(records=>{for(const record of records){if(record.type==='characterData')localize(record.target);else if(record.type==='attributes')localize(record.target);else for(const node of record.addedNodes)localize(node);}}).observe(localeRoot,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['placeholder','aria-label','title','alt']});
})();
