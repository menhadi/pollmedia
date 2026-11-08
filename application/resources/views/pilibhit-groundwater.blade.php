<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $pilot['district'] ?? 'Pilibhit' }} groundwater monitoring pilot · Pollmedia</title><meta name="robots" content="noindex">
<link rel="stylesheet" href="{{ asset('css/pilibhit-groundwater.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/maplibre/maplibre-gl.css') }}">
<script src="{{ asset('vendor/maplibre/maplibre-gl.js') }}" defer></script>
<script src="{{ asset('js/pilibhit-groundwater.js') }}" defer></script>@include('site-theme')</head>
<body>@include('public-header',['summaryLayout'=>true])<main>
<nav><a href="{{ url('/india/districts/uttar-pradesh/pilibhit') }}">Pilibhit district</a> / <a href="{{ route('india-map.index') }}">India map</a></nav>
<p class="eyebrow">Pollmedia · Historical monitoring pilot</p><h1>{{ $pilot['district'] ?? 'Pilibhit' }} groundwater</h1>
<p class="intro">Explore official monitoring records, their locations and the chemistry that was actually measured.</p>
<p class="notice"><strong>Historical evidence, not a current drinking-water assessment.</strong> Observation dates are shown below. Monitoring wells are not verified household supply sources. No water safety classification is assigned.</p>
<section class="cards" aria-label="Dataset coverage">
<article><strong>{{ number_format($pilot['summary']['level_observations']) }}</strong><span>Level observations</span></article>
<article><strong>{{ number_format($pilot['summary']['chemistry_observations']) }}</strong><span>Chemistry observations</span></article>
<article><strong>{{ $pilot['summary']['monitoring_series'] }}</strong><span>Monitoring series</span></article>
<article><strong>{{ $pilot['summary']['linked_villages'] ?? 0 }}</strong><span>Supported village monitoring links</span></article>
</section>
<p id="pilot-dates"></p>
<section aria-labelledby="explore-title"><h2 id="explore-title">Explore monitoring locations</h2>
<p>{{ $pilot['summary']['source_locality_names'] }} distinct locality labels occur in the source, including unspecified labels. This is not coverage of all {{ number_format($pilot['summary']['district_directory_villages']) }} villages in the stored district directory.</p>
<div class="filters"><label>Record type<select id="groundwater-kind"><option value="">Levels and chemistry</option><option value="level">Groundwater levels</option><option value="chemistry">Groundwater chemistry</option></select></label>
<label>Find a station or locality<input id="groundwater-search" type="search" placeholder="For example, Amaria or Puranpur"></label>
<label>Monitoring series<select id="groundwater-station"></select></label></div>
<p id="station-count" role="status" aria-live="polite"></p><div id="groundwater-map" aria-label="Monitoring locations map"></div>
<p class="muted">Points show source coordinates. Selecting a point opens its record. Map proximity does not establish a shared aquifer or a village drinking-water supply link.</p>
</section>
@if(isset($waterContext))
<p class="notice">The three water evidence sections below follow {{ isset($waterContext['village']) ? 'selected village LGD '.$waterContext['village'] : 'this district' }}. The district explorer above retains the full monitoring inventory.</p>
@include('water-evidence-panel',['waterState'=>$waterContext['state'],'waterDistrict'=>$waterContext['district'],'waterVillage'=>$waterContext['village']??null])
@endif
<section id="station-detail" aria-labelledby="station-title"><h2 id="station-title"></h2><p id="station-meta"></p><p id="station-link" class="notice"></p>
<label>Measurement<select id="groundwater-parameter"></select></label>
<p id="measurement-note"></p><div id="groundwater-chart"></div><p id="observation-count" role="status"></p>
<div class="table-wrap"><table><caption>Selected measurement history</caption><thead><tr><th>Source observation time</th><th>Measurement</th><th>Source row</th><th>Original CSV</th></tr></thead><tbody id="groundwater-observations"></tbody></table></div>
</section>
<section><h2>Drinking-water quality</h2><p>{{ $pilot['drinking_water_quality']['note'] }}</p>
<p>CGWB chemistry is displayed in the original measurement units. Missing tests are unavailable, not zero. A chemical sample does not establish microbiological safety, continuous service or present-day household water quality.</p>
<a href="https://ejalshakti.gov.in/WQMIS/Main/report" target="_blank" rel="noopener">Official WQMIS testing and remedial-action reports ↗</a></section>
<section><h2>Sources and method</h2><p>Publisher: Central Ground Water Board. Download service: National Water Data Portal, National Water Informatics Centre, Ministry of Jal Shakti.</p>
<p>Rows must match LGD state 9, district 173 and the source district name Pilibhit. The original files are preserved with SHA-256 fingerprints. Snapshot built {{ substr($pilot['built_at'],0,10) }}; this is not the observation date.</p>
<ul>@foreach($pilot['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul>
<div class="table-wrap"><table><caption>Downloaded source files</caption><thead><tr><th>Dataset</th><th>UP rows</th><th>Pilibhit rows</th><th>File fingerprint</th></tr></thead><tbody>
@foreach($pilot['sources'] as $source)<tr><td><a href="{{ $source['url'] }}" target="_blank" rel="noopener">{{ str_replace('_',' ',$source['id']) }}</a></td><td>{{ number_format($source['total_rows']) }}</td><td>{{ $source['pilibhit_rows'] }}</td><td><code>{{ $source['sha256'] }}</code></td></tr>@endforeach
</tbody></table></div>
<p>Multiple rows at the same series and time are preserved for review: {{ count($pilot['duplicate_audit']) }} groups. No conflicting row is silently chosen.</p>
</section><footer>Pollmedia · Source observations remain distinct from estimates and advice.</footer>
<script id="groundwater-data" type="application/json">{!! json_encode($pilot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
</main></body></html>
