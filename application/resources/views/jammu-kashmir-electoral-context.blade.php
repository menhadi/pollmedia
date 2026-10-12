@php
    $electoral = json_decode(file_get_contents(database_path('fixtures/jammu-kashmir-electoral-source-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $electoralDistricts = collect($electoral['districts']);
    $defaultElectoral = $electoralDistricts->first(fn ($item) => strtoupper(trim($item['name'])) === strtoupper(trim($district['source_name'])));
    $electoralNumber = (int) request()->query('electoral', $defaultElectoral['number'] ?? 0);
    $electoralDistrict = $electoralDistricts->firstWhere('number', $electoralNumber);
    $electoralAcs = collect($electoral['acs'])->keyBy('number');
    $electoralPcs = collect($electoral['pcs'])->keyBy('number');
    $districtAcs = $electoralDistrict ? $electoralAcs->only($electoralDistrict['acs']) : collect();
    $districtPcs = $districtAcs->pluck('pc')->unique()->sort();
    $archiveUrl = fn ($seat) => route('constituency.overview', ['state' => $seat['archive']['state_label'] ?? 'Jammu and Kashmir', 'kind' => $seat['archive']['kind'], 'name' => $seat['archive']['constituency_name'], 'edition' => $seat['archive']['edition_id'], 'code' => $seat['archive']['record_code']]);
@endphp
<section class="panel" id="jammu-kashmir-electoral-context"><h2>PC / AC details and election history</h2>
<p>District grouping and PC membership: final Delimitation Order No. 2 of 5 May 2022, effective 20 May 2022, with territorial references to 15 June 2020. AC names retain Table B spelling; AC 87 is Thannamandi in Table A and Thana Mandi in Table B. Earlier election geography is a separate edition. These records do not establish equivalence with the map or Census boundaries.</p>
<form method="get" action="{{ url()->current() }}#jammu-kashmir-electoral-context">
@if(request()->has('census'))
<input type="hidden" name="census" value="{{ request()->query('census') }}">
@endif
<label for="jammu-kashmir-electoral-district">Electoral source district</label><select id="jammu-kashmir-electoral-district" name="electoral"><option value="0">Choose source district</option>
@foreach($electoralDistricts as $item)
<option value="{{ $item['number'] }}" @selected($electoralNumber === $item['number'])>{{ $item['name'] }} · 2022 delimitation edition</option>
@endforeach
</select><button type="submit">Show PC / AC details</button></form>
@if($electoralDistrict)
<h3>{{ $electoralDistrict['name'] }} · {{ $districtAcs->count() }} ACs</h3><div class="table-wrap"><table data-electoral-acs><thead><tr><th>AC number / source name</th><th>Associated PC</th><th>Election history</th></tr></thead><tbody>
@foreach($districtAcs as $ac)
<tr data-ac="{{ $ac['number'] }}"><td>
@if(isset($ac['archive']))
<a data-ac-dashboard="{{ $ac['number'] }}" href="{{ $archiveUrl($ac) }}">{{ $ac['number'] }} · {{ $ac['name'] }}</a>
@else
{{ $ac['number'] }} · {{ $ac['name'] }}<small>Post-2022 AC archive not yet verified</small>
@endif
</td><td>{{ $ac['pc'] }} · {{ $electoralPcs[$ac['pc']]['name'] }}</td><td><a href="{{ route('elections.constituencies', ['state' => 'Jammu & Kashmir', 'kind' => 'ac', 'q' => $ac['name']]) }}">Search historical reports</a></td></tr>
@endforeach
</tbody></table></div><h3>Associated PCs · shared membership</h3>
@foreach($districtPcs as $pcNumber)
@php($pc = $electoralPcs[$pcNumber])
<details data-pc="{{ $pcNumber }}"><summary>PC {{ $pcNumber }} · {{ $pc['name'] }} · {{ count($pc['acs']) }} ACs</summary>
@if(isset($pc['archive']))
<p><a data-pc-dashboard="{{ $pcNumber }}" href="{{ $archiveUrl($pc) }}">Open {{ $pc['name'] }} constituency dashboard</a> · {{ $pc['archive']['year'] }} archive edition</p>
@else
<p>Post-2022 AC archive not yet verified. <a href="{{ route('elections.constituencies', ['state' => 'Jammu and Kashmir', 'kind' => 'pc', 'q' => $pc['name']]) }}">Search historical reports</a></p>
@endif
<p>All source segments are shown, including those assigned to other districts.</p><ul>
@foreach($pc['acs'] as $acNumber)
@php($segment = $electoralAcs[$acNumber])
<li>
@if(isset($segment['archive']))
<a href="{{ $archiveUrl($segment) }}">{{ $acNumber }} · {{ $segment['name'] }}</a>
@else
{{ $acNumber }} · {{ $segment['name'] }} · post-2022 archive not yet verified
@endif
— {{ $segment['district'] }}
@if($segment['district'] !== $electoralDistrict['name'])
(other district in source)
@endif
</li>
@endforeach
</ul></details>
@endforeach
@else
<p>Select the electoral source district to view its ACs and shared PCs. Different source spellings require explicit selection. This selector changes electoral context only, not the map.</p>
@endif
<p><a href="{{ $electoral['district_source']['url'] }}">Official MHA archive · final 2022 Delimitation Order No. 2</a></p><p>Direct AC and PC links use verified 2024 archive identities. Original archive labels, including repeated reservation suffixes, are retained on their destination dashboards. Historical search may include pre-delimitation constituencies; matching names alone do not establish the same geography.</p></section>
