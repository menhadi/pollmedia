(() => {
    if(window.parent===window)return;
    let pending=false;
    const send=()=>{pending=false;const main=document.querySelector('main');if(main)parent.postMessage({type:'pollmedia-place-height',height:Math.ceil(main.getBoundingClientRect().height+40)},location.origin);};
    const schedule=()=>{if(!pending){pending=true;requestAnimationFrame(send);}};
    const main=document.querySelector('main');if(main)new ResizeObserver(schedule).observe(main);
    window.addEventListener('load',schedule);document.addEventListener('load',schedule,true);schedule();
})();
