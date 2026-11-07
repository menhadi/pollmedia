@php
    $hi=request('lang')==='hi' || app()->getLocale()==='hi';
    $title=$hi?'ग्राम पंचायत निर्देशिका':'Gram Panchayat directory';
    $params=array_filter(['scope'=>$scope,'state'=>$state,'district'=>$district,'subdistrict'=>$subdistrict,'lang'=>$hi?'hi':null]);
@endphp
@extends('gram-panchayat-layout',compact('hi','title'))
@section('directory-content')
<h1>{{ $title }}</h1>
<p>{{ $areaName ?? (($hi?'जनगणना 2011 जिला':'Census 2011 district').' '.$district.($subdistrict?' · '.($hi?'उपजिला':'subdistrict').' '.$subdistrict:'')) }}</p>
@if($subdistricts)
<details><summary>{{ $hi?'उपजिले चुनें':'Browse by subdistrict' }}</summary><ul class="gp-list">
@foreach($subdistricts as $sub)<li><a href="{{ route('gram-panchayats.area',array_merge($params,['subdistrict'=>$sub['subdistrict']])) }}">{{ $sub['subdistrict_name'] }}</a></li>@endforeach
</ul></details>
@endif
<form method="get">
@if($hi)<input type="hidden" name="lang" value="hi">@endif
<label>{{ $hi?'पंचायत का नाम या LGD कोड':'Panchayat name or LGD code' }}<input type="search" name="q" maxlength="120" value="{{ $q }}"></label>
<button>{{ $hi?'खोजें':'Search' }}</button>
</form>
@if($listing['items'])
<ul class="gp-list">@foreach($listing['items'] as $gp)
<li><a href="{{ route('gram-panchayats.show',['state'=>$gp['state'],'gp'=>$gp['gp'],'lang'=>$hi?'hi':null]) }}">{{ $gp['name'] }}</a> <span class="gp-code">{{ $gp['gp'] }}</span></li>
@endforeach</ul>
@else
<p>{{ $hi?'इस चयन के लिए कोई सत्यापित पंचायत नहीं मिली।':'No verified Gram Panchayats found for this selection.' }}</p>
@endif
<nav>@if($page>1)<a href="{{ route('gram-panchayats.area',$params+['q'=>$q,'page'=>$page-1]) }}">{{ $hi?'पिछला पृष्ठ':'Previous page' }}</a>@endif
@if($listing['more'])<a href="{{ route('gram-panchayats.area',$params+['q'=>$q,'page'=>$page+1]) }}">{{ $hi?'अगला पृष्ठ':'Next page' }}</a>@endif</nav>
@endsection
