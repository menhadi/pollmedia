@php
    $censusDistricts = json_decode(file_get_contents(database_path('fixtures/'.$region.'-published-census-districts.json')), true, 512, JSON_THROW_ON_ERROR);
    $sameName = collect($censusDistricts)->first(fn ($item) => strtoupper(trim(str_replace('&', 'and', $item['name']))) === strtoupper(trim(str_replace('&', 'and', $district['source_name']))));
    $verifiedNameContexts = collect($censusDistricts)->keyBy('code')->all();
    $sourceContextCodes = ['pondicherry'=>'635','puducherry'=>'635','mahe'=>'636','yanam'=>'634','karaikal'=>'637','lakshadweep'=>'587','lakshadweep-district'=>'587','nicobars'=>'638','north-and-middle-andaman'=>'639','south-andamans'=>'640'];
    $nameContext = $verifiedNameContexts[$sourceContextCodes[$district['slug']] ?? ''] ?? null;
    $selectedCensusCode = (string) request()->query('census', $sameName['code'] ?? $nameContext['code'] ?? '');
    $selectedCensusRow = collect($censusDistricts)->firstWhere('code', $selectedCensusCode);
    $selectedCensusData = in_array($selectedCensusCode, array_column($censusDistricts, 'code'), true)
        ? app(\App\Services\DistrictDashboardData::class)->published($selectedCensusCode, $selectedCensusRow['state']) : null;
