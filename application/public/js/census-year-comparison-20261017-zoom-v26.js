export function yearPlayback(year, metric) {
 const surface=document.getElementById('india-map')||document.getElementById('district-polygons');
 if(!surface)return;
 const home=document.body.classList.contains('home-dashboard');let delhiPercent=41;
 const isIndia=()=>!document.querySelector('[data-polygon-map]')&&!document.querySelector('#state-filter')?.value&&!document.querySelector('[data-scope]');
 const svg=surface.tagName.toLowerCase()==='svg',parent=svg?surface.parentElement:surface;parent.style.position='relative';
 if(svg){surface.style.position='relative';surface.style.zIndex='0';}
 const plane=document.createElement('div');plane.id='census-year-comparison';plane.style.cssText='position:absolute;inset:0;z-index:4;pointer-events:none;overflow:hidden';
 const divider=document.createElement('div');divider.style.cssText='position:absolute;top:0;bottom:0;left:50%;width:2px;background:#185c6c;z-index:4;border-left:2px solid #185c6c;filter:drop-shadow(0 0 1px white)';plane.append(divider);
 const controls=document.createElement('div');controls.style.cssText='position:absolute;bottom:24px;left:50%;transform:translateX(-50%);display:flex;z-index:5;pointer-events:auto;border-radius:5px;box-shadow:0 2px 8px #0003;overflow:hidden';
 const buttons={};for(const value of ['2001','2011']){const button=document.createElement('button');button.type='button';button.textContent=value;button.setAttribute('aria-label',`Reveal Census ${value}`);button.style.cssText='border:0;border-right:1px solid #fff;background:#dce4ec;color:#111;padding:9px 14px;font:700 18px system-ui;cursor:pointer';controls.append(button);buttons[value]=button;}
 const slider=document.createElement('input');slider.type='range';slider.min='0';slider.max='100';slider.value='50';slider.setAttribute('aria-label','Compare 2001 on the left with 2011 on the right');slider.style.cssText='position:absolute;left:10%;bottom:8px;height:18px;min-height:0;padding:0;width:80%;z-index:5;pointer-events:auto;accent-color:#333';
 const notice=document.createElement('span');notice.setAttribute('role','status');notice.style.cssText='position:absolute;left:12px;top:12px;background:#fff;padding:7px 10px;border-radius:5px;color:#173d37;font:600 13px system-ui;z-index:5';notice.textContent='Preparing 2001 / 2011 comparison…';
 slider.style.display='none';
 const handle=document.createElement('button');handle.type='button';handle.setAttribute('aria-label','Drag divider to compare Census years');handle.textContent='↔';handle.style.cssText='position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:44px;height:44px;padding:0;border:2px solid #185c6c;border-radius:50%;background:white;color:#185c6c;font:700 26px system-ui;z-index:6;cursor:ew-resize;touch-action:none;box-shadow:0 2px 9px #0005';
 const labels={};for(const value of ['2001','2011']){const label=document.createElement('span');label.style.cssText='position:absolute;top:16px;z-index:5;background:#fffE;padding:7px 10px;border-radius:5px;font:700 14px system-ui;color:#173d37;pointer-events:none';label.style.zIndex='7';label.style.maxWidth='42%';label.style.boxSizing='border-box';label.style.fontSize='12px';if(svg)label.style.top='56px';labels[value]=label;parent.append(label);}
 notice.style.top='auto';notice.style.bottom='77px';notice.style.left='50%';notice.style.transform='translateX(-50%)';notice.style.whiteSpace='nowrap';
 const rail=document.createElement('div');rail.id='census-comparison-divider';rail.style.cssText=`position:absolute;left:50%;top:0px;bottom:0;width:44px;transform:translateX(-50%);z-index:6;pointer-events:none`;divider.style.left='50%';divider.style.borderLeft='0';divider.style.transform='translateX(-50%)';handle.style.left='50%';handle.style.pointerEvents='auto';rail.hidden=true;rail.append(divider,handle);controls.style.zIndex=notice.style.zIndex='8';parent.append(plane,controls,slider,notice,rail);if(svg){const tools=parent.querySelector('.polygon-tools');if(tools){tools.style.zIndex='8';}}
 const heading=document.createElement('div');heading.id='census-comparison-heading';heading.style.cssText='display:flex;justify-content:space-between;gap:20px;padding:12px 18px;background:#f7fafc;color:#173d37;border-block:1px solid #dce5e8';for(const label of Object.values(labels)){label.style.cssText='position:static;font:700 13px system-ui;max-width:48%;color:#173d37';heading.append(label);}const footer=document.createElement('div');footer.id='census-comparison-footer';footer.style.cssText='display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:12px;padding:12px 18px;background:#f7fafc;border-block:1px solid #dce5e8';controls.style.position='static';controls.style.transform='none';notice.style.cssText='font:600 13px system-ui;color:#173d37';controls.style.cssText='display:flex;gap:4px;padding:4px;border:1px solid #d4dfe5;border-radius:12px;background:#eaf0f4;box-shadow:0 1px 3px #173d3714;pointer-events:auto';for(const button of Object.values(buttons))button.style.cssText='border:0;border-radius:8px;background:transparent;color:#334155;padding:10px 22px;font:700 15px system-ui;cursor:pointer;transition:background .15s,color .15s';footer.style.cssText='display:grid;grid-template-columns:minmax(0,1fr);justify-items:center;align-items:center;gap:8px;padding:12px 18px;background:#f7fafc;border-block:1px solid #dce5e8';controls.style.gridArea='1 / 1';notice.style.cssText='grid-area:2 / 1;display:block;width:100%;min-height:36px;text-align:center;font:600 13px/18px system-ui;color:#173d37;white-space:normal';heading.style.minHeight='64px';heading.style.boxSizing='border-box';heading.style.alignItems='center';for(const label of Object.values(labels)){label.style.flex='0 0 calc(50% - 10px)';label.style.lineHeight='18px';}labels['2011'].style.textAlign='right';footer.append(controls,notice);parent.before(heading);parent.after(footer);let extent={min:25,max:75};
 const measure=()=>metric.selectedOptions[0]?.textContent?.replace(/^Census 20\d\d\s*[·|]\s*/,'')||'Selected measure';
 const labelYears=()=>{for(const value of ['2001','2011'])labels[value].textContent=`${value} · ${measure()}`;};
 const layout=()=>{};
 if(home){
  parent.append(heading,footer);
  heading.style.cssText+=';position:absolute;inset:10px 10px auto;z-index:7;background:transparent;border:0;padding:0;min-height:0;pointer-events:none';
  footer.style.cssText+=';position:absolute;bottom:12px;left:50%;transform:translateX(-50%);z-index:8;width:max-content;max-width:calc(100% - 24px);padding:8px 10px;box-sizing:border-box;box-shadow:0 2px 8px #0002';
  footer.dataset.interaction='idle';
  heading.replaceChildren(buttons['2001'],buttons['2011']);
  for(const value of ['2001','2011']){
   buttons[value].replaceChildren(labels[value]);
   buttons[value].style.cssText='display:block;pointer-events:auto;background:#fff;color:#185c6c;border:1px solid #b6cbd6;border-top:3px solid #185c6c;border-radius:0;padding:8px 12px;max-width:46%;font:700 13px system-ui;cursor:pointer;box-shadow:0 1px 4px #0002';
   labels[value].style.cssText='display:block;max-width:none;font:inherit;color:inherit;line-height:1.3;pointer-events:none';
  }
  heading.style.justifyContent='space-between';
  controls.hidden=true;

 }

 // Keep one pair of transparent year controls inside every comparison map.
 parent.append(heading,footer);
 heading.replaceChildren(buttons['2001'],buttons['2011']);
 heading.style.cssText='position:absolute;inset:12px 64px auto 12px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;z-index:7;background:transparent;border:0;padding:0;min-height:0;pointer-events:none';
 for(const value of ['2001','2011']){
  buttons[value].replaceChildren(labels[value]);
  buttons[value].style.cssText='display:block;pointer-events:auto;background:transparent;color:#185c6c;border:0;border-bottom:2px solid transparent;border-radius:0;padding:7px 9px;max-width:46%;font:700 14px system-ui;cursor:pointer;box-shadow:none;text-shadow:0 1px 3px #fff,0 0 5px #fff';
  labels[value].style.cssText='display:block;background:transparent;max-width:none;font:inherit;color:inherit;line-height:1.35;pointer-events:none';
 }
 controls.remove();
 footer.style.cssText='position:absolute;bottom:10px;left:50%;transform:translateX(-50%);z-index:8;display:grid;justify-items:center;width:max-content;max-width:calc(100% - 24px);padding:0;border:0;background:transparent;box-shadow:none;pointer-events:none';
 notice.style.cssText='font:600 12px/1.4 system-ui;color:#183444;text-align:center;background:transparent;text-shadow:0 1px 3px #fff,0 0 5px #fff';
 let selection=null;let left=null,right=null,position=50,automatic=false,playing=!home,busy=false,version=0,frame=null,timer=null,movement=0;
 const supported=()=>['literacy','population','households'].includes(metric.value)||/^(vd_|pca_|c11_)/.test(metric.value);
 const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
 const loading=()=> (svg&&surface.dataset.comparisonReady!=='true')||/loading/i.test((document.getElementById('map-status')||document.getElementById('polygon-status'))?.textContent||'')||/loading/i.test(document.getElementById('metric-legend')?.textContent||'');
 const showPosition=value=>{position=value;slider.value=String(Math.round(value));const x=extent.min+(extent.max-extent.min)*value/100;if(left)left.style.clipPath=`inset(0 ${value===0?100:value===100?0:100-x}% 0 0)`;rail.style.left=`${x}%`;handle.setAttribute('aria-valuetext',`${Math.round(value)}% Census 2001`);for(const value of ['2001','2011']){const active=position===(value==='2001'?100:0);buttons[value].setAttribute('aria-pressed',String(active));buttons[value].style.background='transparent';buttons[value].style.color=active?'#185c6c':'#183444';buttons[value].style.borderBottomColor=active?'#185c6c':'transparent';};};
 const stop=()=>{playing=false;clearTimeout(timer);cancelAnimationFrame(frame);movement++;};
 const clear=(preservePlayback=false)=>{const resume=playing;heading.style.display=supported()?"flex":"none";footer.style.display=supported()?"grid":"none";stop();if(preservePlayback)playing=resume;version++;left?.remove();right?.remove();left=right=null;plane.hidden=true;slider.hidden=true;notice.hidden=true;rail.hidden=!home;for(const label of Object.values(labels))label.hidden=true;if(home){heading.style.display='none';showPosition((delhiPercent-extent.min)/(extent.max-extent.min)*100);}};
 const capture=()=>svg?Promise.resolve(surface.cloneNode(true)):new Promise(resolve=>{let done=false;const finish=value=>{if(!done){done=true;resolve(value);}};window.dispatchEvent(new CustomEvent('census-map-capture',{detail:{resolve:finish}}));setTimeout(()=>finish(null),20000);});
 const ready=async()=>{const end=Date.now()+60000;while(loading()&&Date.now()<end)await wait(80);return !loading();};
 const setYear=async value=>{automatic=true;year.value=value;year.dispatchEvent(new CustomEvent('change',{bubbles:true,detail:{comparison:selection}}));metric.dispatchEvent(new Event('change',{bubbles:true}));automatic=false;return ready();};
 const move=async(to,duration=2600)=>{const token=++movement,from=position,start=performance.now();return new Promise(resolve=>{const tick=now=>{if(token!==movement){resolve();return;}const t=Math.min(1,(now-start)/duration),ease=t*t*(3-2*t);showPosition(from+(to-from)*ease);if(t<1)frame=requestAnimationFrame(tick);else resolve();};frame=requestAnimationFrame(tick);});};
 const cycle=async()=>{if(!playing||!left||!right)return;if(document.hidden){timer=setTimeout(cycle,1000);return;}await move(100,4500);if(!playing)return;await wait(700);if(!playing)return;await move(0,5500);if(!playing)return;playing=false;await setYear('2011');notice.textContent='Showing 2011 · drag the divider or choose a year';};
 const prepare=async()=>{
  if(busy||!supported())return false;busy=true;const token=++version,initial=year.value;plane.hidden=true;slider.hidden=true;notice.hidden=false;notice.textContent='Preparing 2001 / 2011 comparison…';for(const button of Object.values(buttons))button.disabled=true;
  try{
   if(!await ready())throw Error('Map values are still loading.');
   if(version!==token||!supported())return false;
   const images={};selection={field:metric.value,title:measure()};labelYears();
   for(const value of [initial,initial==='2001'?'2011':'2001']){if(version!==token)return false;if(year.value!==value&&!await setYear(value))throw Error('Year values are unavailable.');images[value]=await capture();if(version!==token||!supported())return false;if(value===initial&&images[value]){const preview=images[value];preview.removeAttribute('id');preview.setAttribute('aria-hidden','true');preview.style.cssText='position:absolute;inset:0;width:100%;height:100%;pointer-events:none;margin:0';plane.replaceChildren(preview);plane.hidden=false;}labels[value].textContent=`${value} · ${selection.title}${metric.value.endsWith('_UNAVAILABLE')?' · Unavailable':''}`;if(!images[value])throw Error('Map comparison is temporarily unavailable.');}
   if(version!==token)return false;
   if(year.value!==initial)await setYear(initial);
   if(version!==token||!supported())return false;
   right=images['2011'];left=images['2001'];
   if(left.dataset.comparisonMin){extent={min:Number(left.dataset.comparisonMin),max:Number(left.dataset.comparisonMax)};}else if(svg){const box=surface.getBoundingClientRect(),paths=surface.querySelector('g')?.getBoundingClientRect();if(paths&&paths.width){extent={min:Math.max(2,(paths.left-box.left-15)/box.width*100),max:Math.min(98,(paths.right-box.left+15)/box.width*100)};}}
   layout();heading.style.display="flex";footer.style.display="grid";rail.hidden=false;for(const label of Object.values(labels))label.hidden=false;
   plane.replaceChildren();for(const image of [right,left]){image.removeAttribute('id');image.setAttribute('aria-hidden','true');image.style.cssText='position:absolute;inset:0;width:100%;height:100%;pointer-events:none;margin:0';plane.prepend(image);}plane.append(left);plane.hidden=false;slider.hidden=false;showPosition(home?Math.max(0,Math.min(100,(delhiPercent-extent.min)/(extent.max-extent.min)*100)):isIndia()?40:50);notice.textContent='← Drag the divider or choose a year →';return true;
  }catch(error){plane.replaceChildren();plane.hidden=true;notice.textContent=error.message;playing=false;return false;}
  finally{busy=false;for(const button of Object.values(buttons))button.disabled=false;if(!home&&version!==token&&supported())timer=setTimeout(prepare,500);else if(playing&&left&&right)timer=setTimeout(cycle,500);}
 };
 for(const value of ['2001','2011'])buttons[value].addEventListener('click',async()=>{stop();if(!left||!right){if(!await prepare())return;}notice.textContent=`${value} · ${measure()} · drag ↔ to compare`;await move(value==='2001'?100:0);await setYear(value);});
 let dragging=false;
 handle.addEventListener('pointerdown',event=>{event.preventDefault();stop();dragging=true;handle.setPointerCapture(event.pointerId);});
 handle.addEventListener('pointermove',event=>{if(!dragging)return;const box=parent.getBoundingClientRect(),x=(event.clientX-box.left)/box.width*100;showPosition(Math.max(0,Math.min(100,(x-extent.min)/(extent.max-extent.min)*100)));});
 handle.addEventListener('pointerup',()=>{dragging=false;});
 handle.addEventListener('keydown',event=>{if(['ArrowLeft','ArrowRight','Home','End'].includes(event.key)){event.preventDefault();stop();showPosition(event.key==='Home'?0:event.key==='End'?100:Math.max(0,Math.min(100,position+(event.key==='ArrowLeft'?-5:5))));}});
 slider.addEventListener('input',()=>{stop();showPosition(Number(slider.value));});
 const viewportObserver=svg?new MutationObserver(()=>{const viewBox=surface.getAttribute('viewBox');for(const image of [left,right])if(image&&viewBox)image.setAttribute('viewBox',viewBox);}):null;if(viewportObserver)viewportObserver.observe(surface,{attributes:true,attributeFilter:['viewBox']});window.addEventListener('pagehide',()=>viewportObserver?.disconnect(),{once:true});
 const interact=event=>{if(!event.isTrusted||controls.contains(event.target)||event.target===slider||event.target===handle)return;if(event.type==='wheel'||event.type==='keydown'){if(surface.contains(event.target)){if(svg)stop();else clear();}return;}if(surface.contains(event.target)){stop();}else if(event.target.closest('select,input,button,summary'))stop();};
 for(const type of ['pointerdown','wheel','keydown','touchstart'])document.addEventListener(type,interact,{capture:true,passive:true});
 document.addEventListener('change',event=>{if((!automatic||event.isTrusted)&&event.target!==slider){clear();if(supported())timer=setTimeout(prepare,1200);}},true);
 metric.addEventListener('change',()=>{if(automatic)return;clear();controls.hidden=!supported();if(supported())timer=setTimeout(prepare,700);});year.addEventListener('change',()=>{if(!automatic)clear();});
 window.addEventListener('census-comparison-refresh',()=>{clear(true);if(supported())timer=setTimeout(prepare,1000);});let viewportWidth=window.innerWidth;window.addEventListener('resize',()=>{if(Math.abs(window.innerWidth-viewportWidth)<2)return;viewportWidth=window.innerWidth;clear(true);if(supported())timer=setTimeout(prepare,250);});window.addEventListener('pagehide',clear);
 if(home){
  let draggedDivider=false;
  handle.addEventListener('pointerdown',()=>{draggedDivider=false;});
  handle.addEventListener('pointermove',()=>{if(dragging)draggedDivider=true;});
  handle.addEventListener('click',async()=>{if(draggedDivider)return;stop();playing=true;footer.dataset.interaction='active';if(left&&right)cycle();else await prepare();});
  year.addEventListener('change',event=>{if(!automatic&&event.isTrusted){playing=true;footer.dataset.interaction='active';timer=setTimeout(prepare,0);}});
  window.addEventListener('census-map-delhi-position',event=>{
   if(!Number.isFinite(event.detail?.percent))return;
   delhiPercent=Math.max(2,Math.min(98,event.detail.percent));
   if(footer.dataset.interaction==='idle'){showPosition((delhiPercent-extent.min)/(extent.max-extent.min)*100);rail.hidden=!supported();}
  });
  for(const button of Object.values(buttons))button.addEventListener('click',()=>{footer.dataset.interaction='active';});
  plane.hidden=true;notice.hidden=true;slider.hidden=true;rail.hidden=!supported();heading.style.display='flex';labelYears();for(const label of Object.values(labels))label.hidden=false;
  handle.title='Click to animate the Census comparison, or drag the divider';
  handle.setAttribute('aria-label','Start Census comparison or drag divider');
  showPosition((delhiPercent-extent.min)/(extent.max-extent.min)*100);
 }
 controls.hidden=home||!supported();timer=setTimeout(prepare,150);
}
