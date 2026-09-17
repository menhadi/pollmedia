@extends('seo-layout')
@section('content')
<a href="{{ route('election-imports.index',['archive_type'=>$data['kind'],'archive_year'=>$data['year']]) }}#historical-archive">Back to historical archive</a>
<h1>{{ $data['kind']==='pc' ? 'Lok Sabha' : 'Uttar Pradesh Assembly' }} / {{ $data['year'] }}</h1>
<section class="card"><h2>Extracted historical results</h2>
<p>{{ count($data['records']) }} constituency records · {{ $data['validated_count'] }} source-validated · {{ collect($data['records'])->whereNotNull('review')->count() }} admin-reviewed · {{ $data['review_count'] }} warnings remaining.</p>
@isset($data['coverage_note'])<p class="notice">{{ $data['coverage_note'] }}</p>@endisset
@if(!empty($data['coverage']['unmatched_summaries']))<details><summary>† {{ count($data['coverage']['unmatched_summaries']) }} summary identities have no matched detailed table</summary><ul>@foreach($data['coverage']['unmatched_summaries'] as $missing)<li>{{ $missing['state_name'] }} / {{ $missing['official_pc_code'] }} / {{ $missing['constituency_name'] }} — summary PDF page {{ $missing['summary_page'] }}</li>@endforeach</ul></details>@endif
<p>These are historical constituency identities from this report. Links to modern constituencies and publication remain pending. Validation checks reported totals, electorate and source identities; candidate counts and vote components are checked where reported.</p>
<p><a href="{{ $data['source_url'] }}" target="_blank" rel="noreferrer">Official ECI edition</a> · <a href="{{ route('election-archives.file',[$archive,$source['download_id']]) }}">Archived official source</a></p>
@foreach($data['additional_sources'] ?? [] as $additional)<p><a href="{{ route('election-archives.file',[$archive,$additional['download_id']]) }}">{{ $additional['name'] }}</a></p>@endforeach
<details><summary>Source checksum</summary><p style="overflow-wrap:anywhere">{{ $data['source_sha256'] }}</p></details></section>
<section class="card"><h2>Choose a historical constituency</h2>
<form method="get"><label for="code">Constituency in {{ $data['year'] }}</label><select id="code" name="code" required><option value="">Select a constituency</option>@foreach($data['records'] as $record)<option value="{{ $record['code'] }}" @selected(($selected['code'] ?? null)===$record['code'])>{{ $record['official_pc_code'] ?? $record['code'] }} / {{ $record['name'] }}{{ $record['has_warning'] ? ' †' : '' }}</option>@endforeach</select><button>Show results</button></form>
@if($selected)
<h3>{{ $selected['official_pc_code'] ?? $selected['code'] }} / {{ $selected['name'] }} @if($selected['has_warning'])<a href="#data-note" aria-label="Data needs review; see explanation">†</a>@endif</h3>
@isset($selected['number_of_seats'])<p>Seats elected by this constituency: {{ $selected['number_of_seats'] }}.</p>@endisset
<p>@isset($selected['detail_page'])Detailed results start: PDF page {{ $selected['detail_page'] }}@else{{ $selected['source_locator'] ?? 'See the linked official workbook for this constituency.' }}@endisset @isset($selected['summary_page']) · Summary: PDF page {{ $selected['summary_page'] }}@endisset.</p>
@isset($selected['summary_locator'])<p>Official summary workbook: {{ $selected['summary_locator'] }}.</p>@endisset
@isset($selected['extraction_note'])<p>{{ $selected['extraction_note'] }}.</p>@endisset
@if(!$selected['has_warning'] && isset($selected['winner'], $selected['margin']))
<p><strong>Winner: {{ $selected['winner'] }}</strong> · Winning margin: {{ number_format($selected['margin']) }} votes.</p>
@endif
<p>Electors: {{ isset($selected['electors']) ? number_format($selected['electors']) : 'Not reported' }} · Votes polled: {{ isset($selected['votes_polled']) ? number_format($selected['votes_polled']) : 'Not reported' }} · Valid candidate votes: {{ isset($selected['valid_candidate_votes']) ? number_format($selected['valid_candidate_votes']) : 'Not reported' }}.</p>
@if(isset($selected['summary_totals']) && $selected['has_warning'])
<div style="overflow:auto"><table><thead><tr><th>Reported measure</th><th>Detailed section</th><th>Summary section</th></tr></thead><tbody>@foreach(['electors'=>'Electors','votes_polled'=>'Votes polled','valid_candidate_votes'=>'Valid candidate votes'] as $key=>$label)<tr><td>{{ $label }}</td><td>{{ isset($selected[$key]) ? number_format($selected[$key]) : 'Not reported' }}</td><td>{{ isset($selected['summary_totals'][$key]) ? number_format($selected['summary_totals'][$key]) : 'Not reported' }}</td></tr>@endforeach</tbody></table></div>
@endif
<div style="overflow:auto"><table style="width:100%;text-align:left"><thead><tr><th>Candidate</th><th>Party at election</th><th>General votes</th><th>Postal votes</th><th>Total votes</th></tr></thead><tbody>@foreach($selected['candidates'] as $candidate)<tr><td>{{ $candidate['candidate_name'] }}</td><td>{{ $candidate['party_at_election'] }}</td><td>{{ $candidate['general_votes'] === null ? 'Not reported' : number_format($candidate['general_votes']) }}</td><td>{{ $candidate['postal_votes'] === null ? 'Not reported' : number_format($candidate['postal_votes']) }}</td><td>{{ $candidate['votes'] === null ? 'Not reported' : number_format($candidate['votes']) }}</td></tr>@endforeach</tbody></table></div>
@if($selected['has_warning'])<p id="data-note" class="notice error"><strong>† Data note:</strong> {{ rtrim($selected['error'], '.') }}. Available source data is shown above; this issue awaits admin correction or acceptance.</p>@endif
@if($selected['review'])<p>Admin {{ $selected['status']==='corrected' ? 'corrected' : 'accepted' }} this record on {{ $selected['review']->created_at }}. Acceptance records an editorial decision; it does not change the official source.</p>@endif
<details><summary>Admin review and correction</summary>
<p>Accept the displayed data with a reason, or save corrected values. Reviews apply to this source version; changed source data will be reviewed again.</p>
<form method="post" action="{{ route('election-archives.review',[$archive,$selected['code']]) }}">@csrf
<input type="hidden" name="fingerprint" value="{{ $selected['review_fingerprint'] }}"><input type="hidden" name="review_id" value="{{ $selected['review_id'] }}">
<label>Review reason<textarea name="reason" required minlength="5" maxlength="4000">{{ old('reason') }}</textarea></label>
<label>Supporting source link<input type="url" name="reference_url" value="{{ old('reference_url') }}"></label>
<button name="action" value="accept">Accept displayed data and clear warning</button>
</form>
<form method="post" action="{{ route('election-archives.review',[$archive,$selected['code']]) }}">@csrf
<input type="hidden" name="fingerprint" value="{{ $selected['review_fingerprint'] }}"><input type="hidden" name="review_id" value="{{ $selected['review_id'] }}"><input type="hidden" name="action" value="correct">
<label>Constituency name<input name="name" value="{{ $selected['name'] }}" required></label>
@foreach(['electors'=>'Electors','votes_polled'=>'Votes polled','valid_candidate_votes'=>'Valid candidate votes'] as $key=>$label)<label>{{ $label }}<input type="number" min="0" name="{{ $key }}" value="{{ $selected[$key] ?? '' }}" required></label>@endforeach
<p>Candidate figures: leave unreported vote components blank.</p>
@foreach($selected['candidates'] as $i=>$candidate)<fieldset><legend>Candidate {{ $i+1 }}</legend>
@foreach(['candidate_name'=>'Name','party_at_election'=>'Party'] as $key=>$label)<label>{{ $label }}<input name="candidates[{{ $i }}][{{ $key }}]" value="{{ $candidate[$key] }}" required></label>@endforeach
@foreach(['general_votes'=>'General votes','postal_votes'=>'Postal votes','votes'=>'Total votes'] as $key=>$label)<label>{{ $label }}<input type="number" min="0" name="candidates[{{ $i }}][{{ $key }}]" value="{{ $candidate[$key] ?? '' }}"></label>@endforeach
</fieldset>@endforeach
<label>Correction reason<textarea name="reason" required minlength="5" maxlength="4000"></textarea></label>
<label>Supporting source link<input type="url" name="reference_url"></label>
<button>Save correction and clear warning</button></form>
</details>
@if($history->isNotEmpty())<details><summary>Review history and original source issue</summary><p>{{ $selected['error'] ?? 'No extraction issue' }}</p><ul>@foreach($history as $review)<li>{{ $review->created_at }} — {{ $review->action }} by administrator #{{ $review->reviewed_by }}: {{ $review->reason }} @if($review->reference_url)<a href="{{ $review->reference_url }}" rel="noreferrer" target="_blank">Supporting source</a>@endif</li>@endforeach</ul></details>@endif
@endif</section>
@endsection
