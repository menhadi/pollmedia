@if(($sourceMap ?? null) === 'maps/chandigarh-regions.geojson')
@includeIf('chandigarh-electoral-context')
@endif
@if(isset($sourceMap) && str_contains($sourceMap, 'maps/delhi-'))
@includeIf('delhi-electoral-context')
@endif
@if($constituencyLinks['available'])
<section id="elections" class="panel">
<p class="eyebrow">District → constituencies</p><h2>Election results & history</h2>
<p>Open a constituency to explore its available election years, graphs and original reports. These pages use the existing election dashboard and its published updates.</p>
@if($constituencyLinks['scope_note'] ?? null)<p class="notice">{{ $constituencyLinks['scope_note'] }}</p>@endif
<div class="election-seat-tables">
@foreach(['parliamentary' => 'Lok Sabha · PC', 'assembly' => 'State Assembly · AC'] as $kind => $label)
<section><h3>{{ $label }} · {{ count($constituencyLinks[$kind]) }} constituencies</h3><div class="table-wrap"><table><thead><tr><th>Code</th><th>Constituency</th><th>Election results &amp; history</th></tr></thead><tbody>
@foreach($constituencyLinks[$kind] as $seat)<tr><td>{{ $kind === 'assembly' ? 'AC' : 'PC' }} {{ $seat['code'] }}</td><th scope="row">{{ $seat['name'] }}</th><td><a href="{{ $seat['url'] }}">View results &amp; history →</a></td></tr>
@if($kind === 'parliamentary' && count($seat['outside_district'] ?? []))<tr><td colspan="3"><small>Also includes @foreach($seat['outside_district'] as $outside){{ $outside['name'] }} (AC {{ $outside['code'] }}, {{ $outside['district'] }} district){{ $loop->last ? '.' : '; ' }}@endforeach Constituency totals are not district totals.</small></td></tr>@endif
@endforeach</tbody></table></div></section>@endforeach</div>
<details><summary>How these constituency links are established</summary><p>Links use Assembly codes in the source-backed state geography fixture, not village-name matching. Electoral mappings and Census 2011 geography have separate dates; historical constituency boundaries may differ.</p><ul>@foreach($constituencyLinks['sources'] as $source)<li><a href="{{ $source['url'] }}">{{ $source['label'] }}</a> · {{ $source['date'] }}</li>@endforeach</ul>@if($constituencyLinks['supplemental_source'] ?? null)<p><a href="{{ $constituencyLinks['supplemental_source']['url'] }}">Official district Assembly listing</a> · retrieved {{ $constituencyLinks['supplemental_source']['retrieved_at'] }}</p>@endif</details>
</section>
@endif
