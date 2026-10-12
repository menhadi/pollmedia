@php
    $electoral = json_decode(file_get_contents(database_path('fixtures/odisha-electoral-source-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $electoralDistricts = collect($electoral['districts']);
    $defaultElectoral = $electoralDistricts->firstWhere('name', strtoupper(trim($district['source_name'])));
    $electoralNameContexts = [
        'khordha' => ['name' => 'KHURDA', 'url' => 'https://khordha.odisha.gov.in/en/about-district/history'],
        'sonepur' => ['name' => 'SUBARNAPUR', 'url' => 'https://eodbodisha.odisha.gov.in/District/Home?distid=nGTbzCiAbFU%3D'],
    ];
    $electoralNameContext = $electoralNameContexts[$district['slug']] ?? null;
    $defaultElectoral ??= $electoralNameContext ? $electoralDistricts->firstWhere('name', $electoralNameContext['name']) : null;
    $electoralNumber = (int) request()->query('electoral', $defaultElectoral['number'] ?? 0);
    $electoralDistrict = $electoralDistricts->firstWhere('number', $electoralNumber);
    $electoralAcs = collect($electoral['acs'])->keyBy('number');
    $electoralPcs = collect($electoral['pcs'])->keyBy('number');
    $districtAcs = $electoralDistrict ? $electoralAcs->only($electoralDistrict['acs']) : collect();
    $districtPcs = $districtAcs->pluck('pc')->filter()->unique()->sort();
    $archiveUrl = fn ($seat) => route('constituency.overview', ['state' => 'Odisha', 'kind' => $seat['archive']['kind'], 'name' => $seat['archive']['constituency_name'], 'edition' => $seat['archive']['edition_id'], 'code' => $seat['archive']['record_code']]);
@endphp
<section class="panel" id="odisha-electoral-context">
<h2>PC / AC details and election history</h2>
<p>District grouping: CEO Draft 2020 electoral-roll analysis. PC membership: separate, undated CEO list, supplemented for Gopalpur by the CEO map citing the 15 December 2006 delimitation order. These source records are not a boundary match to the village map or Census edition, and are not certified as the current legal geography.</p>
@if($electoralNameContext)
<p data-electoral-name-reference>Named district context: {{ $district['source_name'] }} / {{ $electoralNameContext['name'] }} — <a href="{{ $electoralNameContext['url'] }}">official district naming reference</a>. This establishes the name context only; map and electoral boundary editions remain separate.</p>
@endif
<form method="get" action="{{ url()->current() }}#odisha-electoral-context">
@if(request()->has('census'))<input type="hidden" name="census" value="{{ request()->query('census') }}">@endif
<label for="odisha-electoral-district">Electoral source district</label>
<select id="odisha-electoral-district" name="electoral"><option value="0">Choose source district</option>
@foreach($electoralDistricts as $item)<option value="{{ $item['number'] }}" @selected($electoralNumber === $item['number'])>{{ $item['name'] }} · Draft 2020</option>@endforeach
</select><button type="submit">Show PC / AC details</button>
</form>
@if($electoralDistrict)
<h3>{{ $electoralDistrict['name'] }} · {{ $districtAcs->count() }} ACs in the district table</h3>
<div class="table-wrap"><table data-electoral-acs><thead><tr><th>AC number / source name</th><th>Reservation</th><th>PC in the cited sources</th><th>Election history</th></tr></thead><tbody>
@foreach($districtAcs as $ac)<tr data-ac="{{ $ac['number'] }}"><td><a data-ac-dashboard="{{ $ac['number'] }}" href="{{ $archiveUrl($ac) }}">{{ $ac['number'] }} · {{ $ac['name'] }}</a></td><td>{{ $ac['reservation'] }}</td><td>@if($ac['pc']){{ $ac['pc'] }} · {{ $electoralPcs[$ac['pc']]['name'] }}@if(isset($ac['pc_source'])) · CEO map, order 15 December 2006 @endif@else Unavailable — omitted from the reviewed PC list @endif</td><td><a data-ac-archive="{{ $ac['number'] }}" href="{{ route('elections.constituencies', ['state' => 'Odisha', 'kind' => 'ac', 'q' => $ac['name']]) }}">Search historical reports</a></td></tr>@endforeach
</tbody></table></div>
<h3>Associated PCs · shared membership</h3>
@foreach($districtPcs as $pcNumber)
@php($pc = $electoralPcs[$pcNumber])
<details data-pc="{{ $pcNumber }}"><summary>PC {{ $pcNumber }} · {{ $pc['name'] }} · {{ count($pc['acs']) }} listed ACs</summary>
<p><a data-pc-archive="{{ $pcNumber }}" href="{{ route('elections.constituencies', ['state' => 'Odisha', 'kind' => 'pc', 'q' => $pc['name']]) }}">Search this PC in historical reports</a>. Report editions and historical spelling may differ; a name search does not establish boundary equivalence.</p>
<p><a data-pc-dashboard="{{ $pcNumber }}" href="{{ $archiveUrl($pc) }}">Open {{ $pc['name'] }} constituency dashboard</a> · archive edition {{ $pc['archive']['year'] }}</p>
<p>All listed segments are shown, including those grouped under other districts in the Draft 2020 table.</p>
<ul>@foreach($pc['acs'] as $acNumber)@php($segment = $electoralAcs[$acNumber])<li><a href="{{ $archiveUrl($segment) }}">{{ $acNumber }} · {{ $segment['name'] }}</a> — {{ $segment['district'] }}@if($segment['district'] !== $electoralDistrict['name']) (other district in source)@endif</li>@endforeach</ul>
</details>
@endforeach
@else
<p>Select the district spelling used in the electoral source to view its ACs and shared PCs. Name variants are not automatically treated as boundary equivalence.</p>
@endif
<p>Source exception retained: the reviewed PC list omitted AC 132 GOPALPUR. Its association with PC 20 BERHAMPUR is supplied by the separate <a href="{{ $electoral['supplemental_pc_source']['url'] }}">official CEO / ORSAC map · order dated 15 December 2006</a>, which shows all seven segments. This does not establish a match to the village-map edition.</p>
<p><a href="{{ $electoral['district_source']['url'] }}">Official district / AC table · Draft 2020 directory</a> · <a href="{{ $electoral['pc_source']['url'] }}">Official PC / AC list · undated</a></p>
<p>Election archives retain their own report editions: <a href="{{ route('elections.constituencies', ['state' => 'Odisha', 'kind' => 'pc']) }}">Odisha parliamentary archives</a> · <a href="{{ route('elections.constituencies', ['state' => 'Odisha', 'kind' => 'ac']) }}">Odisha assembly archives</a> · <a href="{{ route('elections.constituencies', ['state' => 'Orissa', 'kind' => 'pc']) }}">Orissa parliamentary archives</a> · <a href="{{ route('elections.constituencies', ['state' => 'Orissa', 'kind' => 'ac']) }}">Orissa assembly archives</a>.</p>
</section>
