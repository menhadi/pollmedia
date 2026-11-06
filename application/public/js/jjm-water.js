(() => {
 const disclaimer=document.getElementById('water-disclaimer'),main=document.querySelector('main');if(disclaimer&&main)main.append(disclaimer);
 const panel=document.getElementById('water');if(!panel)return;
 const rows=[...panel.querySelectorAll('[data-water-row]')],block=document.getElementById('water-block'),search=document.getElementById('water-search'),minimum=document.getElementById('water-min'),maximum=document.getElementById('water-max');
 function filter(){let count=0;for(const row of rows){const value=row.dataset.coverage===''?null:Number(row.dataset.coverage);const match=(!block.value||row.dataset.block===block.value)&&row.dataset.name.toLowerCase().includes(search.value.trim().toLowerCase())&&(minimum.value===''||(value!==null&&value>=Number(minimum.value)))&&(maximum.value===''||(value!==null&&value<=Number(maximum.value)));row.hidden=!match;if(match)count++;}document.getElementById('water-results').textContent=`${count.toLocaleString('en-IN')} of ${rows.length.toLocaleString('en-IN')} JJM source records`;}
 for(const control of [block,search,minimum,maximum])control.addEventListener('input',filter);filter();
})();
