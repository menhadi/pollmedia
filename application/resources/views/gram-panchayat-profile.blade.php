@php
    $hi=request('lang')==='hi' || app()->getLocale()==='hi';
    $title=$record['name'].' · '.($hi?'ग्राम पंचायत':'Gram Panchayat');
@endphp
@extends('gram-panchayat-layout', compact('hi','title'))
@section('directory-content')
<p>{{ $hi?'ग्राम पंचायत':'Gram Panchayat' }} · {{ $villages[0]['state_name'] }}</p>
<h1 translate="no">{{ $record['name'] }}</h1>
<p>{{ $hi?'पंचायत LGD कोड':'Panchayat LGD code' }} <strong>{{ $record['gp'] }}</strong></p>
<h2>{{ $hi?'सत्यापित गाँव':'Verified linked villages' }} ({{ count($villages) }})</h2>
@foreach(collect($villages)->groupBy('district') as $district => $districtVillages)
@php($params=['scope'=>'lgd','state'=>$record['state'],'district'=>$district,'lang'=>$hi?'hi':null])
<h3>{{ $hi?'जिला':'District' }} · <a href="{{ route('gram-panchayats.area',$params) }}">{{ $districtVillages->first()['district_name'] }}</a></h3>
@foreach($districtVillages->groupBy('subdistrict') as $sub => $rows)
<p>{{ $hi?'उपजिला':'Subdistrict' }} · <a href="{{ route('gram-panchayats.area',$params+['subdistrict'=>$sub]) }}">{{ $rows->first()['subdistrict_name'] }}</a></p>
<ul>@foreach($rows as $v)<li><a href="{{ route('my-place.index',['state'=>$v['state'],'district'=>$district,'village'=>$v['village'],'lang'=>$hi?'hi':null]) }}">{{ $v['name'] }}</a> <span class="gp-code">LGD {{ $v['village'] }}</span></li>@endforeach</ul>
@endforeach
@endforeach
@if($reports)
@php($t=fn($en,$hindi)=>$hi?$hindi:$en)
@include('my-place-panchayat',['hub'=>['panchayat_reports'=>$reports],'context'=>['village'=>null]])
@include('my-place-panchayat-activities',['panchayat'=>['reports'=>$reports]])
@endif
<p class="gp-source"><a href="https://lgdirectory.gov.in/downloadDirectory.do">Local Government Directory</a> · 5 November 2026</p>
@endsection
