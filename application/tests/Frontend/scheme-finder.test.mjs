import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {SourceTextModule,SyntheticModule,createContext} from 'node:vm';
class Element extends EventTarget {
 constructor(tag='div'){super();this.tagName=tag;this.dataset={};this.children=[];this.value='';this.textContent='';this.checked=false;this.files=[];this.hidden=false;}
 append(...children){this.children.push(...children);}
 replaceChildren(...children){this.children=children;this.textContent='';}
 hasAttribute(name){return name==='data-confirm'&&this.confirm;}
 get selectedOptions(){return [{textContent:this.value==='9'?'Uttar Pradesh':this.value==='173'?'Pilibhit':this.value}];}
 reset(){} reportValidity(){return true;} scrollIntoView(){} focus(){}
}
const settle=()=>new Promise(resolve=>setImmediate(resolve));
async function setup(lang='en',pin='',schemes=null){
 const fields=Object.fromEntries(['name','dob','age','pincode','state','district','interest'].map(key=>{const e=new Element();e.dataset.field=key;return [key,e];}));fields.interest.value='all';fields.pincode.value=pin;
 const form=new Element(),results=new Element(),status=new Element(),ocr=new Element(),photo=new Element(),read=new Element(),clear=new Element(),confirm=new Element(),consent=new Element(),coverage=new Element(),check=new Element(),catalogue=new Element();confirm.confirm=true;consent.checked=true;photo.files=[{size:100}];
 const data=JSON.parse(await readFile(new URL('../../database/fixtures/my-place-schemes.json',import.meta.url),'utf8'));if(schemes)data.schemes=schemes;catalogue.textContent=JSON.stringify(data);const wrappers={};for(const key of Object.keys(data.questions)){const field=new Element();field.dataset.field=key;fields[key]=field;const wrapper=new Element();wrapper.dataset.question=key;wrappers[key]=wrapper;}
 const nodes={'[data-scheme-form]':form,'[data-results]':results,'[data-status]':status,'[data-ocr-status]':ocr,'[data-photo]':photo,'[data-read]':read,'[data-clear]':clear,'[data-confirm]':confirm,'[data-ocr-consent]':consent,'[data-scheme-coverage]':coverage,'[data-check]':check,'#scheme-catalogue':catalogue};
 const root=new Element();root.dataset={language:lang,options:'https://pollmedia.org/my-place/options',autofill:'https://pollmedia.org/my-place/schemes/autofill',initial:'{"state":"9","district":"173"}'};root.querySelector=s=>nodes[s];root.querySelectorAll=selector=>selector==='[data-question]'?Object.values(wrappers):[];form.querySelectorAll=()=>Object.values(fields);
 const manual=new Element(),pinStatus=new Element(),pinButton=new Element();nodes['[data-age-manual]']=manual;nodes['[data-pin-status]']=pinStatus;nodes['[data-pin-lookup]']=pinButton;root.dataset.pincodeUrl='https://pollmedia.org/my-place/schemes/pincode';
 let resolveFetch,requests=0;const context=createContext({Event,URL,location:{origin:'https://pollmedia.org'},AbortController,FormData:class{append(){}},document:{querySelector:s=>s==='[data-place-hub]'?{}:{content:'test-token'},createElement:tag=>new Element(tag)},fetch:()=>{requests++;return new Promise(resolve=>{resolveFetch=resolve;});}});
 const hierarchy=new SyntheticModule(['placeHierarchy'],function(){this.setExport('placeHierarchy',async()=>{fields.state.value='9';fields.district.value='173';return {set:async values=>{fields.state.value=values.state||'';fields.district.value=values.district||'';}};});},{context});
 const rules=new SourceTextModule(await readFile(new URL('../../public/js/scheme-rules.js',import.meta.url),'utf8'),{context});await rules.link(()=>{});await rules.evaluate();
 const module=new SourceTextModule(await readFile(new URL('../../public/js/scheme-finder.js',import.meta.url),'utf8'),{context});await module.link(spec=>spec.includes('place-hierarchy')?hierarchy:rules);await module.evaluate();module.namespace.mountSchemeFinder(root);await settle();
 return {root,form,fields,read,confirm,wrappers,results,consent,photo,ocr,coverage,check,manual,pinStatus,pinButton,resolveRaw:data=>resolveFetch({ok:true,json:async()=>data}),requests:()=>requests,resolve:fields=>resolveFetch({ok:true,json:async()=>({fields})})};
}
for(const lang of ['en','hi']){
 test(`photo autofill fills editable fields and asks for confirmation (${lang})`,async()=>{
  const s=await setup(lang);assert.equal(s.check.disabled,false);assert.match(s.coverage.textContent,/3/);s.confirm.checked=true;s.read.dispatchEvent(new Event('click'));await settle();s.resolve({name:'Example Citizen',dob:'1985-01-01',pincode:'262001',state:'9',district:'173'});await settle();await settle();
  if(s.requests()===2){s.resolveRaw({reason:null,matches:[{state:'9',district:'173'}],postal_regions:[]});await settle();await settle();}assert.equal(s.check.disabled,false);assert.equal(s.fields.name.value,'Example Citizen');assert.equal(s.fields.dob.value,'1985-01-01');assert.equal(s.fields.pincode.value,'262001');assert.equal(s.fields.state.value,'9');assert.equal(s.fields.district.value,'173');assert.equal(s.confirm.checked,false);assert.equal(s.photo.value,'');assert.equal(s.consent.checked,false);
  s.fields.name.value='Edited example';assert.equal(s.fields.name.value,'Edited example');
 });
 test(`a delayed photo response preserves newer edits (${lang})`,async()=>{
  const s=await setup(lang);s.read.dispatchEvent(new Event('click'));await settle();s.fields.name.value='My newer edit';const edit=new Event('input');Object.defineProperty(edit,'target',{value:s.fields.name});s.form.dispatchEvent(edit);s.resolve({name:'Older OCR value',dob:'1985-01-01'});await settle();await settle();assert.equal(s.fields.name.value,'My newer edit');assert.equal(s.fields.dob.value,'');assert.match(s.ocr.textContent,lang==='hi'?/बदलाव सुरक्षित/:/edits have been kept/);
 });
 test(`photo consent is required but manual matching remains available (${lang})`,async()=>{
  const s=await setup(lang);s.consent.checked=false;s.read.dispatchEvent(new Event('click'));await settle();assert.equal(s.requests(),0);assert.equal(s.check.disabled,false);assert.equal(s.fields.state.value,'9');
 });
}

