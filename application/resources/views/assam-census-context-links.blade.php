@php
    $selectedCensusCode = (string) request()->query('census', '');
    $allowedCensusCodes = array_map('strval', range(300, 326));
    $selectedCensusData = in_array($selectedCensusCode, $allowedCensusCodes, true)
        ? app(\App\Services\DistrictDashboardData::class)->published($selectedCensusCode, '18')
        : null;
@endphp
<section class="panel" id="selected-census-context"><h2>Published Census 2011 district context</h2><p>Select a Census 2011 district to display its published figures and historical charts alongside this map. These figures describe the selected Census geography; they are not totals for the newer outline, village shapes or 2023 constituencies.</p>
<form method="get" action="{{ url()->current() }}#selected-census-context"><label>Census district<select name="census"><option value="">Choose a Census 2011 district</option><option value="300" @selected($selectedCensusCode === '300')>Kokrajhar · Census 2011</option><option value="301" @selected($selectedCensusCode === '301')>Dhubri · Census 2011</option><option value="302" @selected($selectedCensusCode === '302')>Goalpara · Census 2011</option><option value="303" @selected($selectedCensusCode === '303')>Barpeta · Census 2011</option><option value="304" @selected($selectedCensusCode === '304')>Morigaon · Census 2011</option><option value="305" @selected($selectedCensusCode === '305')>Nagaon · Census 2011</option><option value="306" @selected($selectedCensusCode === '306')>Sonitpur · Census 2011</option><option value="307" @selected($selectedCensusCode === '307')>Lakhimpur · Census 2011</option><option value="308" @selected($selectedCensusCode === '308')>Dhemaji · Census 2011</option><option value="309" @selected($selectedCensusCode === '309')>Tinsukia · Census 2011</option><option value="310" @selected($selectedCensusCode === '310')>Dibrugarh · Census 2011</option><option value="311" @selected($selectedCensusCode === '311')>Sivasagar · Census 2011</option><option value="312" @selected($selectedCensusCode === '312')>Jorhat · Census 2011</option><option value="313" @selected($selectedCensusCode === '313')>Golaghat · Census 2011</option><option value="314" @selected($selectedCensusCode === '314')>Karbi Anglong · Census 2011</option><option value="315" @selected($selectedCensusCode === '315')>Dima Hasao · Census 2011</option><option value="316" @selected($selectedCensusCode === '316')>Cachar · Census 2011</option><option value="317" @selected($selectedCensusCode === '317')>Karimganj · Census 2011</option><option value="318" @selected($selectedCensusCode === '318')>Hailakandi · Census 2011</option><option value="319" @selected($selectedCensusCode === '319')>Bongaigaon · Census 2011</option><option value="320" @selected($selectedCensusCode === '320')>Chirang · Census 2011</option><option value="321" @selected($selectedCensusCode === '321')>Kamrup · Census 2011</option><option value="322" @selected($selectedCensusCode === '322')>Kamrup Metropolitan · Census 2011</option><option value="323" @selected($selectedCensusCode === '323')>Nalbari · Census 2011</option><option value="324" @selected($selectedCensusCode === '324')>Baksa · Census 2011</option><option value="325" @selected($selectedCensusCode === '325')>Darrang · Census 2011</option><option value="326" @selected($selectedCensusCode === '326')>Udalguri · Census 2011</option></select></label><button type="submit">Show figures & history</button></form>
@if($selectedCensusData)
<h3>{{ $selectedCensusData['name'] }} · Census {{ $selectedCensusData['year'] }}</h3>
@php($censusTotals = $selectedCensusData['totals']['Total'])
<dl class="stats-grid">
@foreach(['population'=>'Population','households'=>'Households','male'=>'Male population','female'=>'Female population','literates'=>'Literate persons','workers'=>'Workers'] as $key=>$label)
<div><dt>{{ $label }}</dt><dd>{{ ($censusTotals[$key] ?? null) === null ? 'Unavailable' : number_format($censusTotals[$key]) }}</dd></div>
@endforeach
</dl>
<p>{{ $selectedCensusData['census_village_rows'] }} published village Census rows available in the selected edition.</p>
@foreach($selectedCensusData['census_editions'] as $edition)<p><a href="{{ $edition['source_url'] }}">{{ $edition['source_key'] }} · official source</a></p>@endforeach
<p><a href="{{ route('district-dashboard', ['state'=>'18','district'=>$selectedCensusCode]) }}">Open the complete {{ $selectedCensusData['name'] }} Census dashboard</a></p>
@include('district-dashboard-history', ['data'=>$selectedCensusData, 'censusSeries'=>$selectedCensusData['history']])
<script src="{{ asset('js/history-lines.js') }}" defer></script>
@elseif($selectedCensusCode !== '')
<p role="status">Published figures for that Census district selection are unavailable. Choose a district from the list.</p>
@endif
</section>
