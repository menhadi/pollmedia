<section class="panel" id="island-electoral-context"><h2>AC / PC dashboards and election history</h2>
<p><a data-pc-dashboard href="{{ route('constituency.overview', ['state'=>$islandPc['state'], 'kind'=>'pc', 'name'=>$islandPc['archive_name'], 'edition'=>$islandPc['edition'], 'code'=>$islandPc['code']]) }}">{{ $islandPc['name'] }} · Parliamentary dashboard · 2024</a></p>
<p>All source groups in this territory share this single parliamentary constituency. The PC is listed once per page; source-map boundaries and constituency boundaries are separate editions.</p>
@if($region === 'puducherry')
<p>Electoral district grouping: Puducherry district includes ACs 1–23, Mahe (29) and Yanam (30); Karaikal includes ACs 24–28. Settlement source groups retain the four regional names. No village-to-AC polygon assignment is inferred.</p>
<div class="table-wrap"><table><thead><tr><th>AC</th><th>Dashboard</th><th>Source region</th></tr></thead><tbody>
@foreach($islandAcs as $ac)
<tr><td>{{ $ac['number'] }}</td><td><a data-ac-dashboard href="{{ route('constituency.overview', ['state'=>$ac['state'], 'kind'=>'ac', 'name'=>$ac['name'], 'edition'=>$ac['edition'], 'code'=>$ac['code']]) }}">{{ $ac['name'] }} · {{ $ac['year'] }} results and history</a></td><td>{{ $ac['group'] }}</td></tr>
@endforeach
</tbody></table></div>
<p><a href="{{ $islandElectoral['source_ac'] }}">CEO constituency and district list · 16 September 2026</a> · <a href="{{ $islandElectoral['source_regions'] }}">Official regional and constituency maps</a></p>
@else
<p><strong>Assembly constituencies: not applicable.</strong> {{ $regionLabel }} has no Legislative Assembly. <a href="{{ $islandElectoral['source_institutions'] }}">MHA annual report 2021–22, Chapter 6</a>.</p>
@endif
<p><a href="{{ $islandElectoral['source_pc'] }}">ECI General Elections 2024 reference</a>. Archive record codes are distinct from official constituency numbers.</p>
</section>
