const slug=value=>String(value).normalize('NFKD').toLowerCase().replace(/&/g,'and').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
export function orderedPlaceNavigation(destinations,{mode='admin'}={}){
 const seen=new Set(),rows=[];
 for(const d of destinations){
  const url=new URL(d.url,'https://pollmedia.org'),path=url.pathname;
  const seat=path.match(/\/constituencies\/(pc|ac)\/[^/]+\/([^/]+)/);
  const kind=seat?.[1]||(path==='/india/constituency'?url.searchParams.get('kind'):null);
  const name=seat?.[2]||url.searchParams.get('name');
  const rank=kind==='pc'?4:kind==='ac'?5:/subdistrict/i.test(d.label)?2:/village|town|city/i.test(d.label)?3:/district/i.test(d.label)?1:0;
  const displayRank=mode==='electoral'?(kind==='pc'?1:kind==='ac'?2:rank===3?3:0):rank;
  const key=kind&&name?kind+':'+slug(decodeURIComponent(name)):rank<3?'parent:'+rank:'url:'+url.href;
  if(seen.has(key))continue;seen.add(key);rows.push({...d,rank:displayRank});
 }
 return rows.sort((a,b)=>a.rank-b.rank).map(({rank,...d})=>d);
}

export function statePageForMode(value,mode){const url=new URL(value,'https://pollmedia.org');const match=url.pathname.match(/^\/india\/(?:census\/states|state)\/([^/]+)\/?$/);if(!match)return value;url.pathname=(mode==='electoral'?'/india/state/':'/india/census/states/')+match[1];return url.href;}
