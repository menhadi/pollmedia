<section id="village-coverage">
<div class="kicker">Village connections · official LGD report</div><h2>Explore villages by constituency</h2>
<p class="lead">Open the linked village profiles in either Census edition. Each profile keeps its historical figures separate from current administrative and electoral connections.</p>
<div class="card"><div class="table"><table><caption>Available coverage in the Pilibhit PC report · checked {{ $villageCoverage['checked_on'] }}</caption><thead><tr><th scope="col">Assembly constituency</th><th scope="col">LGD village records</th><th scope="col">Linked 2011 profiles</th><th scope="col">Linked 2001 profiles</th></tr></thead><tbody>
@forelse($villageCoverage['rows'] as $row)
<tr><th scope="row"><a href="{{ route('places.show',['type'=>'ac','slug'=>$row['ac']]) }}">{{ ucfirst($row['ac']) }} AC →</a></th><td>{{ number_format($row['reported']) }}</td>
@foreach(['2011','2001'] as $edition)
<td>@if($row['profiles'][$edition]>0)<a href="{{ route('villages.index',['ac'=>$row['ac'],'year'=>$edition]) }}">{{ number_format($row['profiles'][$edition]) }} profiles →</a>@else<span>No profiles imported</span>@endif</td>
@endforeach
</tr>
@empty
<tr><td colspan="4">No village mappings for this scope are available in the imported report.</td></tr>
@endforelse
</tbody></table></div>
<p class="small">LGD counts describe village records in this report, excluding urban wards. Linked counts describe available Pilibhit district Census profiles, not all settlements or the constituency's population. A missing profile does not mean the village has no residents. Census editions have different coverage and boundaries.</p>
<a href="{{ $villageCoverage['url'] }}" target="_blank" rel="noreferrer">Official PC/AC mapping source ↗</a></div>
</section>
