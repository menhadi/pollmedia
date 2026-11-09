<header class="hub-hero">
<div class="hub-intro"><h1>{{ $title }}</h1>
<div class="hub-lead-number">{{ number_format(array_sum($nationalCounts)) }}</div>
<p>{{ $tr('Constituency records','निर्वाचन क्षेत्र रिकॉर्ड') }}</p>
<div class="hub-actions"><a href="#national-records">{{ $tr('Find a constituency','निर्वाचन क्षेत्र खोजें') }} ↓</a><a href="#election-states">{{ $tr('State election results','राज्य चुनाव परिणाम') }} ↓</a></div></div>
<section class="hub-chart"><h2>{{ $tr('Records by election type','चुनाव प्रकार के अनुसार रिकॉर्ड') }}</h2>
<ul class="hub-bars">@foreach($labels as $key=>$label)
@php($count=$nationalCounts[$key]??0)<li><a data-record-link href="{{ route('elections.all-india',['type'=>$key]+$lang) }}#national-records"><span>{{ $labels[$key] }}</span><strong>{{ number_format($count) }}</strong></a><div><i style="width:{{ 100*$count/max([1,...array_values($nationalCounts)]) }}%"></i></div></li>@endforeach</ul>
</section>
</header>