const input=(form,field,type='input')=>{const event=new Event(type);Object.defineProperty(event,'target',{value:field});form.dispatchEvent(event);};
for(const lang of ['en','hi']){
 test(`DOB calculates age and unknown-DOB fallback stays explicit (${lang})`,async()=>{
  const s=await setup(lang);assert.equal(s.fields.age.readOnly,true);assert.equal(s.fields.dob.required,true);s.fields.dob.value='1985-01-01';input(s.form,s.fields.dob);assert.equal(s.fields.age.value,new Date().getFullYear()-1985);s.manual.checked=true;s.manual.dispatchEvent(new Event('change'));assert.equal(s.fields.age.readOnly,false);assert.equal(s.fields.dob.required,false);assert.equal(s.fields.dob.value,'');s.fields.dob.value='1985-01-01';input(s.form,s.fields.dob);assert.equal(s.manual.checked,false);assert.equal(s.fields.age.readOnly,true);
 });
 test(`PIN fills residence after input and blur without submitting a profile (${lang})`,async()=>{
  const s=await setup(lang);s.fields.pincode.value='302001';input(s.form,s.fields.pincode);input(s.form,s.fields.pincode,'change');await settle();assert.equal(s.check.disabled,true);s.resolveRaw({reason:null,matches:[{state:'8',district:'102'}],postal_regions:[]});await settle();await settle();assert.equal(s.fields.state.value,'8');assert.equal(s.fields.district.value,'102');assert.equal(s.confirm.checked,false);assert.match(s.pinStatus.textContent,lang==='hi'?/भर दिए गए/:/filled from the PIN/);
 });
 test(`PIN ambiguity keeps residence editable without guessing (${lang})`,async()=>{
  const s=await setup(lang);s.fields.pincode.value='262001';input(s.form,s.fields.pincode);await settle();s.resolveRaw({reason:'ambiguous',matches:[{state:'9',district:'173'},{state:'9',district:'150'}],postal_regions:[]});await settle();assert.equal(s.fields.district.value,'173');assert.match(s.pinStatus.textContent,lang==='hi'?/एक से अधिक/:/more than one district/);assert.equal(s.confirm.checked,false);
 });
 test(`manual residence edits win over a pending PIN response (${lang})`,async()=>{
  const s=await setup(lang);s.fields.pincode.value='262001';input(s.form,s.fields.pincode);await settle();s.fields.state.value='8';input(s.form,s.fields.state);s.resolveRaw({reason:null,matches:[{state:'9',district:'173'}]});await settle();assert.equal(s.fields.state.value,'8');
 });
}

