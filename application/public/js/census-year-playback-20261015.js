export function yearPlayback(year, metric) {
 const box=document.createElement('div');box.id='census-year-playback';box.style.cssText='display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0';
 const button=document.createElement('button');button.type='button';button.textContent='▶ Play years';button.setAttribute('aria-label','Play Census years');button.setAttribute('aria-pressed','false');button.style.cssText='padding:10px 16px;border:0;border-radius:7px;background:#173d37;color:white;font:600 14px system-ui;cursor:pointer';
 const status=document.createElement('span');status.setAttribute('role','status');status.style.cssText='font:600 14px system-ui';
 const help=document.createElement('small');help.textContent='2001 ↔ 2011 · Changes every 5 seconds. Current boundaries; linked coverage varies by year.';
 box.append(button,status,help);document.getElementById('census-year-note').after(box);
 let timer=null,playing=false,automatic=false;
 const supported=()=>['literacy','population','households'].includes(metric.value);
 const update=()=>{button.disabled=!supported();button.style.opacity=button.disabled?'.5':'1';status.textContent=`Census ${year.value}`;help.textContent=supported()?'2001 ↔ 2011 · Changes every 5 seconds. Current boundaries; linked coverage varies by year.':'Year animation is available for literacy, population and households.';};
 const pause=()=>{clearTimeout(timer);timer=null;playing=false;button.textContent='▶ Play years';button.setAttribute('aria-label','Play Census years');button.setAttribute('aria-pressed','false');update();};
 const advance=()=>{if(!playing)return;if(document.hidden){pause();return;}const loading=/loading/i.test(document.getElementById('map-status')?.textContent||'');if(!loading){automatic=true;year.value=year.value==='2001'?'2011':'2001';year.dispatchEvent(new Event('change',{bubbles:true}));automatic=false;update();}timer=setTimeout(advance,5000);};
 button.addEventListener('click',()=>{if(playing){pause();return;}if(!supported())return;playing=true;button.textContent='❚❚ Pause';button.setAttribute('aria-label','Pause Census years');button.setAttribute('aria-pressed','true');advance();});
 year.addEventListener('change',()=>{if(!automatic)pause();update();});metric.addEventListener('change',pause);
 document.addEventListener('visibilitychange',()=>{if(document.hidden)pause();});window.addEventListener('pagehide',pause);update();
}
