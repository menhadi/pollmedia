@php($water = app(\App\Services\JjmWaterSnapshot::class)->pilibhit())
<link rel="stylesheet" href="{{ asset('css/jjm-water.css') }}">
<section id="water" class="jjm-water panel" aria-labelledby="water-title">
<p class="water-eyebrow">Water · Jal Jeevan Mission</p><h2 id="water-title">Pilibhit household tap connections</h2>
<p>Official JJM report snapshot retrieved {{ substr($water['retrieved_at'], 0, 10) }}. </p>
<div class="water-cards">
<article><strong>{{ number_format($water['summary']['villages']) }}</strong><span>JJM village records</span></article>
<article><strong>{{ number_format($water['summary']['households']) }}</strong><span>Households in this JJM report</span></article>
<article><strong>{{ number_format($water['summary']['tap_connections']) }}</strong><span>Reported household tap connections</span></article>
<article><strong>{{ number_format(100 * $water['summary']['tap_connections'] / $water['summary']['households'], 2) }}%</strong><span>Reported connection coverage</span></article>
</div>
<p><a href="{{ $water['source_url'] }}" target="_blank" rel="noopener">Official JJM Pilibhit village report ↗</a> · <a href="#map">Return to district map ↑</a></p>
<details><summary>Browse the {{ number_format(count($water['rows'])) }} official village records</summary>
<div class="water-filters"><label>JJM block<select id="water-block"><option value="">All blocks</option>@foreach(collect($water['rows'])->pluck('block')->unique()->sort() as $block)<option>{{ $block }}</option>@endforeach</select></label>
<label>Village name or JJM ID<input type="search" id="water-search" placeholder="Search report records"></label>
<label>Minimum coverage (%)<input type="number" id="water-min" min="0" max="100" placeholder="Any"></label><label>Maximum coverage (%)<input type="number" id="water-max" min="0" max="100" placeholder="Any"></label></div>
<p id="water-results" role="status"></p><div class="water-table"><table><thead><tr><th>Village · JJM ID</th><th>Block / panchayat</th><th>Households</th><th>Tap connections</th><th>Coverage</th><th>Source</th></tr></thead><tbody>
@foreach($water['rows'] as $row)<tr data-water-row data-block="{{ $row['block'] }}" data-name="{{ $row['name'] }} {{ $row['jjm_village_id'] }}" data-coverage="{{ $row['coverage_pct'] }}"><td>{{ $row['name'] }}<small>JJM {{ $row['jjm_village_id'] }}</small></td><td>{{ $row['block'] }}<small>{{ $row['panchayat'] }}</small></td><td>{{ number_format($row['households']) }}</td><td>{{ number_format($row['tap_connections']) }}</td><td>{{ $row['coverage_pct'] === null ? 'Unavailable' : number_format($row['coverage_pct'], 2).'%' }}</td><td><a href="{{ $row['source_url'] }}" target="_blank" rel="noopener">Official profile ↗</a><small>Report row {{ $row['source_row'] }}</small></td></tr>@endforeach
</tbody></table></div></details>
<details><summary>Source, dates and original identifiers</summary><p>{{ $water['publisher'] }} · Report {{ $water['report'] }}.</p><p>JJM state 31 / district 477 · LGD state 9 / district 173 · Census 2011 state 09 / district 151. These identifier systems remain separate.</p><p>Village JJM IDs are retained unchanged. A separate dated B37(A) crosswalk connects water records by exact LGD IDs.</p><p>Retrieved {{ $water['retrieved_at'] }}. {{ $water['observation_date_note'] }}</p><p>Source release {{ $water['source_release_id'] }} · Original report SHA-256: <code>{{ $water['original_sha256'] }}</code>. This dated snapshot is preserved for future comparisons; it does not replace Census household data.</p></details>
@include('jjm-water-disclaimer')
</section>
<script type="application/json" id="polygon-water-values">@json((object)app(\App\Services\JjmWaterSnapshot::class)->polygonValues('maps/pilibhit-district-pilot.json'))</script>
<script src="{{ asset('js/jjm-water.js').'?v='.filemtime(public_path('js/jjm-water.js')) }}" defer></script>