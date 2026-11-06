import {loadMarkets} from './market-prices.js?v=041db146335b';
for(const root of document.querySelectorAll('[data-page-market]')){
    const details=root.querySelector('[data-market-page-details]'),select=root.querySelector('[data-market-page-district]'),mount=root.querySelector('[data-market-page-mount]');
    if(!details||!select||!mount)continue;
    const t=(en,hi)=>root.dataset.language==='hi'?hi:en;
    function show(){
        if(!details.open)return;
        const option=select.selectedOptions[0];
        if(!option?.value){mount.textContent=t('Choose the current district to see its reports.','रिपोर्ट देखने के लिए वर्तमान जिला चुनें।');return;}
        const previous=mount.querySelector('[data-loaded-scope]');if(previous?.dataset.loadedScope===option.value&&previous.dataset.loaded)return;
        const body=document.createElement('div');body.className='hub-body';body.dataset.loadedScope=option.value;body.dataset.loaded='1';
        const status=document.createElement('p');status.dataset.marketStatus='';status.setAttribute('role','status');
        const records=document.createElement('div');records.dataset.marketRecords='';body.append(status,records);mount.replaceChildren(body);
        root.dataset.state=option.dataset.state;root.dataset.district=option.dataset.district;
        loadMarkets(body,root,t);
    }
    details.addEventListener('toggle',show);select.addEventListener('change',()=>{mount.replaceChildren();show();});
    const reveal=()=>{if(location.hash==='#'+root.id){details.open=true;show();}};window.addEventListener('hashchange',reveal);reveal();show();
}