for(const lang of ['en','hi'])test(`a PIN entered before residence options are ready is resolved (${lang})`,async()=>{const s=await setup(lang,'262001');assert.equal(s.requests(),1);assert.equal(s.check.disabled,true);s.resolveRaw({reason:null,matches:[{state:'9',district:'173'}],postal_regions:[]});await settle();await settle();assert.equal(s.check.disabled,false);assert.equal(s.fields.district.value,'173');});

const content=node=>node.textContent+' '+node.children.map(content).join(' ');
for(const lang of ['en','hi']){
 test(`only relevant reviewed questions are shown (${lang})`,async()=>{
  const s=await setup(lang);assert.equal(s.wrappers.bank.hidden,true);s.fields.dob.value='1985-01-01';input(s.form,s.fields.dob);assert.equal(s.wrappers.bank.hidden,false);assert.equal(s.wrappers.family_income.hidden,true);for(const field of ['occupation','education','student'])assert.equal(s.wrappers[field].hidden,true);
 });
 test(`results show benefits, visible document guidance and reasons (${lang})`,async()=>{
  const s=await setup(lang);s.fields.dob.value='1985-01-01';input(s.form,s.fields.dob);Object.assign(s.fields.age,{value:'41'});s.fields.interest.value='insurance';for(const key of ['citizen','bank','autodebit'])s.fields[key].value='yes';s.confirm.checked=true;const submit=new Event('submit',{cancelable:true});s.form.dispatchEvent(submit);const text=content(s.results);assert.match(text,lang==='hi'?/तैयार रखने वाले दस्तावेज़/:/Documents to prepare/);assert.match(text,lang==='hi'?/आपके उत्तरों के आधार पर/:/Based on your answers/);assert.match(text,lang==='hi'?/क्या सहायता मिलती है/:/What it offers/);
 });
 test(`a verified education question is supported without showing unrelated occupation (${lang})`,async()=>{
  const record={id:'test-only-education',scope:'IN',status:'listed',checked_on:'2026-10-28',name:{en:'Test programme',hi:'परीक्षण योजना'},benefit:{en:'Test benefit',hi:'परीक्षण सहायता'},documents:{en:'Test documents',hi:'परीक्षण दस्तावेज़'},rules:[{field:'education',op:'in',value:['graduate','postgraduate']}]};const s=await setup(lang,'',[record]);s.fields.dob.value='1985-01-01';input(s.form,s.fields.dob);assert.equal(s.wrappers.education.hidden,false);assert.equal(s.wrappers.occupation.hidden,true);
 });
}
