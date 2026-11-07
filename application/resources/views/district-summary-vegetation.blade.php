@php($metric = $ndvi['summary'])
<section class="pb-panel pb-record-card" data-pb-preview="satellite"><h2>{{ $tr('Vegetation index (NDVI)','वनस्पति सूचकांक (NDVI)') }}</h2>
<p>{{ $tr('Sentinel-2 · Same mapped area on both dates','सेंटिनल-2 · दोनों तिथियों पर वही मानचित्रित क्षेत्र') }}</p>
<div class="pb-index-pair">
@foreach(['before_ndvi','after_ndvi'] as $i=>$key)
@if($i === 1)<span class="pb-index-arrow" aria-hidden="true">→</span>@endif
<div><time datetime="{{ $ndvi['dates'][$i] }}">{{ $ndvi['dates'][$i] }}</time><strong>{{ number_format($metric[$key],4) }}</strong></div>@endforeach
</div>
<p>{{ $tr('Index change','सूचकांक में बदलाव') }} <strong>{{ number_format($metric['ndvi_change'],4) }}</strong> · {{ number_format($metric['paired_coverage_pct'],2).'%' }} {{ $tr('paired coverage','दोनों तिथियों पर कवरेज') }}</p>
<a class="pb-more" href="{{ route('district-dashboard-directory',['satellite'=>$ndvi['layer_id'],'lang'=>$hi?'hi':null]) }}#satellite-index">{{ $tr('Compare satellite images','उपग्रह चित्रों की तुलना करें') }} →</a>
</section>
