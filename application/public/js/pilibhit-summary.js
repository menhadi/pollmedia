(() => {
    const form=document.querySelector('[data-pb-form]');
    if(!form)return;
    const input=form.elements.q, status=document.querySelector('[data-pb-status]');
    const hi=document.documentElement.lang==='hi';
    let version=0, timer, controller, retryUrl;
    function cancel(){version++;clearTimeout(timer);controller?.abort();}
    function searchUrl(){
        const url=new URL(form.action);
        url.search=new URLSearchParams(new FormData(form));
        if(!url.searchParams.get('q')?.trim())url.searchParams.delete('q');
        return url;
    }
    async function load(url,focusResults=false){
        cancel();
        const token=version;
        controller=new AbortController();
        const api=new URL(url);api.hash='';api.searchParams.set('part','village-list');
        const panel=document.querySelector('[data-pb-results]');
        panel.setAttribute('aria-busy','true');
        const loading=setTimeout(()=>{if(token===version)status.textContent=hi?'गाँव खोजे जा रहे हैं…':'Loading villages…';},230);
        try{
            const response=await fetch(api,{signal:controller.signal,headers:{Accept:'text/html'}});
            if(!response.ok)throw new Error('directory');
            const result=new DOMParser().parseFromString(await response.text(),'text/html').querySelector('[data-pb-results]');
            if(!result)throw new Error('directory');
            if(token!==version)return;
            panel.replaceWith(result);status.textContent='';retryUrl=null;
            history.replaceState(history.state,'',url);
            if(focusResults){
                const heading=document.querySelector('#places h2');heading.tabIndex=-1;heading.focus({preventScroll:true});heading.scrollIntoView({block:'start'});
            }
        }catch(err){
            if(token!==version || err.name==='AbortError')return;
            retryUrl=url;status.textContent=hi?'गाँव लोड नहीं हुए। ':'Could not load villages. ';
            const retry=document.createElement('button');retry.type='button';retry.textContent=hi?'फिर कोशिश करें':'Retry';retry.onclick=()=>load(retryUrl);status.append(retry);
        }finally{
            clearTimeout(loading);if(token===version)document.querySelector('[data-pb-results]').removeAttribute('aria-busy');
        }
    }
    form.addEventListener('submit',e=>{e.preventDefault();load(searchUrl());});
    input.addEventListener('input',()=>{cancel();status.textContent='';timer=setTimeout(()=>load(searchUrl()),280);});
    document.addEventListener('click',e=>{
        const a=e.target.closest('a[data-pb-page]');
        if(!a||e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.altKey||e.shiftKey)return;
        e.preventDefault();load(new URL(a.href),true);
    });
})();
