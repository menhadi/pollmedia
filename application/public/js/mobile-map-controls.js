(() => {
 const mobile=window.matchMedia('(max-width:760px)');
 for(const root of document.querySelectorAll('.explorer,[data-polygon-map]')){
  const controls=root.querySelector('.metric-controls,.polygon-filters'),finder=root.querySelector('.finder,.polygon-finder');
  if(!controls||!finder)continue;
  const sheet=document.createElement('details');sheet.className='mobile-map-filters';
  const summary=document.createElement('summary');summary.textContent='Filters';sheet.append(summary);
  const apply=document.createElement('button');apply.type='button';apply.className='mobile-map-apply';apply.textContent='Show map';
  const slots=[controls,finder].map(node=>{const anchor=document.createComment('map-control-position');node.before(anchor);return {node,anchor};});
  root.append(sheet);sheet.append(apply);
  if(root.matches('[data-polygon-map]')&&!root.querySelector('.map-toolbar')){
   const bar=document.createElement('div');bar.className='map-toolbar';
   const heading=document.createElement('strong');heading.textContent='Filters';bar.append(heading);
   root.querySelector('.polygon-panel').prepend(bar);
  }
  const title=root.querySelector('.map-toolbar>strong');
  const toggle=title?document.createElement('button'):null;
  if(toggle){
   toggle.type='button';toggle.className='mobile-map-filter-toggle';toggle.textContent='Filters';
   sheet.id='mobile-map-filters-'+Array.from(document.querySelectorAll('.mobile-map-filters')).indexOf(sheet);
   toggle.setAttribute('aria-controls',sheet.id);toggle.setAttribute('aria-expanded','false');
   title.after(toggle);sheet.classList.add('has-toolbar-toggle');
   toggle.addEventListener('click',()=>{sheet.open=true;sheet.scrollIntoView({block:'start',behavior:'smooth'});});
   sheet.addEventListener('toggle',()=>toggle.setAttribute('aria-expanded',String(sheet.open)));
  }
  const layout=()=>{if(mobile.matches){sheet.open=!document.body.classList.contains('place-layout');if(toggle)toggle.setAttribute('aria-expanded',String(sheet.open));for(const {node}of slots)sheet.insertBefore(node,apply);}else{for(const {node,anchor}of slots)anchor.after(node);sheet.open=false;}};
  apply.addEventListener('click',()=>{sheet.open=mobile.matches&&!document.body.classList.contains('place-layout');root.querySelector('.map-panel,.polygon-panel').scrollIntoView({block:'start',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});});
  mobile.addEventListener('change',layout);layout();
 }
})();

(() => {
 const disclaimer=document.querySelector('.india-explorer #coverage');
 if(!disclaimer)return;
 const main=disclaimer.closest('main')||document.querySelector('main');
 const notes=document.createElement('div');notes.className='map-methodology-notes';
 for(const note of document.querySelectorAll('.india-explorer .metric-controls>p,.india-explorer #map-status'))notes.append(note);
 disclaimer.append(notes);
 const options=document.querySelector('.india-explorer .reference-notes');
 if(options){
  options.querySelector('summary')?.remove();
  const group=document.createElement('section');
  const title=document.createElement('h3');title.textContent='Map options and source geography';group.append(title);
  while(options.firstChild)group.append(options.firstChild);
  disclaimer.append(group);options.remove();
 }
 const water=document.getElementById('water-disclaimer');
 if(water){
  water.querySelector('summary')?.remove();
  const heading=document.createElement('h3');heading.textContent='Water data: sources and dates';
  const section=document.createElement('section');section.id='water-disclaimer';section.append(heading);
  while(water.firstChild)section.append(water.firstChild);
  disclaimer.append(section);water.remove();
 }
 const finish=()=>{
  for(const note of document.querySelectorAll('.india-explorer .metric-controls>p,.india-explorer .metric-controls .metric-coverage')){if(!notes.contains(note))notes.append(note);}
  const lateWater=document.querySelector('main> #water-disclaimer');
  if(lateWater){
   const section=document.createElement('section');section.id='water-disclaimer';
   const heading=document.createElement('h3');heading.textContent='Water data: sources and dates';section.append(heading);
   lateWater.querySelector('summary')?.remove();while(lateWater.firstChild)section.append(lateWater.firstChild);
   disclaimer.append(section);lateWater.remove();
  }
  const destination=main?.querySelector('#area-methodology,#local-methodology,#methodology,#place-methodology')||main;if(destination&&disclaimer.parentElement!==destination)destination.append(disclaimer);
 };
 finish();if(main)new MutationObserver(finish).observe(main,{childList:true,subtree:true});
})();

(() => {
 const map=document.querySelector('[data-polygon-map]');if(!map)return;
 const main=map.closest('main');if(!main)return;
 const disclaimer=document.createElement('details');disclaimer.className='coverage district-map-disclaimer';
 const summary=document.createElement('summary');summary.textContent='Disclaimer, methodology & sources';disclaimer.append(summary);main.append(disclaimer);
 const collect=()=>{const note=map.querySelector('#census-year-note');if(note)disclaimer.append(note);};
 collect();new MutationObserver(collect).observe(map,{childList:true,subtree:true});
})();

(() => {
 for(const root of document.querySelectorAll('.explorer,[data-polygon-map]')){
  let changed=false;
  const selected=()=>Array.from(root.querySelectorAll('#state-filter,#admin-district-filter,#subdistrict-filter,#village-filter,#polygon-subdistrict,#polygon-village,#polygon-search')).some(input=>Boolean(input.value));
  const update=()=>{for(const button of root.querySelectorAll('#back-to-india,#polygon-reset'))button.hidden=!(changed||selected());};
  root.addEventListener('change',event=>{if(event.isTrusted&&event.target.matches('select,input')&&!event.target.matches('#census-map-year,#colour-metric,#polygon-metric')){changed=true;update();}});
  root.addEventListener('pointerup',event=>{if(event.isTrusted&&event.target.closest('#india-map,#district-polygons')){changed=true;update();}});
  root.addEventListener('click',event=>{if(event.target.closest('#back-to-india,#polygon-reset')){changed=false;setTimeout(update,0);}});
  new MutationObserver(update).observe(root,{childList:true,subtree:true});update();
 }
})();
