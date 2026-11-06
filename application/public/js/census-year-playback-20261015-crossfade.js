export function yearPlayback(year, metric) {
 const surface=document.getElementById('india-map')||document.getElementById('district-polygons');
 if(!surface)return;
 const isSvg=surface.tagName.toLowerCase()==='svg',parent=isSvg?surface.parentElement:surface;
 parent.style.position='relative';
 const badge=document.createElement('span');badge.id='map-playing-year';badge.setAttribute('role','status');badge.style.cssText='position:absolute;left:14px;top:14px;z-index:4;padding:8px 14px;border-radius:8px;background:#fff;color:#173d37;box-shadow:0 2px 8px #0003;font:700 24px system-ui;pointer-events:none';parent.append(badge);
 const style=document.createElement('style');style.textContent='.polygon-tools{top:66px}';document.head.append(style);
 let timer=null,playing=true,automatic=false,overlay=null,busy=false;
 const supported=()=>['literacy','population','households'].includes(metric.value);
 const update=()=>{badge.textContent=year.value;badge.setAttribute('aria-label',`Census ${year.value}${playing?' · animating':' · paused'}`);badge.hidden=!supported();};
 const clearOverlay=()=>{overlay?.remove();overlay=null;};
 const pause=()=>{playing=false;clearTimeout(timer);clearOverlay();update();};
 const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
 const loading=()=>/loading/i.test((document.getElementById('map-status')||document.getElementById('polygon-status'))?.textContent||'');
 const snapshot=async()=>{
  if(isSvg){const copy=surface.cloneNode(true);copy.removeAttribute('id');copy.setAttribute('aria-hidden','true');return copy;}
  return new Promise(resolve=>{let settled=false;const done=image=>{if(!settled){settled=true;resolve(image);}};window.dispatchEvent(new CustomEvent('census-map-capture',{detail:{resolve:done}}));setTimeout(()=>done(null),2000);});
 };
 const advance=async()=>{
  if(!playing||!supported()||busy)return;
  if(document.hidden){timer=setTimeout(advance,1000);return;}
  if(loading()){timer=setTimeout(advance,1000);return;}
  busy=true;
  try{
   const previous=await snapshot();if(!playing)return;
   if(previous){overlay=previous;overlay.style.cssText='position:absolute;inset:0;width:100%;height:100%;z-index:2;pointer-events:none;margin:0';parent.append(overlay);}
   automatic=true;year.value=year.value==='2001'?'2011':'2001';year.dispatchEvent(new Event('change',{bubbles:true}));automatic=false;
   badge.textContent=`${year.value}…`;
   await wait(250);
   const deadline=Date.now()+60000;
   while(playing&&loading()&&Date.now()<deadline)await wait(250);
   if(loading()){pause();return;}
   update();
   if(overlay&&playing){await overlay.animate([{opacity:1},{opacity:0}],{duration:1800,easing:'ease-in-out',fill:'forwards'}).finished.catch(()=>{});}
  }finally{automatic=false;clearOverlay();busy=false;if(playing)timer=setTimeout(advance,5000);}
 };
 const interact=event=>{if(event.isTrusted)pause();};
 for(const type of ['pointerdown','wheel','keydown','touchstart'])document.addEventListener(type,interact,{capture:true,passive:true});
 document.addEventListener('change',()=>{if(!automatic)pause();},true);
 year.addEventListener('change',()=>{if(!automatic)pause();update();});metric.addEventListener('change',pause);
 window.addEventListener('pagehide',pause);update();timer=setTimeout(advance,5000);
}
