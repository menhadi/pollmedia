const picker=document.getElementById('district-picker');
picker?.addEventListener('change',()=>{const url=new URL(picker.value,location.href);if(url.origin===location.origin)location.assign(url.href);});
const search=document.getElementById('census-village-search');
const rows=[...document.querySelectorAll('#census-villages tbody tr:not([data-empty])')];
function filterVillages(){const query=(search?.value||'').trim().toLocaleLowerCase();let count=0;for(const row of rows){row.hidden=!row.cells[0].textContent.toLocaleLowerCase().includes(query);if(!row.hidden)count++;}const status=document.getElementById('village-results');if(status)status.textContent=`${count} matching published Census villages`;}
search?.addEventListener('input',filterVillages);filterVillages();

// Keep long data tables and supporting methodology easy to find without crowding the page.
(() => {
 if (document.querySelector('[data-compact-place-assets]')) return;
 const main=document.querySelector('main');if(!main)return;
 const methodology=document.createElement('details');methodology.id='methodology';methodology.className='panel place-methodology';
 const summary=document.createElement('summary');summary.textContent='Methodology & disclaimer · sources and data notes';methodology.append(summary);main.append(methodology);
 function arrange(){
  const sources=document.getElementById('sources');if(sources&&!methodology.contains(sources)){methodology.append(sources);const h=sources.querySelector('h2');if(h)h.textContent='Sources & coverage';}
  for(const note of [...main.querySelectorAll('details')]){
   if(note===methodology||methodology.contains(note)||note.closest('[data-water-evidence]')||note.classList.contains('place-data-fold'))continue;
   const title=note.querySelector(':scope > summary');if(!title||!/(disclaimer|methodology|sources|data notes|how these constituency links)/i.test(title.textContent))continue;
   const section=document.createElement('section'),heading=document.createElement('h3');heading.textContent=title.textContent;section.append(heading);title.remove();while(note.firstChild)section.append(note.firstChild);note.replaceWith(section);methodology.append(section);
  }
  for(const id of ['villages','towns','subdistricts','soil','rainfall']){
   const section=document.getElementById(id);if(!section||section.querySelector(':scope > .place-data-fold'))continue;
   const heading=section.querySelector('h2');if(!heading)continue;
   const count=section.querySelectorAll('tbody tr:not([data-empty])').length;
   const fold=document.createElement('details');fold.className='place-data-fold';const label=document.createElement('summary');label.textContent=heading.textContent+(count?' · '+count.toLocaleString()+' rows available':' · view available data');fold.append(label);heading.remove();while(section.firstChild)fold.append(section.firstChild);section.append(fold);
  }
  for(const p of main.querySelectorAll('p'))if(/^(Reading published database records\. Reload after publication to see updated figures\.|Published database records are read on every request\. Newly published imports appear when this page reloads\.)$/.test(p.textContent.trim()))p.remove();
  let position=document.getElementById('elections')||document.getElementById('history');
  if(position)for(const id of ['subdistricts','towns','villages','soil']){const section=document.getElementById(id);if(section){if(position.nextElementSibling!==section)position.after(section);position=section;}}
  const rain=document.getElementById('rainfall');const rainAnchor=document.getElementById('rainfall-annual')||methodology;if(rain&&rainAnchor.previousElementSibling!==rain)rainAnchor.before(rain);
  for(const node of [...main.childNodes])if(node.nodeType===3&&node.textContent.trim()==='null')node.remove();
  const annual=document.getElementById('rainfall-annual');if(annual){if(methodology.previousElementSibling!==annual)methodology.before(annual);const nav=document.querySelector('.district-title .section-nav');if(nav&&!nav.querySelector('a[href="#rainfall-annual"]')){const link=document.createElement('a');link.href='#rainfall-annual';link.textContent='Rainfall · 2023 & 2024';const edit=nav.querySelector('.data-edit-listing');if(edit)edit.before(link);else nav.append(link);}}
  for(const link of document.querySelectorAll('a[href="#sources"]')){link.href='#methodology';link.textContent='Methodology & disclaimer';}
  const satellite=document.getElementById('satellite-comparison'),sectionLinks=main.querySelector('.section-nav');if(satellite&&sectionLinks&&!sectionLinks.querySelector('a[href="#satellite-comparison"]')){const link=document.createElement('a');link.href='#satellite-comparison';link.textContent='Satellite';sectionLinks.insertBefore(link,sectionLinks.querySelector('.data-edit-listing'));}
  const navigation=main.querySelector('.section-nav');
  if(navigation){
   const links=[...navigation.children];
   const target=link=>{const href=link.getAttribute('href')||'';return href.startsWith('#')?document.getElementById(href.slice(1)):null;};
   const ordered=[...links].sort((a,b)=>{
    const editA=a.classList.contains('data-edit-listing'),editB=b.classList.contains('data-edit-listing');
    if(editA!==editB)return editA?1:-1;
    const sectionA=target(a),sectionB=target(b);
    if(sectionA&&sectionB){const position=sectionA.compareDocumentPosition(sectionB);return position&Node.DOCUMENT_POSITION_FOLLOWING?-1:position&Node.DOCUMENT_POSITION_PRECEDING?1:0;}
    return sectionA?-1:sectionB?1:0;
   });
   if(ordered.some((link,index)=>link!==links[index]))navigation.append(...ordered);
  }
 }
 function reveal(){const id=decodeURIComponent(location.hash.slice(1));const target=document.getElementById(id);if(!target)return;if(target.matches('details'))target.open=true;const fold=target.querySelector(':scope > details.place-data-fold');if(fold)fold.open=true;for(let p=target.parentElement;p;p=p.parentElement)if(p.matches('details'))p.open=true;}
 let scheduled=false;new MutationObserver(()=>{if(scheduled)return;scheduled=true;requestAnimationFrame(()=>{scheduled=false;arrange();});}).observe(main,{childList:true,subtree:true});
 arrange();reveal();addEventListener('hashchange',reveal);
})();
