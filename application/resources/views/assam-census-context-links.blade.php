@php
    $censusNameCodes = [
        'KOKRAJHAR' => '300',
        'DHUBRI' => '301',
        'GOALPARA' => '302',
        'BARPETA' => '303',
        'MORIGAON' => '304',
        'NAGAON' => '305',
        'SONITPUR' => '306',
        'LAKHIMPUR' => '307',
        'DHEMAJI' => '308',
        'TINSUKIA' => '309',
        'DIBRUGARH' => '310',
        'SIVASAGAR' => '311',
        'JORHAT' => '312',
        'GOLAGHAT' => '313',
        'KARBI ANGLONG' => '314',
        'DIMA HASAO' => '315',
        'CACHAR' => '316',
        'KARIMGANJ' => '317',
        'HAILAKANDI' => '318',
        'BONGAIGAON' => '319',
        'CHIRANG' => '320',
        'KAMRUP' => '321',
        'KAMRUP METROPOLITAN' => '322',
        'NALBARI' => '323',
        'BAKSA' => '324',
        'DARRANG' => '325',
        'UDALGURI' => '326',
    ];
    $censusName = strtoupper(trim($district['source_name']));
    $censusNameReferences = ['sribhumi' => 'KARIMGANJ', 'sibsagar' => 'SIVASAGAR', 'marigaon' => 'MORIGAON', 'kamrup-metro' => 'KAMRUP METROPOLITAN'];
    $censusName = $censusNameReferences[$district['slug']] ?? $censusName;
    $defaultCensusCode = $censusNameCodes[$censusName] ?? '';
    $selectedCensusCode = (string) request()->query('census', $defaultCensusCode);
    $allowedCensusCodes = array_map('strval', range(300, 326));
    $selectedCensusData = in_array($selectedCensusCode, $allowedCensusCodes, true)
        ? app(\App\Services\DistrictDashboardData::class)->published($selectedCensusCode, '18')
        : null;
