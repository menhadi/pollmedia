const $ = s => document.querySelector(s);
const gps=$('#issue-gps'), status=$('#issue-location-status'), choices=$('#issue-location-choices'), compose=$('#issue-compose');
let reporter=null, problem=null, gpsGeneration=0, optionsGeneration=0, selectionGeneration=0, feedGeneration=0;
const label = location => location.scopes.filter(s=>['village','locality','district','state'].includes(s.kind)).map(s=>s.name).join(' · ');
async function post(meta, body) {
    const response=await fetch($(meta).content,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':$('input[name="_token"]').value},body:JSON.stringify(body)});
    const data=await response.json(); if(!response.ok) throw new Error(data.message || 'Please retry.'); return data;
}
const locationKey = location => location?.identity_key || location?.scopes.find(s=>s.kind==='village')?.key;
let pendingProblem=null, detectionRunning=false;
function showProblem(location) {
    problem=location; pendingProblem=null; $('#issue-location-token').value=location.token;
    $('#issue-location-warning').hidden=true;
    $('#issue-problem-label').textContent='Reported from: '+(reporter?label(reporter):'Not available / not shared')+(locationKey(reporter)!==locationKey(location)?' | Problem location: '+label(location):'');
    $('#issue-confirmed-label').textContent='Reported from: '+(reporter?label(reporter):'Not available / not shared')+' | Problem location: '+label(location);
    compose.hidden=false;
    loadLocationFeed(locationKey(location));
}
function proposeProblem(location) {
    $('#issue-different-confirmed').value='';
    if(reporter && locationKey(reporter)!==locationKey(location)) {
        loadLocationFeed(locationKey(location));pendingProblem=location;compose.hidden=false;$('#issue-location-token').value='';
        $('#issue-location-warning-text').textContent='Detected location: '+label(reporter)+' | Problem location: '+label(location);
        $('#issue-location-warning').hidden=false;
        $('#issue-location-warning').scrollIntoView({behavior:'smooth'});
    } else showProblem(location);
}
function clearProblem(){clearLocationFeed();problem=null;pendingProblem=null;selectionGeneration++;compose.hidden=false;$('#issue-location-token').value='';$('#issue-different-confirmed').value='';$('#issue-location-warning').hidden=true;$('#issue-problem-label').textContent='Choose and confirm the problem location.';}
function detectionFailed(message){detectionRunning=false;status.textContent=message+' Please enter the problem location manually below. The detected place will be marked unavailable.';gps.hidden=false;gps.disabled=false;$('#issue-other-location').open=true;}
function detect() {
    const current=++gpsGeneration;detectionRunning=true;gps.disabled=true;gps.hidden=true;choices.replaceChildren();
    status.textContent='Detecting your location…';
    if(!window.isSecureContext){detectionFailed('Location requires a secure HTTPS page.');return;}
    if(!navigator.geolocation){detectionFailed('This browser does not provide device location.');return;}
    const fail=error=>{
        if(current!==gpsGeneration)return;
        const messages={1:'Location permission is blocked. Allow location for pollmedia.org in your browser’s site settings and enable device location.',2:'Your device could not determine its location. Check that device location services are enabled.',3:'The device did not return a location in time. Please retry.'};
        detectionFailed(messages[error.code]||'Device location could not be read.');
    };
    const received=async p=>{
        if(current!==gpsGeneration)return;
        status.textContent='Location received. Finding its place name…';
        try {
            const data=await post('meta[name="issue-locate-url"]',{latitude:p.coords.latitude,longitude:p.coords.longitude,accuracy:p.coords.accuracy});
            if(current!==gpsGeneration)return;
            if(!data.choices.length){detectionFailed('Your coordinates were received, but the available Pollmedia boundaries do not identify this locality. This is a map-coverage gap, not a location-permission failure.');return;}
            detectionRunning=false;
            const useDetected=choice=>{reporter=choice;$('#issue-reporter-token').value=choice.token;status.textContent='You are reporting a problem from '+label(choice)+'.'+(p.coords.accuracy?' Device accuracy: about '+Math.round(p.coords.accuracy)+' metres.':'');choices.replaceChildren();if(problem)proposeProblem(problem);else proposeProblem(choice);};
            if(data.choices.length===1)useDetected(data.choices[0]);
            else {
                status.textContent='The map has overlapping place matches. Choose the correct detected place.';
                for(const choice of data.choices){const button=document.createElement('button');button.type='button';button.textContent=label(choice);button.addEventListener('click',()=>useDetected(choice));choices.append(button);}
            }
            gps.hidden=false;gps.disabled=false;
        }catch(e){if(current===gpsGeneration)detectionFailed('The place lookup failed: '+e.message);}
    };
    navigator.geolocation.getCurrentPosition(received,error=>{
        if(current!==gpsGeneration)return;
        if(error.code===1){fail(error);return;}
        status.textContent='Trying a more accurate device location…';
        navigator.geolocation.getCurrentPosition(received,fail,{enableHighAccuracy:true,timeout:20000,maximumAge:0});
    },{enableHighAccuracy:false,timeout:10000,maximumAge:60000});
}
function resetSelect(select,prompt){select.replaceChildren(new Option(prompt,''));select.disabled=true;}
const optionCache=new Map();
async function loadOptions(select,params,prompt,generation){
    const cacheKey=new URLSearchParams(params).toString();
    select.replaceChildren(new Option("Loading locations…", ""));
    let data=optionCache.get(cacheKey);
    if(!data){const response=await fetch($('meta[name="issue-options-url"]').content+'?'+cacheKey,{headers:{Accept:'application/json'}});data=await response.json();if(!response.ok)throw new Error(data.message||'Could not load locations.');optionCache.set(cacheKey,data);}
    if(generation!==optionsGeneration)return;
    resetSelect(select,prompt);for(const option of data.options)select.add(new Option(option.name,option.code));select.disabled=false;
    if(!data.options.length)$('#issue-problem-status').textContent='No directory locations are available here.';
}
const state=$('#issue-state'),district=$('#issue-district'),village=$('#issue-village'),confirm=$('#issue-confirm-problem');
state.addEventListener('change',async()=>{clearProblem();resetSelect(district,'Choose a district');resetSelect(village,'Choose a village');confirm.disabled=true;const generation=++optionsGeneration;$('#issue-problem-status').textContent='';if(state.value)try{await loadOptions(district,{state:state.value},'Choose a district',generation);}catch(e){$('#issue-problem-status').textContent=e.message;}});
district.addEventListener('change',async()=>{clearProblem();resetSelect(village,'Choose a village');confirm.disabled=true;const generation=++optionsGeneration;$('#issue-problem-status').textContent='';if(district.value)try{loadLocationFeed('district:'+state.value+':'+district.value);await loadOptions(village,{state:state.value,district:district.value},'Choose a village',generation);}catch(e){$('#issue-problem-status').textContent=e.message;}});
village.addEventListener('change',()=>{clearProblem();confirm.disabled=!village.value;loadLocationFeed(village.value?'village:'+village.value:'district:'+state.value+':'+district.value);});
confirm.addEventListener('click',async()=>{const current=++selectionGeneration;confirm.disabled=true;try{const choice=await post('meta[name="issue-problem-url"]',{state:state.value,district:district.value,village:village.value});if(current!==selectionGeneration)return;proposeProblem(choice);$('#issue-problem-status').textContent='Location confirmed.';if(!compose.hidden)compose.scrollIntoView({behavior:'smooth'});}catch(e){$('#issue-problem-status').textContent=e.message;}finally{if(current===selectionGeneration)confirm.disabled=false;}});
$('#issue-proceed-different').addEventListener('click',()=>{if(pendingProblem){const location=pendingProblem;$('#issue-different-confirmed').value='1';showProblem(location);}});
$('#issue-cancel-different').addEventListener('click',()=>{if(reporter)proposeProblem(reporter);});
const reportForm=$('form[enctype="multipart/form-data"]'), submitStatus=$('#issue-submit-status'), submitLocation=$('#issue-submit-location');
function submissionMessage(message,review=false){submitStatus.hidden=false;submitStatus.textContent=message;submitLocation.hidden=!review;submitStatus.focus();}
submitLocation.addEventListener('click',()=>{const target=pendingProblem?$('#issue-location-warning'):$('#issue-other-location');if(!pendingProblem){target.open=true;$('#issue-change-location').setAttribute('aria-expanded','true');}target.scrollIntoView({behavior:'smooth',block:'center'});});
reportForm.addEventListener('invalid',event=>{submissionMessage(event.target.validationMessage || 'Complete the required fields before posting.');},true);
let submissionResolving=false;
reportForm.addEventListener('submit',async event=>{
 if(submissionResolving){event.preventDefault();return;}
 if(!reportForm.description.value.trim() && !reportForm.querySelector('[name="media[]"]').files.length){event.preventDefault();submissionMessage('Add a description or at least one photo or video before posting.');return;}
 if(detectionRunning){event.preventDefault();submissionMessage('Location detection is still running. Your report is saved in this form. Wait for it to finish, then press Post report again.',true);return;}
 if(!$('#issue-location-token').value){
  event.preventDefault();
  if(pendingProblem){submissionMessage('Your reporting and problem locations differ. Review and confirm the location before posting.',true);return;}
  if(!village.value){submissionMessage('Select a village and confirm the problem location, or retry automatic detection. Your report stays in this form.',true);return;}
  submissionResolving=true;const current=selectionGeneration;submissionMessage('Confirming your selected location…');
  try{const choice=await post('meta[name="issue-problem-url"]',{state:state.value,district:district.value,village:village.value});if(current!==selectionGeneration){submissionMessage('The location changed. Check your selection and press Post report again.',true);return;}proposeProblem(choice);if(pendingProblem){submissionMessage('Your reporting and problem locations differ. Review and confirm the location before posting.',true);}else{submissionResolving=false;reportForm.requestSubmit();}}
  catch(error){submissionMessage('Could not confirm the location: '+error.message,true);}finally{submissionResolving=false;}
  return;
 }
 submitStatus.hidden=false;submitStatus.textContent='Posting your report…';submitLocation.hidden=true;
});
$('#issue-change-location').addEventListener('click',()=>{const panel=$('#issue-other-location');panel.open=!panel.open;$('#issue-change-location').setAttribute('aria-expanded',String(panel.open));});
gps.addEventListener('click',detect);
loadOptions(state,{},'Choose a state',optionsGeneration).catch(e=>{$('#issue-problem-status').textContent=e.message;});
detect();

function clearLocationFeed(){feedGeneration++;const feed=$('#issue-selected-feed');feed.replaceChildren();const p=document.createElement('p');p.textContent='Choose a problem location to see reports from that place.';feed.append(p);}
async function loadLocationFeed(key,page=1){
 const generation=++feedGeneration,feed=$('#issue-selected-feed');feed.replaceChildren();const message=document.createElement('p');message.textContent='Loading reports at this location…';feed.append(message);
 if(!key){clearLocationFeed();return;}
 try{const url=new URL(window.location.pathname,window.location.origin);url.searchParams.set('area',key);url.searchParams.set('feed','1');url.searchParams.set('page',page);const response=await fetch(url,{headers:{Accept:'text/html'}});if(!response.ok)throw new Error('Reports could not be loaded.');const doc=new DOMParser().parseFromString(await response.text(),'text/html');if(generation!==feedGeneration)return;const content=doc.querySelector('#issue-selected-feed');if(!content)throw new Error('Reports could not be loaded.');feed.replaceChildren(...content.childNodes);feed.querySelectorAll('a[href*="page="]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();loadLocationFeed(key,new URL(link.href).searchParams.get('page'));}));}catch(error){if(generation===feedGeneration)message.textContent='Could not load reports for this location. Please confirm the location again.';}
}
