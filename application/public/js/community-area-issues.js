(() => {
 const root=document.querySelector('[data-community-area]');if(!root)return;
 const panel=root.querySelector('[data-community-panel]'),tabs=[...root.querySelectorAll('[data-community-tab]')];
 let issues=location.hash==='#community-issues';
 function conceal(){
  for(let el=root.nextElementSibling;el;el=el.nextElementSibling){
   if(['SCRIPT','STYLE','LINK','FOOTER'].includes(el.tagName))continue;
   if(issues&&!el.hasAttribute('data-community-concealed')){el.dataset.communityDisplay=el.style.getPropertyValue('display');el.dataset.communityDisplayPriority=el.style.getPropertyPriority('display');}
   el.toggleAttribute('data-community-concealed',issues);
   if(issues){el.style.setProperty('display','none','important');}else if('communityDisplay' in el.dataset){el.style.removeProperty('display');if(el.dataset.communityDisplay)el.style.setProperty('display',el.dataset.communityDisplay,el.dataset.communityDisplayPriority);delete el.dataset.communityDisplay;delete el.dataset.communityDisplayPriority;}
  }
 }
 function select(value){issues=value;panel.hidden=!issues;conceal();for(const tab of tabs)tab.setAttribute('aria-selected',String((tab.dataset.communityTab==='issues')===issues));}
 tabs.forEach(tab=>tab.addEventListener('click',()=>{select(tab.dataset.communityTab==='issues');history.replaceState(null,'',location.pathname+location.search+(issues?'#community-issues':''));}));
 new MutationObserver(conceal).observe(root.parentElement,{childList:true});
 window.addEventListener('hashchange',()=>select(location.hash==='#community-issues'));
 select(issues);
})();
