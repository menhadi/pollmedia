export function yearPlayback(year, metric) {
 const box=document.createElement('div');box.id='census-year-playback';box.style.cssText='flex-basis:100%;width:100%;display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0';
 const button=document.createElement('button');button.type='button';button.textContent='▶ Play years';button.setAttribute('aria-label','Play Census years');button.setAttribute('aria-pressed','false');button.style.cssText='padding:10px 16px;border:0;border-radius:7px;background:#173d37;color:white;font:600 14px system-ui;cursor:pointer';
 const status=document.createElement('span');status.setAttribute('role','status');status.style.cssText='font:600 14px system-ui';
 const help=document.createElement('small');help.textContent='2001 ↔ 2011 · Plays automatically; interact to pause. Current boundaries; linked coverage varies by year.';
 box.append(button,status,help);document.getElementById('census-year-note').after(box);
 let timer=null,playing=false,automatic=false;
 const surface=document.getElementById('india-map')||document.getElementById('district-polygons');
 const badge=document.createElement('span');badge.id='map-playing-year';badge.style.cssText='position:absolute;left:14px;bottom:36px;z-index:3;padding:8px 12px;border-radius:8px;background:#fff;color:#173d37;box-shadow:0 2px 8px #0003;font:700 22px system-ui;pointer-events:none';
 if(surface){const parent=surface.tagName.toLowerCase()==='svg'?surface.parentElement:surface;parent.style.position='relative';parent.append(badge);}
 const style=document.createElement('style');style.textContent='#district-polygons path{transition:fill 1.2s ease}';document.head.append(style);

 const supported=()=>['literacy','population','households'].includes(metric.value);
 const update=()=>{button.disabled=!supported();button.style.opacity=button.disabled?'.5':'1';status.textContent=`Census ${year.value}`;badge.textContent=year.value;badge.setAttribute('aria-label',`Map showing Census ${year.value}`);help.textContent=supported()?'2001 ↔ 2011 · Plays automatically; interact to pause. Current boundaries; linked coverage varies by year.':'Year animation is available for literacy, population and households.';};
 const pause=()=>{clearTimeout(timer);timer=null;playing=false;button.textContent='▶ Play years';button.setAttribute('aria-label','Play Census years');button.setAttribute('aria-pressed','false');update();};
 const advance=()=>{if(!playing)return;if(document.hidden){pause();return;}const loading=/loading/i.test((document.getElementById('map-status')||document.getElementById('polygon-status'))?.textContent||'');if(!loading){if(surface)surface.animate([{opacity:1},{opacity:.35},{opacity:1}],{duration:1600,easing:'ease-in-out'});automatic=true;year.value=year.value==='2001'?'2011':'2001';year.dispatchEvent(new Event('change',{bubbles:true}));automatic=false;update();}timer=setTimeout(advance,5000);};
 const play=()=>{if(!supported())return;playing=true;button.textContent='❚❚ Pause';button.setAttribute('aria-label','Pause Census years');button.setAttribute('aria-pressed','true');clearTimeout(timer);timer=setTimeout(advance,5000);update();};
 button.addEventListener('click',()=>{if(playing)pause();else play();});
 const interact=event=>{if(event.isTrusted&&!button.contains(event.target))pause();};
 document.addEventListener('change',()=>{if(!automatic)pause();},true);
 for(const type of ['pointerdown','wheel','keydown','touchstart'])document.addEventListener(type,interact,{capture:true,passive:true});
 year.addEventListener('change',()=>{if(!automatic)pause();update();});metric.addEventListener('change',pause);
 document.addEventListener('visibilitychange',()=>{if(document.hidden)pause();});window.addEventListener('pagehide',pause);update();play();
}
