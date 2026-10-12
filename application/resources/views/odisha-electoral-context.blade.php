@php
    $electoral = json_decode(file_get_contents(database_path('fixtures/odisha-electoral-source-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $electoralDistricts = collect($electoral['districts']);
    $defaultElectoral = $electoralDistricts->firstWhere('name', strtoupper(trim($district['source_name'])));
    $electoralNumber = (int) request()->query('electoral', $defaultElectoral['number'] ?? 0);
    $electoralDistrict = $electoralDistricts->firstWhere('number', $electoralNumber);
    $electoralAcs = collect($electoral['acs'])->keyBy('number');
    $electoralPcs = collect($electoral['pcs'])->keyBy('number');
    $districtAcs = $electoralDistrict ? $electoralAcs->only($electoralDistrict['acs']) : collect();
    $districtPcs = $districtAcs->pluck('pc')->filter()->unique()->sort();
@endphp
<section class="panel" id="odisha-electoral-context">
<h2>PC / AC details and election history</h2>
<p>District grouping: CEO Draft 2020 electoral-roll analysis. PC membership: separate, undated CEO list. These source records are not a boundary match to the village map or Census edition, and are not certified as the current legal geography.</p>
<form method="get" action="{{ url()->current() }}#odisha-electoral-context">
@if(request()->has('census'))<input type="hidden" name="census" value="{{ request()->query('census') }}">@endif
<label for="odisha-electoral-district">Electoral source district</label>
<select id="odisha-electoral-district" name="electoral"><option value="0">Choose source district</option>
@foreach($electoralDistricts as $item)<option value="{{ $item['number'] }}" @selected($electoralNumber === $item['number'])>{{ $item['name'] }} · Draft 2020</option>@endforeach
</select><button type="submit">Show PC / AC details</button>
</form>
@if($electoralDistrict)
<h3>{{ $electoralDistrict['name'] }} · {{ $districtAcs->count() }} ACs in the district table</h3>
<div class="table-wrap"><table data-electoral-acs><thead><tr><th>AC number / source name</th><th>Reservation</th><th>PC in the separate CEO list</th></tr></thead><tbody>
@foreach($districtAcs as $ac)<tr data-ac="{{ $ac['number'] }}"><td>{{ $ac['number'] }} · {{ $ac['name'] }}</td><td>{{ $ac['reservation'] }}</td><td>@if($ac['pc']){{ $ac['pc'] }} · {{ $electoralPcs[$ac['pc']]['name'] }}@else Unavailable — omitted from the reviewed PC list @endif</td></tr>@endforeach
</tbody></table></div>
<h3>Associated PCs · shared membership</h3>
@foreach($districtPcs as $pcNumber)
@php($pc = $electoralPcs[$pcNumber])
<details data-pc="{{ $pcNumber }}"><summary>PC {{ $pcNumber }} · {{ $pc['name'] }} · {{ count($pc['acs']) }} listed ACs</summary>
<p>All listed segments are shown, including those grouped under other districts in the Draft 2020 table.</p>
<ul>@foreach($pc['acs'] as $acNumber)@php($segment = $electoralAcs[$acNumber])<li>{{ $acNumber }} · {{ $segment['name'] }} — {{ $segment['district'] }}@if($segment['district'] !== $electoralDistrict['name']) (other district in source)@endif</li>@endforeach</ul>
</details>
@endforeach
@else
<p>Select the district spelling used in the electoral source to view its ACs and shared PCs. Name variants are not automatically treated as boundary equivalence.</p>
@endif
<p>Source exception: AC 132 GOPALPUR appears under GANJAM in the district table but is omitted from the reviewed PC list. Its PC association remains unavailable.</p>
<p><a href="{{ $electoral['district_source']['url'] }}">Official district / AC table · Draft 2020 directory</a> · <a href="{{ $electoral['pc_source']['url'] }}">Official PC / AC list · undated</a></p>
<p>Election archives retain their own report editions: <a href="{{ route('elections.constituencies', ['state' => 'Odisha', 'kind' => 'pc']) }}">Odisha parliamentary archives</a> · <a href="{{ route('elections.constituencies', ['state' => 'Odisha', 'kind' => 'ac']) }}">Odisha assembly archives</a> · <a href="{{ route('elections.constituencies', ['state' => 'Orissa', 'kind' => 'pc']) }}">Orissa parliamentary archives</a> · <a href="{{ route('elections.constituencies', ['state' => 'Orissa', 'kind' => 'ac']) }}">Orissa assembly archives</a>.</p>
</section>
