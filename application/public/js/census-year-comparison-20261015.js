export function yearPlayback(year, metric) {
 const surface=document.getElementById('india-map')||document.getElementById('district-polygons');
 if(!surface)return;
 const svg=surface.tagName.toLowerCase()==='svg',parent=svg?surface.parentElement:surface;parent.style.position='relative';
 const plane=document.createElement('div');plane.id='census-year-comparison';plane.style.cssText='position:absolute;inset:0;z-index:2;pointer-events:none;overflow:hidden';
 const divider=document.createElement('div');divider.style.cssText='position:absolute;top:0;bottom:0;left:50%;border-left:2px solid #222;filter:drop-shadow(0 0 1px white)';plane.append(divider);
 const controls=document.createElement('div');controls.style.cssText='position:absolute;bottom:32px;left:50%;transform:translateX(-50%);display:flex;z-index:5;pointer-events:auto;border-radius:5px;box-shadow:0 2px 8px #0003;overflow:hidden';
 const buttons={};for(const value of ['2001','2011']){const button=document.createElement('button');button.type='button';button.textContent=value;button.setAttribute('aria-label',`Reveal Census ${value}`);button.style.cssText='border:0;border-right:1px solid #fff;background:#dce4ec;color:#111;padding:9px 14px;font:700 18px system-ui;cursor:pointer';controls.append(button);buttons[value]=button;}
 const slider=document.createElement('input');slider.type='range';slider.min='0';slider.max='100';slider.value='50';slider.setAttribute('aria-label','Compare 2001 on the left with 2011 on the right');slider.style.cssText='position:absolute;left:10%;bottom:8px;height:18px;min-height:0;padding:0;width:80%;z-index:5;pointer-events:auto;accent-color:#333';
 const notice=document.createElement('span');notice.setAttribute('role','status');notice.style.cssText='position:absolute;left:12px;top:12px;background:#fff;padding:7px 10px;border-radius:5px;color:#173d37;font:600 13px system-ui;z-index:5';notice.textContent='Preparing 2001 / 2011 comparison…';
 parent.append(plane,controls,slider,notice);
 let left=null,right=null,position=50,automatic=false,playing=true,busy=false,version=0,frame=null,timer=null,movement=0;
 const supported=()=>['literacy','population','households'].includes(metric.value);
 const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
 const loading=()=>/loading/i.test((document.getElementById('map-status')||document.getElementById('polygon-status'))?.textContent||'')||/loading/i.test(document.getElementById('metric-legend')?.textContent||'');
 const showPosition=value=>{position=value;slider.value=String(Math.round(value));if(left)left.style.clipPath=`inset(0 ${100-value}% 0 0)`;divider.style.left=`${value}%`;for(const value of ['2001','2011'])buttons[value].setAttribute('aria-pressed',String(position===(value==='2001'?100:0)));};
 const stop=()=>{playing=false;clearTimeout(timer);cancelAnimationFrame(frame);movement++;};
 const clear=()=>{stop();version++;left?.remove();right?.remove();left=right=null;plane.hidden=true;slider.hidden=true;notice.hidden=true;};
 const capture=()=>svg?Promise.resolve(surface.cloneNode(true)):new Promise(resolve=>{let done=false;const finish=value=>{if(!done){done=true;resolve(value);}};window.dispatchEvent(new CustomEvent('census-map-capture',{detail:{resolve:finish}}));setTimeout(()=>finish(null),2000);});
 const ready=async()=>{const end=Date.now()+60000;await wait(300);while(loading()&&Date.now()<end)await wait(250);return !loading();};
 const setYear=async value=>{automatic=true;year.value=value;year.dispatchEvent(new Event('change',{bubbles:true}));automatic=false;return ready();};
 const move=async(to,duration=2600)=>{const token=++movement,from=position,start=performance.now();return new Promise(resolve=>{const tick=now=>{if(token!==movement){resolve();return;}const t=Math.min(1,(now-start)/duration),ease=t*t*(3-2*t);showPosition(from+(to-from)*ease);if(t<1)frame=requestAnimationFrame(tick);else resolve();};frame=requestAnimationFrame(tick);});};
 const cycle=async()=>{if(!playing||!left||!right)return;if(document.hidden){timer=setTimeout(cycle,1000);return;}await move(position>=50?0:100,4200);if(playing)timer=setTimeout(cycle,2500);};
 const prepare=async()=>{
  if(busy||!supported())return false;busy=true;const token=++version,initial=year.value;plane.hidden=true;slider.hidden=true;notice.hidden=false;notice.textContent='Preparing 2001 / 2011 comparison…';for(const button of Object.values(buttons))button.disabled=true;
  try{
   if(!await ready())throw Error('Map values are still loading.');
   const images={};
   for(const value of [initial,initial==='2001'?'2011':'2001']){if(version!==token)return false;if(year.value!==value&&!await setYear(value))throw Error('Year values are unavailable.');images[value]=await capture();if(!images[value])throw Error('Map comparison is temporarily unavailable.');}
   if(version!==token)return false;
   if(year.value!==initial)await setYear(initial);
   right=images['2011'];left=images['2001'];for(const image of [right,left]){image.removeAttribute('id');image.setAttribute('aria-hidden','true');image.style.cssText='position:absolute;inset:0;width:100%;height:100%;pointer-events:none;margin:0';plane.prepend(image);}plane.append(left,divider);plane.hidden=false;slider.hidden=false;showPosition(50);notice.textContent='2001 ← drag to compare → 2011';return true;
  }catch(error){notice.textContent=error.message;playing=false;return false;}
  finally{busy=false;for(const button of Object.values(buttons))button.disabled=false;}
 };
 for(const value of ['2001','2011'])buttons[value].addEventListener('click',async()=>{stop();if(!left||!right){if(!await prepare())return;}notice.textContent=`Census ${value} · click the map to explore`;await move(value==='2001'?100:0);await setYear(value);});
 slider.addEventListener('input',()=>{stop();showPosition(Number(slider.value));});
 const interact=event=>{if(!event.isTrusted||controls.contains(event.target)||event.target===slider)return;clear();};
 for(const type of ['pointerdown','wheel','keydown','touchstart'])document.addEventListener(type,interact,{capture:true,passive:true});
 document.addEventListener('change',event=>{if(!automatic&&event.target!==slider)clear();},true);
 metric.addEventListener('change',()=>{clear();controls.hidden=!supported();});year.addEventListener('change',()=>{if(!automatic)clear();});
 window.addEventListener('resize',clear);window.addEventListener('pagehide',clear);
 controls.hidden=!supported();timer=setTimeout(async()=>{if(playing&&await prepare()&&playing)timer=setTimeout(cycle,2000);},1500);
}
