(() => {
    document.querySelectorAll('[data-state-filter]').forEach(input => input.addEventListener('input', () => {
        const cards = document.querySelectorAll(`[data-state-grid="${input.dataset.stateFilter}"] a`); let count = 0;
        cards.forEach(card => {card.hidden = !card.textContent.toLowerCase().includes(input.value.trim().toLowerCase()); if(!card.hidden) count++;});
        document.querySelector(`[data-state-empty="${input.dataset.stateFilter}"]`).hidden = count > 0;
    }));
    const form = document.querySelector('[data-home-finder]'); if(!form) return;
    const kind=form.elements.kind, state=form.elements.state, seat=form.elements.seat, button=form.querySelector('button'), status=form.querySelector('[role=status]');
    let request=null;
    const placeholder = text => {seat.replaceChildren(new Option(text,''));seat.disabled=true;button.disabled=true;};
    async function load() {
        request?.abort(); status.textContent=''; placeholder(state.value?'Loading constituencies…':'Choose a state first');
        if(!state.value) return;
        const controller=new AbortController(); request=controller;
        const url=new URL(form.dataset.endpoint,location.href);url.search=new URLSearchParams({finder:'1',kind:kind.value,state:state.value}).toString();
        try {
            const response=await fetch(url,{signal:controller.signal,headers:{Accept:'application/json'}});if(!response.ok) throw new Error();
            const data=await response.json();if(controller.signal.aborted) return;
            placeholder(data.seats.length?'Choose a constituency':'No constituencies available');
            data.seats.forEach(row=>seat.append(new Option(row.name,row.url)));seat.disabled=!data.seats.length;
            if(!data.seats.length) status.textContent='Explore the state page for historical election records.';
        } catch(error) {if(error.name!=='AbortError'){placeholder('Could not load constituencies');status.textContent='Please select the state again to retry.';}}
    }
    kind.addEventListener('change',load);state.addEventListener('change',load);seat.addEventListener('change',()=>button.disabled=!seat.value);
    window.addEventListener('pageshow',()=>{if(state.value) load();});
    form.addEventListener('submit',event=>{event.preventDefault();if(!seat.value) return;const url=new URL(seat.value,location.href);if(url.origin===location.origin) location.assign(url.href);});
})();
