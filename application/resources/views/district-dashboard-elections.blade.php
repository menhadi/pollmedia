@if($constituencyLinks['available'])
<section id="elections" class="panel">
<p class="eyebrow">District → constituencies</p><h2>Election results & history</h2>
<p>Open a constituency to explore its available election years, graphs and original reports. These pages use the existing election dashboard and its published updates.</p>
<div class="source-grid"><article><h3>State Assembly · {{ count($constituencyLinks['assembly']) }} constituencies</h3><nav aria-label="District Assembly constituencies">
@foreach($constituencyLinks['assembly'] as $seat)<p><a href="{{ $seat['url'] }}"><strong>{{ $seat['name'] }}</strong> · AC {{ $seat['code'] }} →</a></p>@endforeach
</nav></article><article><h3>Lok Sabha</h3>
@foreach($constituencyLinks['parliamentary'] as $seat)<p><a href="{{ $seat['url'] }}"><strong>{{ $seat['name'] }}</strong> · PC {{ $seat['code'] }} →</a></p>
@if(count($seat['outside_district']))<p class="notice">This parliamentary constituency also includes @foreach($seat['outside_district'] as $outside){{ $outside['name'] }} (AC {{ $outside['code'] }}, {{ $outside['district'] }} district){{ $loop->last ? '.' : '; ' }}@endforeach Its totals are not district totals.</p>@endif
@endforeach</article></div>
<details><summary>How these constituency links are established</summary><p>Links use Assembly codes in the source-backed Uttar Pradesh geography fixture, not village-name matching. Electoral mappings and Census 2011 geography have separate dates; historical constituency boundaries may differ.</p><ul>@foreach($constituencyLinks['sources'] as $source)<li><a href="{{ $source['url'] }}">{{ $source['label'] }}</a> · {{ $source['date'] }}</li>@endforeach</ul></details>
</section>
@endif
