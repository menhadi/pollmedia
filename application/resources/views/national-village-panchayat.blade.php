<link rel="stylesheet" href="{{ asset('css/gram-panchayat-consistent.css') }}?v={{ substr(hash_file('sha256',public_path('css/gram-panchayat-consistent.css')),0,12) }}">
@php
$accountsState=(int)($accountVillage['state']??0);
$accountsVillage=(int)($accountVillage['lgd']??0);
$accountDirectory=app(\App\Services\GramPanchayatDirectory::class);
$accountMembership=$accountDirectory->forVillage($accountsState,$accountsVillage);
$accountSummary=app(\App\Services\NationalPanchayatSummaries::class)->forVillage($accountsState,$accountsVillage);
$t=fn($en,$hi)=>app()->getLocale()==='hi'?$hi:$en;
$accountOwn=[];
if($accountMembership){
    $accountProfile=$accountDirectory->profile($accountsState,(int)$accountMembership['gp']);
    $accountMember=collect($accountProfile['villages'])->first(fn($row)=>(string)$row['village']===(string)$accountsVillage);
    if($accountMember){$accountOwn=app(\App\Services\MyPlacePanchayat::class)->profileRecords($accountProfile['record'],[(string)$accountMember['district']]);}
}
@endphp
<section class="panel" id="village-panchayat-accounts"><h2>Gram Panchayat accounts</h2>
@if($accountMembership)
<p><a href="{{ app(\App\Services\GramPanchayatUrls::class)->link($accountsState,$accountMembership['name'],$accountMembership['gp']) }}">{{ $accountMembership['name'] }} →</a></p>
@if(!empty($accountOwn['reports']))
@include('my-place-panchayat',['hub'=>['panchayat_reports'=>$accountOwn['reports']],'context'=>['village'=>['code'=>(string)$accountsVillage]]])
@include('my-place-panchayat-activities',['panchayat'=>['reports'=>$accountOwn['reports']]])
@endif
@if($accountSummary && !collect($accountOwn['reports']??[])->contains(fn($report)=>$report['financial_year']===$accountSummary['summary']['financial_year'] && !empty($report['months'])))
@include('national-panchayat-monthly',['nationalSummary'=>$accountSummary])
@elseif(empty($accountOwn['reports']))
<p class="place-note">Source records with unconfirmed correspondence remain on the Panchayat accounts page.</p>
@endif
@else<p class="place-note">A unique Panchayat membership is not available for this village.</p>@endif
</section>
