@extends('seo-layout')
@section('content')
<style>.import-table{overflow:auto}.import-table table{border-collapse:collapse;font-size:13px;min-width:100%}.import-table th,.import-table td{padding:9px 12px;border:1px solid #d5dfd5;text-align:left;max-width:300px;min-width:100px;overflow-wrap:anywhere}.import-table th{background:#edf3e9}</style>
<a href="{{ route('imports.index') }}">Back to data imports</a><h1>{{ $connector->name }}</h1><p>Run #{{ $record->id }} / {{ str_replace('_',' ',$record->status) }} / {{ $record->origin }} / {{ $record->created_at }} UTC</p>
<p><a class="url" href="{{ $record->source_url }}" target="_blank" rel="noreferrer">Official source URL</a></p>
@if($record->raw_path)
<p><a href="{{ route('imports.download',$record->id) }}">Download archived source file</a></p><p class="url muted">SHA-256: {{ $record->sha256 }}</p>
@endif
@if($record->error)
<p class="notice error">{{ $record->error }}</p>
@endif
@if(isset($summary['same_as_run']))
<p class="notice">The source bytes have not changed since <a href="{{ route('imports.run',$summary['same_as_run']) }}">run #{{ $summary['same_as_run'] }}</a>. Any earlier pending review still applies.</p>
@endif
@if($data)
@if($connector->record_key === 'Town/Village' && $connector->format === 'xlsx')
<section class="card"><h2>Census publication</h2><p>Preview mapped village figures against the public Census 2011 edition, then publish reviewed changes.</p><a class="button" href="{{ route('imports.census', $record->id) }}">Preview Census publication</a></section>
@endif
<section class="card"><h2>Extraction and differences</h2><p>{{ $data['scope'] }}</p><p>Compared with {{ $record->base_run_id ? 'reviewed run #'.$record->base_run_id : 'no previous reviewed baseline' }}.</p>
<p><strong>{{ $summary['rows'] }}</strong> rows / {{ $summary['added'] }} added / {{ $summary['changed'] }} changed / {{ $summary['removed'] }} absent from this snapshot.</p>
<p>{{ $summary['missing_keys'] }} missing codes / {{ $summary['duplicate_keys'] }} duplicate codes / {{ $summary['schema_changed'] ? 'Column layout changed' : 'No column-layout change detected' }}</p>
@if(!$summary['key_column_present'])
<p class="notice error">Configured code column was not found. This snapshot cannot be accepted.</p>
@endif
@if(isset($summary['matched_places']))
<p>{{ $summary['matched_places'] }} rows match existing place identifiers in {{ $summary['identifier_scope'] }}. Unmatched rows remain unlinked.</p>
@endif
<details><summary>Changed record codes (up to 100 per group)</summary>@foreach(['added','changed','removed'] as $kind)<p><strong>{{ ucfirst($kind) }}:</strong> {{ implode(', ', $summary[$kind.'_codes']) ?: 'None' }}</p>@endforeach</details>
<p class="muted">An absent row is not automatically a deleted place or a changed boundary. Check coverage and the archived source, especially for PDF extraction.</p></section>
<section class="card"><h2>Extracted table</h2><div class="import-table"><table><thead><tr>@foreach($data['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@foreach($rows as $row)<tr>@foreach($data['headers'] as $header)<td>{{ $row[$header] ?? '' }}</td>@endforeach</tr>@endforeach</tbody></table></div>{{ $rows->links() }}</section>
@if($record->status==='needs_review')
<section class="card"><h2>Review snapshot</h2><p>Accepting records this table as the comparison baseline. It does not publish raw data or replace existing Census, election or officer records.</p><form method="post" action="{{ route('imports.review',$record->id) }}" class="actions">@csrf<button name="decision" value="accept" @disabled(!$summary['key_column_present'] || $summary['missing_keys'] || $summary['duplicate_keys'])>Accept reviewed baseline</button><button class="secondary" name="decision" value="reject">Reject snapshot</button></form></section>
@endif
@endif
@endsection
