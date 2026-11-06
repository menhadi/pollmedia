export function ageFromDate(value, today = new Date()) {
    if(!/^\d{4}-\d{2}-\d{2}$/.test(value))return null;
    const [year,month,day]=value.split('-').map(Number), date=new Date(year,month-1,day);
    if(date.getFullYear()!==year||date.getMonth()!==month-1||date.getDate()!==day||date>today)return null;
    let age=today.getFullYear()-year;
    if(today.getMonth()<month-1||(today.getMonth()===month-1&&today.getDate()<day))age--;
    return age>=0&&age<=120?age:null;
}
export function checkScheme(scheme, profile, today = new Date()) {
    const missing=[],failed=[],passed=[],unverified=[];
    const numeric=value=>(typeof value==='number'||typeof value==='string'&&value.trim()!=='')&&Number.isFinite(Number(value));
    const finish=status=>({status,missing:[...new Set(missing)],failed,passed,unverified});
    const day=[today.getFullYear(),String(today.getMonth()+1).padStart(2,'0'),String(today.getDate()).padStart(2,'0')].join('-');
    const validDate=value=>{if(!/^\d{4}-\d{2}-\d{2}$/.test(value))return false;const date=new Date(value+'T00:00:00Z');return Number.isFinite(date.getTime())&&date.toISOString().slice(0,10)===value;};
    for(const key of ['valid_from','valid_until','checked_on'])if(scheme[key]&&!validDate(scheme[key])){unverified.push('invalid_dates');return finish('needs_verification');}
    if(['closed','inactive'].includes(scheme.status)||(scheme.valid_from&&scheme.valid_from>day)||(scheme.valid_until&&scheme.valid_until<day))return finish('unavailable');
    if(scheme.rules_verified===false||scheme.status==='unknown'||!Array.isArray(scheme.rules)||!scheme.rules.length){unverified.push('unverified_rules');return finish('needs_verification');}
    if(scheme.checked_on&&(scheme.checked_on>day||(Date.parse(day)-Date.parse(scheme.checked_on))/86400000>90)){unverified.push('stale_rules');return finish('needs_verification');}
    const rules=[...scheme.rules];
    if(scheme.scope&&scheme.scope!=='IN'&&!rules.some(r=>r.field==='state'&&r.op==='eq'&&String(r.value)===String(scheme.scope)))rules.unshift({field:'state',op:'eq',value:scheme.scope});
    for(const rule of rules){
        if(!rule||!rule.field||!['eq','gte','lte','income_limit','in','not_in'].includes(rule.op)||(rule.value===undefined||rule.value===null)){unverified.push('unsupported_rule');continue;}
        if(['gte','lte'].includes(rule.op)&&!numeric(rule.value)){unverified.push('invalid_rule_value');continue;}
        if(['in','not_in'].includes(rule.op)&&(!Array.isArray(rule.value)||!rule.value.length)){unverified.push('invalid_rule_value');continue;}
        if(rule.op==='income_limit'&&(!rule.value||typeof rule.value!=='object'||Array.isArray(rule.value))){unverified.push('invalid_rule_value');continue;}
        const value=profile[rule.field];
        if(rule.op==='income_limit'&&!(profile.residence_type in rule.value)){missing.push('residence_type');continue;}
        if(value===undefined||value===null||value===''){missing.push(rule.field);continue;}
        if(['gte','lte','income_limit'].includes(rule.op)&&!numeric(value)){missing.push(rule.field);continue;}
        let ok=false;
        if(rule.op==='eq')ok=String(value)===String(rule.value);
        else if(rule.op==='gte')ok=Number(value)>=Number(rule.value);
        else if(rule.op==='lte')ok=Number(value)<=Number(rule.value);
        else if(rule.op==='in')ok=rule.value.map(String).includes(String(value));
        else if(rule.op==='not_in')ok=!rule.value.map(String).includes(String(value));
        else if(rule.op==='income_limit'){
            if(!numeric(rule.value[profile.residence_type])){unverified.push('invalid_rule_value');continue;}
            ok=Number(value)>=0&&Number(value)<=Number(rule.value[profile.residence_type]);
        }
        (ok?passed:failed).push(rule);
    }
    return finish(failed.length?'not_matched':unverified.length?'needs_verification':missing.length?'needs_information':'potential');
}
