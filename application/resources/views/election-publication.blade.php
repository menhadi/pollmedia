@extends('seo-layout')
@section('content')
<a href="{{ route('election-imports.index') }}">Back to election imports</a>
<h1>{{ $place->name }} / {{ $base->year }}</h1>
<p>{{ str_replace('_',' ',$draft->status) }} / {{ count($changes) }} changed fields / compared with edition #{{ $base->id }}.</p>
<p><a href="{{ route('places.show', ['type'=>$place->type, 'slug'=>substr($place->slug,3), 'year'=>$base->year]) }}#elections">View public results and charts</a></p>
@if(!$current || $current->id !== $base->id)
<p class="notice">The active edition is now #{{ $current->id ?? 'unavailable' }}. This preview retains the comparison made when the draft was created.</p>
@endif
<section class="card"><h2>Source & validation</h2>
<p>Constituency code {{ $data['code'] }} / {{ $base->year }} / {{ $data['source_locator'] }}</p>
<p><a href="{{ $data['url'] }}" target="_blank" rel="noreferrer">Official ECI source</a> / <a href="{{ route('election-imports.download', [$draft->id,'detail']) }}">Archived detailed report</a>@if($draft->totals_path) / <a href="{{ route('election-imports.download', [$draft->id,'totals']) }}">Archived summary report</a>@endif @if(isset($data['totals_url'])) / <a href="{{ $data['totals_url'] }}" target="_blank" rel="noreferrer">Official totals source</a>@endif</p>
<p class="url">Detailed SHA-256: {{ $data['sha256'] }}@if(isset($data['totals_sha256']))<br>Summary SHA-256: {{ $data['totals_sha256'] }}@endif</p>
<p>{{ count($data['candidates']) }} candidate/NOTA rows. Candidate vote components, valid-vote totals and elector limits passed validation. Review the archived reports before publishing.</p>
</section>
<section class="card"><h2>Result comparison</h2><div style="overflow:auto"><table style="width:100%;text-align:left"><thead><tr><th>Measure</th>@foreach($metrics as $label=>$values)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
@foreach(['winner'=>'Winner','party'=>'Party at election','margin'=>'Winning margin (votes)','turnout'=>'All votes polled / electors (%)','participation'=>'Recorded candidate + NOTA votes / electors (%)'] as $field=>$label)
<tr><th>{{ $label }}</th>@foreach($metrics as $values)<td>{{ is_numeric($values[$field]) ? number_format($values[$field], in_array($field,['turnout','participation']) ? 2 : 0) : $values[$field] }}</td>@endforeach</tr>
@endforeach
</tbody></table></div><p>These are election results. Current representative profiles are maintained separately.</p>
@if(!$changes)<p class="notice">All mapped results match the public edition. Marking this review complete will not create a duplicate edition.</p>@else
<h3>Changed fields</h3><div style="overflow:auto;max-height:450px"><table style="width:100%;text-align:left"><thead><tr><th>Field</th><th>Before</th><th>Imported</th></tr></thead><tbody>@foreach($changes as $change)<tr><td>{{ $change['label'] }}</td><td>{{ $change['before'] === null ? 'Absent' : (is_bool($change['before']) ? ($change['before'] ? 'Yes' : 'No') : $change['before']) }}</td><td>{{ $change['after'] === null ? 'Absent' : (is_bool($change['after']) ? ($change['after'] ? 'Yes' : 'No') : $change['after']) }}</td></tr>@endforeach</tbody></table></div>
@endif
<details><summary>Full imported candidate results</summary><div style="overflow:auto"><table style="width:100%;text-align:left"><thead><tr><th>Row</th><th>Name</th><th>Party</th><th>General</th><th>Postal</th><th>Total</th></tr></thead><tbody>@foreach($data['candidates'] as $row)<tr><td>{{ $row['source_row'] }}</td><td>{{ $row['candidate_name'] }}</td><td>{{ $row['party_at_election'] }}</td><td>{{ number_format($row['general_votes']) }}</td><td>{{ number_format($row['postal_votes']) }}</td><td>{{ number_format($row['votes']) }}</td></tr>@endforeach</tbody></table></div></details>
@if($draft->status === 'needs_review' && $current && $current->id === $base->id)
<form method="post" action="{{ route('election-imports.publish', $draft->id) }}">@csrf<p><label><input type="checkbox" name="reviewed" value="1" required>I checked the official reports, election year, constituency and changes.</label></p><button>{{ $changes ? 'Publish reviewed election results' : 'Mark matching results reviewed' }}</button></form>
@endif
</section>
<section class="card"><h2>Publication history</h2><p>Draft created {{ $draft->created_at }} UTC by administrator #{{ $draft->created_by }}.</p>
@if($draft->published_at)<p>{{ $draft->published_contest_id ? 'Published edition #'.$draft->published_contest_id : 'Verified matching results' }} / {{ $draft->published_at }} UTC / administrator #{{ $draft->published_by }}.</p>@endif
@if($draft->restored_at)<p>Restored previous results as edition #{{ $draft->restored_contest_id }} / {{ $draft->restored_at }} UTC / administrator #{{ $draft->restored_by }}.</p>@endif
@if($draft->status === 'published' && $current && $current->id === $draft->published_contest_id)
<form method="post" action="{{ route('election-imports.restore', $draft->id) }}">@csrf<button class="secondary">Restore previous election results</button></form>
@endif
</section>
@endsection