@endphp
<section class="panel" id="selected-census-context"><h2>Published Census 2011 district context</h2><p class="notice">Census 2011 districts and source-map geography are separate editions. Puducherry retains four Census district contexts, including Mahe and Yanam. No historical totals are reassigned to current boundaries.</p>
<p>Matching names (including “and” / “&amp;” spelling variants) select a separately labelled Census context by default. Other source spellings require an explicit selection. These figures describe the selected Census geography, not the map shapes.</p>
<p>Default Census selection is a named historical context only; it does not establish matching source boundaries.</p>
<p class="notice"><strong>Current map: {{ $district['source_name'] }}.</strong> This selector changes figures and history only. Use the district selector at the top to change maps.</p>
<form method="get" action="{{ url()->current() }}#selected-census-context">
@if(request()->has('electoral'))<input type="hidden" name="electoral" value="{{ request()->query('electoral') }}">@endif
<label>Census district<select name="census"><option value="">Choose a Census 2011 district</option>@foreach($censusDistricts as $item)<option value="{{ $item['code'] }}" @selected($selectedCensusCode === $item['code'])>{{ $item['name'] }} · Census 2011</option>@endforeach</select></label><button type="submit">Show figures & history</button></form>
@if($selectedCensusData)
<h3>{{ $selectedCensusData['name'] }} · Census {{ $selectedCensusData['year'] }}</h3>
@php($censusTotals = $selectedCensusData['totals']['Total'])
@include('district-dashboard-overview', ['data' => $selectedCensusData, 'total' => $censusTotals, 'display' => fn ($value, $digits = 0) => $value === null ? 'Unavailable' : number_format($value, $digits), 'share' => fn ($value, $denominator) => $value !== null && $denominator > 0 ? 100 * $value / $denominator : null])
<h3>Census 2011 rural and urban breakdown</h3>
<div class="table-wrap"><table><thead><tr><th>Residence</th><th>Population</th><th>Households</th><th>Literate persons</th><th>Workers</th></tr></thead><tbody>
@foreach(['Rural', 'Urban'] as $residence)
@if(isset($selectedCensusData['totals'][$residence]))
<tr><th>{{ $residence }}</th>@foreach(['population', 'households', 'literates', 'workers'] as $metric)<td>{{ ($selectedCensusData['totals'][$residence][$metric] ?? null) === null ? 'Unavailable' : number_format($selectedCensusData['totals'][$residence][$metric]) }}</td>@endforeach</tr>
@endif
@endforeach
</tbody></table></div>
@foreach(['subdistricts' => 'Subdistricts', 'towns' => 'Towns'] as $unitKey => $unitLabel)
<section id="census-{{ $unitKey }}" aria-label="{{ $unitLabel }} in the selected Census district">
<h3>{{ $unitLabel }} · {{ $selectedCensusData['name'] }} · Census {{ $selectedCensusData['year'] }}</h3>
<p>{{ count($selectedCensusData[$unitKey]) }} published {{ strtolower($unitLabel) }} in this Census edition. Available town context links can highlight a named source shape. This does not establish matching boundaries or assign population to polygons.</p>
<div class="table-wrap"><table><thead><tr><th>Place / Census code</th><th>Population</th><th>Households</th><th>Literacy aged 7+</th></tr></thead><tbody>
@forelse($selectedCensusData[$unitKey] as $place)
<tr><td><a href="{{ route('civic.place', ['record' => $place['id']]) }}">{{ $place['name'] }}</a><small>{{ $place['code'] }}</small>@if($unitKey === 'towns' && isset($mapTownLinks[(string) $place['id']])) <button type="button" data-map-feature="{{ $mapTownLinks[(string) $place['id']] }}">Show on map</button>@endif</td><td>{{ $place['population'] === null ? 'Unavailable' : number_format($place['population']) }}</td><td>{{ $place['households'] === null ? 'Unavailable' : number_format($place['households']) }}</td><td>{{ $place['literacy'] === null ? 'Unavailable' : number_format($place['literacy'], 2).'%' }}</td></tr>
@empty
<tr><td colspan="4">No published {{ strtolower($unitLabel) }} records available.</td></tr>
@endforelse
</tbody></table></div>
</section>
@endforeach
<p>{{ $selectedCensusData['census_village_rows'] }} published village Census rows available in the selected edition.</p>
@foreach($selectedCensusData['census_editions'] as $edition)<p><a href="{{ $edition['source_url'] }}">{{ $edition['source_key'] }} · official source</a></p>@endforeach
<p><a href="{{ route('district-dashboard', ['state'=>$selectedCensusRow['state'],'district'=>$selectedCensusCode]) }}">Open the complete {{ $selectedCensusData['name'] }} Census dashboard</a></p>
<section id="villages" class="panel"><p class="eyebrow">Published Census records</p><h2>Village directory · {{ $selectedCensusData['year'] }}</h2><p class="notice">{{ $selectedCensusData['census_village_rows'] }} published village records are available. These are Census records, not an accepted LGD directory. Map-to-Census and LGD links remain pending; map selection does not assign population.</p><label>Find a Census village<input id="village-search" type="search" maxlength="120" placeholder="Village name or Census code"></label><p id="village-results" role="status"></p><div class="table-wrap"><table id="census-villages"><thead><tr><th>Village / Census code</th><th>Population</th><th>Households</th><th>Literacy 7+</th></tr></thead><tbody>@forelse(($selectedCensusData['census_villages']??[]) as $place)<tr><td><a href="{{ route('civic.place',['record'=>$place['id']]) }}">{{ $place['name'] }}</a><small>{{ $place['code'] }}</small></td><td>{{ ($place['population'] === null ? 'Unavailable' : number_format($place['population'])) }}</td><td>{{ ($place['households'] === null ? 'Unavailable' : number_format($place['households'])) }}</td><td>{{ ($place['literacy'] === null ? 'Unavailable' : number_format($place['literacy'], 2)) }}{{ $place['literacy']!==null?'%':'' }}</td></tr>@empty<tr data-empty><td colspan="4">Village Census records are unavailable in the selected published sources.</td></tr>@endforelse</tbody></table></div></section>
@include('district-dashboard-history', ['data'=>$selectedCensusData, 'censusSeries'=>$selectedCensusData['history']])
<script src="{{ asset('js/history-lines.js') }}" defer></script>
@else
<p role="status">No published Census district is selected. Choose a Census 2011 district above to view its separately labelled figures, directory and history.</p>
@foreach(['census-subdistricts' => 'Subdistricts', 'census-towns' => 'Towns', 'villages' => 'Village directory', 'history' => 'History & graphs'] as $sectionId => $sectionLabel)
<section id="{{ $sectionId }}"><h3>{{ $sectionLabel }}</h3><p>Unavailable until a published Census district is selected. No parent-district figures are assigned automatically.</p></section>
@endforeach
@endif
</section>
