@php($hi=false)
@php($title=$activityRecord['name'].' · '.$sourceRecord['name'])
@php($canonicalUrl=route('panchayat-accounts.activity',['state'=>$sourceRecord['state'],'gp'=>$sourceRecord['gp'],'activity'=>$activityRecord['record_key']]))
@extends('gram-panchayat-layout',compact('hi','title'))
@section('directory-content')
<p><a href="{{ route('panchayat-accounts.show',['state'=>$sourceRecord['state'],'gp'=>$sourceRecord['gp']]) }}">{{ $sourceRecord['name'] }} · Panchayat accounts</a></p>
<h1>{{ $activityRecord['name'] }}</h1>
<div class="national-summary-scroll"><table><thead><tr><th>Activity details</th><th>Value</th></tr></thead><tbody>
@foreach(['status'=>'Status','description'=>'Description','focus_area'=>'Focus area','theme'=>'Theme','type'=>'Type','output'=>'Output','reported_cost'=>'Reported cost','plan_type'=>'Plan type','captured_on'=>'Retrieved'] as $key=>$label)
@if(isset($activityRecord[$key]) && $activityRecord[$key]!=='')<tr><th scope="row">{{ $label }}</th><td>{{ $activityRecord[$key] }}</td></tr>@endif
@endforeach
@foreach(['astTyp'=>'Asset type','astCatNm'=>'Asset category','astSubCatNm'=>'Asset subcategory','astCvrgCd'=>'Coverage','astUntTyp'=>'Asset unit','astNumOfUnt'=>'Number of units','astUnitCost'=>'Unit cost','location'=>'Location','fundAllocation'=>'Funding allocation','trngCatCd'=>'Training category','trngOrgByCd'=>'Organised by','trngSubject'=>'Training subject','totTrainees'=>'Trainees','totDurationDays'=>'Training duration (days)','workTyp'=>'Work type','operationalRemarks'=>'Remarks'] as $key=>$label)
@php($value=$activityRecord['original'][$key] ?? null)
@if($value!==null && $value!=='' && $value!==[])<tr><th scope="row">{{ $label }}</th><td>{{ is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE):$value }}</td></tr>@endif
@endforeach
<tr><th scope="row">Reference</th><td><a href="{{ $activityRecord['source_url'] }}" target="_blank" rel="noopener noreferrer">Official activity report ↗</a></td></tr>
</tbody></table></div>
@foreach($activityRecord['notes'] ?? [] as $note)<p class="place-note">{{ $note }}</p>@endforeach
<details class="gp-methodology"><summary>Methodology & sources</summary><p>Reported activity cost retains the original source value; the source does not state its unit. Activity budgets are separate from payments.</p>@if(!empty($activityRecord['plan_membership_url']))<a href="{{ $activityRecord['plan_membership_url'] }}" target="_blank" rel="noopener noreferrer">Official plan membership ↗</a>@endif</details>
@endsection
