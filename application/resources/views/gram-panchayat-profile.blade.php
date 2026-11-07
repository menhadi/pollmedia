@php
    $hi=request('lang')==='hi' || app()->getLocale()==='hi';
    $t=fn($en,$hindi)=>$hi?$hindi:$en;
    $title=$record['name'].' · '.$t('Gram Panchayat','ग्राम पंचायत');
    $districts=collect($villages)->groupBy('district');
    $profileParams=['state'=>$record['state'],'gp'=>$record['gp'],'lang'=>$hi?'hi':null];
@endphp
@extends('gram-panchayat-layout',compact('hi','title','record'))
@section('directory-content')
<div class="village-breadcrumb-bar">
    @include('location-breadcrumbs',['locationTrail'=>[
        ['label'=>$villages[0]['state_name'],'url'=>null],
        ['label'=>$member['district_name'],'url'=>route('gram-panchayats.area',['scope'=>'lgd','state'=>$record['state'],'district'=>$member['district'],'lang'=>$hi?'hi':null])],
        ['label'=>$record['name'],'url'=>null]
    ]])
</div>
<p class="eyebrow">{{ $t('Gram Panchayat','ग्राम पंचायत') }}</p>
<h1 class="village-heading" translate="no">{{ $record['name'] }}</h1>
<nav class="gp-section-nav" aria-label="{{ $t('Page sections','पृष्ठ के अनुभाग') }}">
    <a href="#panchayat-overview">{{ $t('Overview','सारांश') }}</a>
    <a href="#member-villages">{{ $t('Linked villages','संबंधित गाँव') }}</a>
    <a href="#panchayat-records">{{ $t('Panchayat records','पंचायत रिकॉर्ड') }}</a>
    <a href="#linked-village-data">{{ $t('Village data','गाँव के आँकड़े') }}</a>
</nav>
<section class="metrics" id="panchayat-overview" aria-label="{{ $t('Panchayat overview','पंचायत सारांश') }}">
    <article><span>{{ $t('Panchayat LGD code','पंचायत LGD कोड') }}</span><strong>{{ $record['gp'] }}</strong></article>
    <article><span>{{ $t('Verified linked villages','सत्यापित गाँव') }}</span><strong>{{ number_format(count($villages)) }}</strong></article>
    <article><span>{{ $t('Subdistricts','उपजिले') }}</span><strong>{{ collect($villages)->unique('subdistrict')->count() }}</strong></article>
</section>
<section class="panel" id="member-villages"><h2>{{ $t('Linked villages','संबंधित गाँव') }}</h2>
<div class="table-wrap"><table><thead><tr><th>{{ $t('Village','गाँव') }}</th><th>LGD</th><th>{{ $t('Subdistrict','उपजिला') }}</th><th>{{ $t('District','जिला') }}</th></tr></thead><tbody>
@foreach($villages as $v)
@php($area=['scope'=>'lgd','state'=>$record['state'],'district'=>$v['district'],'lang'=>$hi?'hi':null])
<tr><th scope="row"><a href="{{ route('gram-panchayats.show',$profileParams+['village'=>$v['village']]) }}#linked-village-data">{{ $v['name'] }}</a></th>
<td>{{ $v['village'] }}</td><td><a href="{{ route('gram-panchayats.area',$area+['subdistrict'=>$v['subdistrict']]) }}">{{ $v['subdistrict_name'] }}</a></td>
<td><a href="{{ route('gram-panchayats.area',$area) }}">{{ $v['district_name'] }}</a></td></tr>
@endforeach
</tbody></table></div><p><a href="https://lgdirectory.gov.in/downloadDirectory.do">Local Government Directory</a> · 5 November 2026</p>
</section>
<section class="panel" id="panchayat-records"><h2>{{ $t('Panchayat records','पंचायत रिकॉर्ड') }}</h2>
@if($reports)
@include('my-place-panchayat',['hub'=>['panchayat_reports'=>$reports],'context'=>['village'=>null]])
@include('my-place-panchayat-activities',['panchayat'=>['reports'=>$reports]])
@else
<p>{{ $t('Panchayat accounts and activity reports are awaiting publication for this Panchayat.','इस पंचायत के खाते और गतिविधि रिपोर्ट प्रकाशित होना बाकी है।') }}</p>
@endif
</section>
<section id="linked-village-data" class="gp-member-section">
    <div class="panel gp-member-heading"><p class="eyebrow">{{ $t('Linked village data','संबंधित गाँव के आँकड़े') }}</p>
    <h2 translate="no">{{ $member['name'] }}</h2>
    <p>{{ $t('The records below describe this village.','नीचे दिए गए रिकॉर्ड इस गाँव के हैं।') }} LGD {{ $member['village'] }}</p>
    @if(count($villages)>1)
    <form method="get" action="{{ route('gram-panchayats.show',['state'=>$record['state'],'gp'=>$record['gp']]) }}#linked-village-data">
        @if($hi)<input type="hidden" name="lang" value="hi">@endif
        <label>{{ $t('Village','गाँव') }} <select name="village">@foreach($villages as $v)<option value="{{ $v['village'] }}" @selected($member['village']===$v['village'])>{{ $v['name'] }} · {{ $v['village'] }}</option>@endforeach</select></label>
        <button>{{ $t('Show village records','गाँव के रिकॉर्ड देखें') }}</button>
    </form>
    @endif
    @if($village)
    <a href="{{ app(\App\Services\NamedPlaceUrls::class)->village($village) }}{{ $hi?'?lang=hi':'' }}">{{ $t('Open village dashboard','गाँव का डैशबोर्ड खोलें') }} →</a>
    @endif
    </div>
    @if($village)
        @include('lgd-village-dashboard-records')
    @else
        <p>{{ $t('A village dashboard is awaiting a verified directory match.','गाँव के डैशबोर्ड के लिए निर्देशिका सत्यापन बाकी है।') }}</p>
    @endif
</section>
@endsection
