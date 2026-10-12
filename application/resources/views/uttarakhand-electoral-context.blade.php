@php
    $electoral = json_decode(file_get_contents(database_path('fixtures/uttarakhand-electoral-source-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $electoralDistricts = collect($electoral['districts']);
    $defaultElectoral = $electoralDistricts->first(fn ($item) => strtoupper(trim($item['name'])) === strtoupper(trim($district['source_name'])));
    $electoralNumber = (int) request()->query('electoral', $defaultElectoral['number'] ?? 0);
    $electoralDistrict = $electoralDistricts->firstWhere('number', $electoralNumber);
    $electoralAcs = collect($electoral['acs'])->keyBy('number');
    $electoralPcs = collect($electoral['pcs'])->keyBy('number');
    $districtAcs = $electoralDistrict ? $electoralAcs->only($electoralDistrict['acs']) : collect();
    $districtPcs = $districtAcs->pluck('pc')->unique()->sort();
    $archiveUrl = fn ($seat) => route('constituency.overview', ['state' => 'Uttarakhand', 'kind' => $seat['archive']['kind'], 'name' => $seat['archive']['constituency_name'], 'edition' => $seat['archive']['edition_id'], 'code' => $seat['archive']['record_code']]);
@endphp
<section class="panel" id="uttarakhand-electoral-context"><h2>PC / AC details and election history</h2>
<p>District grouping and PC membership: CEO Uttarakhand electoral snapshots dated 28 March 2024. Names retain the source spelling. These records do not establish equivalence with the map or Census boundaries.</p>
<form method="get" action="{{ url()->current() }}#uttarakhand-electoral-context">
@if(request()->has('census'))
<input type="hidden" name="census" value="{{ request()->query('census') }}">
@endif
<label for="uttarakhand-electoral-district">Electoral source district</label><select id="uttarakhand-electoral-district" name="electoral"><option value="0">Choose source district</option>
@foreach($electoralDistricts as $item)
<option value="{{ $item['number'] }}" @selected($electoralNumber === $item['number'])>{{ $item['name'] }} · 28 March 2024</option>
@endforeach
</select><button type="submit">Show PC / AC details</button></form>
@if($electoralDistrict)
<h3>{{ $electoralDistrict['name'] }} · {{ $districtAcs->count() }} ACs</h3><div class="table-wrap"><table data-electoral-acs><thead><tr><th>AC number / source name</th><th>Associated PC</th><th>Election history</th></tr></thead><tbody>
@foreach($districtAcs as $ac)
<tr data-ac="{{ $ac['number'] }}"><td>
@if(isset($ac['archive']))
<a data-ac-dashboard="{{ $ac['number'] }}" href="{{ $archiveUrl($ac) }}">{{ $ac['number'] }} · {{ $ac['name'] }}</a>
@else
{{ $ac['number'] }} · {{ $ac['name'] }}<small>Direct archive identity under review</small>
@endif
</td><td>{{ $ac['pc'] }} · {{ $electoralPcs[$ac['pc']]['name'] }}</td><td><a href="{{ route('elections.constituencies', ['state' => 'Uttarakhand', 'kind' => 'ac', 'q' => $ac['name']]) }}">Search historical reports</a></td></tr>
@endforeach
</tbody></table></div><h3>Associated PCs · shared membership</h3>
@foreach($districtPcs as $pcNumber)
@php($pc = $electoralPcs[$pcNumber])
<details data-pc="{{ $pcNumber }}"><summary>PC {{ $pcNumber }} · {{ $pc['name'] }} · {{ count($pc['acs']) }} ACs</summary>
@if(isset($pc['archive']))
<p><a data-pc-dashboard="{{ $pcNumber }}" href="{{ $archiveUrl($pc) }}">Open {{ $pc['name'] }} constituency dashboard</a> · {{ $pc['archive']['year'] }} archive edition</p>
@else
<p>Direct archive identity under review. <a href="{{ route('elections.constituencies', ['state' => 'Uttarakhand', 'kind' => 'pc', 'q' => $pc['name']]) }}">Search historical reports</a></p>
@endif
<p>All source segments are shown, including those assigned to other districts.</p><ul>
@foreach($pc['acs'] as $acNumber)
@php($segment = $electoralAcs[$acNumber])
<li>
@if(isset($segment['archive']))
<a href="{{ $archiveUrl($segment) }}">{{ $acNumber }} · {{ $segment['name'] }}</a>
@else
{{ $acNumber }} · {{ $segment['name'] }} · archive identity under review
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
<p><a href="{{ $electoral['district_source']['url'] }}">Official district / AC snapshot · 28 March 2024</a> · <a href="{{ $electoral['pc_source']['url'] }}">Official PC / AC snapshot · 28 March 2024</a></p><p>Archive links retain their own election editions and original names. Unresolved spelling differences are shown explicitly; no constituency is assigned solely by approximate name matching.</p></section>
