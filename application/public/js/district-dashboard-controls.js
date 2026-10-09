const picker=document.getElementById('district-picker');
picker?.addEventListener('change',()=>{const url=new URL(picker.value,location.href);if(url.origin===location.origin)location.assign(url.href);});
const search=document.getElementById('village-search');
const rows=[...document.querySelectorAll('#census-villages tbody tr:not([data-empty])')];
function filterVillages(){const query=(search?.value||'').trim().toLocaleLowerCase();let count=0;for(const row of rows){row.hidden=!row.cells[0].textContent.toLocaleLowerCase().includes(query);if(!row.hidden)count++;}const status=document.getElementById('village-results');if(status)status.textContent=`${count} matching published Census villages`;}
search?.addEventListener('input',filterVillages);filterVillages();