@endphp
<section class="panel" id="selected-census-context"><h2>Published Census 2011 district context</h2><p>Published figures and historical charts for the same-named Census 2011 district appear automatically where available. Documented name references are used for Sribhumi/Karimganj, Sibsagar/Sivasagar, Marigaon/Morigaon and Kamrup Metro/Metropolitan. You can select another Census district for comparison. These figures describe the selected Census geography; they are not totals for the newer outline, village shapes or 2023 constituencies.</p>
<p class="notice"><strong>Current map: {{ $district['source_name'] }}.</strong> Choosing a Census district below changes figures and history only; it keeps this map. <a href="#district-picker">Change the map district at the top of the page</a>.</p>
@if($defaultCensusCode === '')
<p class="notice">No same-named Census 2011 district is available for this source map. A parent district’s population is not assigned to it. Choose a Census district below for separately labelled historical context.</p>
@endif
<form method="get" action="{{ url()->current() }}#selected-census-context"><label>Census district<select name="census"><option value="">Choose a Census 2011 district</option><option value="300" @selected($selectedCensusCode === '300')>Kokrajhar · Census 2011</option><option value="301" @selected($selectedCensusCode === '301')>Dhubri · Census 2011</option><option value="302" @selected($selectedCensusCode === '302')>Goalpara · Census 2011</option><option value="303" @selected($selectedCensusCode === '303')>Barpeta · Census 2011</option><option value="304" @selected($selectedCensusCode === '304')>Morigaon · Census 2011</option><option value="305" @selected($selectedCensusCode === '305')>Nagaon · Census 2011</option><option value="306" @selected($selectedCensusCode === '306')>Sonitpur · Census 2011</option><option value="307" @selected($selectedCensusCode === '307')>Lakhimpur · Census 2011</option><option value="308" @selected($selectedCensusCode === '308')>Dhemaji · Census 2011</option><option value="309" @selected($selectedCensusCode === '309')>Tinsukia · Census 2011</option><option value="310" @selected($selectedCensusCode === '310')>Dibrugarh · Census 2011</option><option value="311" @selected($selectedCensusCode === '311')>Sivasagar · Census 2011</option><option value="312" @selected($selectedCensusCode === '312')>Jorhat · Census 2011</option><option value="313" @selected($selectedCensusCode === '313')>Golaghat · Census 2011</option><option value="314" @selected($selectedCensusCode === '314')>Karbi Anglong · Census 2011</option><option value="315" @selected($selectedCensusCode === '315')>Dima Hasao · Census 2011</option><option value="316" @selected($selectedCensusCode === '316')>Cachar · Census 2011</option><option value="317" @selected($selectedCensusCode === '317')>Karimganj · Census 2011</option><option value="318" @selected($selectedCensusCode === '318')>Hailakandi · Census 2011</option><option value="319" @selected($selectedCensusCode === '319')>Bongaigaon · Census 2011</option><option value="320" @selected($selectedCensusCode === '320')>Chirang · Census 2011</option><option value="321" @selected($selectedCensusCode === '321')>Kamrup · Census 2011</option><option value="322" @selected($selectedCensusCode === '322')>Kamrup Metropolitan · Census 2011</option><option value="323" @selected($selectedCensusCode === '323')>Nalbari · Census 2011</option><option value="324" @selected($selectedCensusCode === '324')>Baksa · Census 2011</option><option value="325" @selected($selectedCensusCode === '325')>Darrang · Census 2011</option><option value="326" @selected($selectedCensusCode === '326')>Udalguri · Census 2011</option></select></label><button type="submit">Show figures & history</button></form>
@if($selectedCensusData)
<h3>{{ $selectedCensusData['name'] }} · Census {{ $selectedCensusData['year'] }}</h3>
@php($censusTotals = $selectedCensusData['totals']['Total'])
<div class="metrics">
@foreach(['population'=>'Population','households'=>'Households','male'=>'Male population','female'=>'Female population','literates'=>'Literate persons','workers'=>'Workers','children'=>'Children aged 0–6'] as $key=>$label)
<article><span>{{ $label }}</span><strong>{{ ($censusTotals[$key] ?? null) === null ? 'Unavailable' : number_format($censusTotals[$key]) }}</strong><small>Census {{ $selectedCensusData['year'] }} · {{ $selectedCensusData['name'] }}</small></article>
@endforeach
</div>
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
<p>{{ count($selectedCensusData[$unitKey]) }} published {{ strtolower($unitLabel) }} in this Census edition. These records are not linked to the source map shapes or 2023 constituencies.</p>
<div class="table-wrap"><table><thead><tr><th>Place / Census code</th><th>Population</th><th>Households</th><th>Literacy aged 7+</th></tr></thead><tbody>
@forelse($selectedCensusData[$unitKey] as $place)
<tr><td><a href="{{ route('civic.place', ['record' => $place['id']]) }}">{{ $place['name'] }}</a><small>{{ $place['code'] }}</small></td><td>{{ $place['population'] === null ? 'Unavailable' : number_format($place['population']) }}</td><td>{{ $place['households'] === null ? 'Unavailable' : number_format($place['households']) }}</td><td>{{ $place['literacy'] === null ? 'Unavailable' : number_format($place['literacy'], 2).'%' }}</td></tr>
@empty
<tr><td colspan="4">No published {{ strtolower($unitLabel) }} records available.</td></tr>
@endforelse
</tbody></table></div>
</section>
@endforeach
<p>{{ $selectedCensusData['census_village_rows'] }} published village Census rows available in the selected edition.</p>
@foreach($selectedCensusData['census_editions'] as $edition)<p><a href="{{ $edition['source_url'] }}">{{ $edition['source_key'] }} · official source</a></p>@endforeach
<p><a href="{{ route('district-dashboard', ['state'=>'18','district'=>$selectedCensusCode]) }}">Open the complete {{ $selectedCensusData['name'] }} Census dashboard</a></p>
@include('district-dashboard-history', ['data'=>$selectedCensusData, 'censusSeries'=>$selectedCensusData['history']])
<script src="{{ asset('js/history-lines.js') }}" defer></script>
@elseif($selectedCensusCode !== '')
<p role="status">Published figures for that Census district selection are unavailable. Choose a district from the list.</p>
@endif
</section>
