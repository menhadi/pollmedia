@php
    $history = collect($data['history']['rows'] ?? [])->filter(fn($row) => is_numeric($row['population'] ?? null))->sortBy('year')->values();
    $units = ['Quintal'=>['quintal','क्विंटल'],'Kilogram'=>['kg','किलो'],'Nos'=>['item','नग'],'Bundle'=>['bundle','गट्ठर']];
@endphp
@if($history->count()>=2)
<section class="pb-panel pb-wide pb-trend" data-pb-preview="history">
<h2>{{ $tr('Population over time','समय के साथ जनसंख्या') }}</h2>
@if($history->count() >= 2)
@php
    $first=$history->first(); $last=$history->last(); $maxPopulation=max(1,$history->max('population'));
@endphp
<p class="pb-sub">{{ $tr('Census of India · District population on 2011 boundaries','भारत की जनगणना · 2011 की ज़िला सीमाओं के अनुसार जनसंख्या') }}</p>
<p class="pb-trend-values"><strong>{{ $fmt($first['population']) }}</strong> ({{ $first['year'] }}) <span aria-hidden="true">→</span> <strong>{{ $fmt($last['population']) }}</strong> ({{ $last['year'] }})</p>
<div class="pb-decades" role="img" aria-label="{{ $tr('Population at each Census. Exact figures are available below.','प्रत्येक जनगणना की जनसंख्या। सटीक आँकड़े नीचे उपलब्ध हैं।') }}">
@foreach($history as $point)
<div class="pb-decade" title="{{ $point['year'] }} · {{ $fmt($point['population']) }}"><div><i style="height:{{ 100*$point['population']/$maxPopulation }}%"></i></div><small>{{ $point['year'] }}</small></div>
@endforeach
</div>
<details class="pb-extra"><summary>{{ $tr('See figures by year','वर्षवार आँकड़े देखें') }}</summary><table><thead><tr><th>{{ $tr('Census year','जनगणना वर्ष') }}</th><th>{{ $tr('Residents','निवासी') }}</th></tr></thead><tbody>
@foreach($history as $point)<tr><th scope="row">{{ $point['year'] }}</th><td>{{ $fmt($point['population']) }}</td></tr>@endforeach
</tbody></table></details>
@endif
<a class="pb-more" href="{{ $url('history') }}">{{ $tr('Explore population history','जनसंख्या का इतिहास देखें') }} →</a>
</section>
@endif
@if($previews['offices'])
<div class="pb-record-grid pb-anchor-pair">

@if($previews['offices'])
<section class="pb-panel pb-record-card" data-pb-preview="authorities"><h2>{{ $tr('District authorities','ज़िला अधिकारी') }}</h2>
<ul class="pb-offices">@forelse($previews['offices'] as $office)<li><a href="{{ $office['url'] }}">{{ $office['title'] }}</a>
@if($office['holder'])<span>{{ $office['holder'] }}</span>@if($office['recorded'])<small>{{ $tr('Last recorded','अंतिम दर्ज तिथि') }} {{ $office['recorded'] }}</small>@endif @endif</li>
@empty<li>{{ $tr('Office listings are unavailable.','कार्यालय सूची उपलब्ध नहीं है।') }}</li>@endforelse</ul>
<a class="pb-more" href="{{ $url('authorities') }}">{{ $tr('Open the authority directory','अधिकारी निर्देशिका खोलें') }} →</a>
</section>
@endif
</div>
@endif
@if(collect($previews['prices'])->isNotEmpty())
<section class="pb-panel pb-wide pb-market-board" data-pb-preview="markets"><h2>{{ $tr('Commodity prices','मंडी भाव') }}</h2>
<p class="pb-sub">{{ $tr('One reported modal price per commodity, with its mandi and date.','प्रत्येक जिंस का एक प्रचलित भाव, मंडी और रिपोर्ट की तारीख।') }}</p>
<table><thead><tr><th scope="col">{{ $tr('Commodity','जिंस') }}</th><th scope="col">{{ $tr('Market','मंडी') }}</th><th scope="col">{{ $tr('Modal price','प्रचलित भाव') }}</th><th scope="col">{{ $tr('Reported','रिपोर्ट तिथि') }}</th></tr></thead><tbody>
@foreach($previews['prices'] as $row)<tr>
<th scope="row">{{ $hi ? ($row['commodity_hi'] ?: $row['commodity']) : $row['commodity'] }}<small>{{ collect([$row['variety'],$row['grade']])->filter()->implode(' · ') }}</small></th>
<td>{{ $row['market'] }}</td><td><strong>₹{{ $fmt($row['modal']) }}</strong><small>/ {{ $units[$row['unit']][$hi?1:0] ?? $row['unit'] }}</small></td><td><time datetime="{{ $row['date'] }}">{{ $row['date'] }}</time></td></tr>@endforeach
</tbody></table>
<a class="pb-more" href="{{ $url('markets') }}">{{ $tr('Explore market prices and trends','सभी मंडी भाव और रुझान देखें') }} →</a>
</section>
@endif
