@extends('seo-layout')
@section('content')
<h1>Official data imports</h1><p><a href="{{ route('official-hosts.index') }}">Manage official source hosts for any country</a></p><p>Connect a public official URL once. Pollmedia can download its latest file or API response, extract a table and flag changes for review.</p>
<section class="card"><h2>Historical Census data</h2><p>Explore verified archive editions, historical population tables and village-data coverage.</p><a class="button" href="{{ route('census.archive') }}">Census history & archives</a></section>
<section class="card"><h2>Election results</h2><p>Preview and publish verified PC and AC report editions, with candidate votes, parties, margins and rollback history.</p><a class="button" href="{{ route('election-imports.index') }}">Election imports & publishing</a></section>
<p class="notice">Automatic collection prepares staging snapshots. Reviewed snapshots become comparison baselines; publishing into election, Census or officeholder pages needs a dataset-specific mapping. PDF tables require visual review; scanned PDFs, paginated APIs and CAPTCHA downloads may need a dedicated connector or manual upload.</p>
<details class="card"><summary><strong>Add an official source</strong></summary>
<form method="post" action="{{ route('imports.store') }}">@csrf
<div class="field"><label for="name">Source name</label><input type="text" id="name" name="name" required maxlength="150" value="{{ old('name') }}"></div>
<div class="field"><label for="url">Direct official file or API URL</label><input style="width:100%" type="url" id="url" name="url" required value="{{ old('url') }}"><small class="muted">Public HTTPS approved official URLs. Use the actual export endpoint, not a catalogue page. APIs requiring keys are not connected by this form.</small></div>
<div class="filters"><div><label for="format">Format</label><select id="format" name="format">@foreach(['json'=>'JSON API / file','csv'=>'CSV (UTF-8)','xls'=>'Legacy Excel (.xls)','xlsx'=>'Excel (.xlsx)','pdf'=>'PDF tables'] as $value=>$label)<option value="{{ $value }}" @selected(old('format')===$value)>{{ $label }}</option>@endforeach</select></div><div><label for="record_key">Unique record-code column</label><input id="record_key" type="text" name="record_key" required value="{{ old('record_key') }}" placeholder="Exact column name"></div></div>
<p class="muted">Use the official record identifier. If codes repeat across years or areas, provide a source with a unique composite code. Names are never used to join places.</p>
<div class="filters"><div><label for="sheet">Excel worksheet (optional)</label><input type="text" id="sheet" name="sheet" value="{{ old('sheet') }}" placeholder="Blank selects first worksheet"></div><div><label for="json_path">JSON records path (optional)</label><input type="text" id="json_path" name="json_path" value="{{ old('json_path') }}" placeholder="e.g. records or data.records"></div></div>
<div class="filters"><div><label for="header_row">Header row for tables</label><input type="number" id="header_row" name="header_row" min="1" max="100" value="{{ old('header_row',1) }}" required></div><div><label for="table_index">PDF table number on each page</label><input type="number" id="table_index" name="table_index" min="1" max="20" value="{{ old('table_index',1) }}" required></div></div>
<div class="filters"><div><label for="filter_column">Filter column (optional)</label><input type="text" id="filter_column" name="filter_column" value="{{ old('filter_column') }}" placeholder="e.g. Level"></div><div><label for="filter_value">Exact filter value</label><input type="text" id="filter_value" name="filter_value" value="{{ old('filter_value') }}" placeholder="e.g. VILLAGE"></div></div>
<div class="field"><label for="identifier">Match existing place identifiers (optional)</label><select style="width:100%" id="identifier" name="identifier"><option value="">Keep source records only</option>@foreach($identifiers as $identifier)<option @selected(old('identifier')===$identifier)>{{ $identifier }}</option>@endforeach</select></div>
<p><label><input type="checkbox" name="automatic" value="1" @checked(old('automatic',1))>Check automatically each day</label></p><p class="muted">Daily checks use the application scheduler. Limits: 20 MB, normally 20,000 rows and 100 PDF pages. Larger national workbooks are split into explicit source filters before staging.</p><button>Save source configuration</button></form></details>
<section><h2>Configured sources</h2>
@forelse($connectors as $connector)
<article class="card"><h3>{{ $connector->name }}</h3><a class="url" href="{{ $connector->url }}" target="_blank" rel="noreferrer">Official source</a><p class="muted">{{ strtoupper($connector->format) }} / Code column: {{ $connector->record_key }} / {{ $connector->automatic ? 'Daily checks enabled' : 'Manual only' }} / {{ $connector->pending }} awaiting review</p>
@if($connector->latest)
<p>Last result: <a href="{{ route('imports.run',$connector->latest->id) }}">{{ str_replace('_',' ',$connector->latest->status) }}</a> / {{ $connector->latest->created_at }} UTC</p>
@endif
<div class="actions"><form method="post" action="{{ route('imports.fetch',$connector->id) }}">@csrf<button>Fetch URL now</button></form><form method="post" action="{{ route('imports.schedule',$connector->id) }}">@csrf<input type="hidden" name="automatic" value="{{ $connector->automatic ? 0 : 1 }}"><button class="secondary">{{ $connector->automatic ? 'Pause automatic checks' : 'Enable daily checks' }}</button></form></div>
<details><summary>Upload an official export instead</summary><form method="post" enctype="multipart/form-data" action="{{ route('imports.upload',$connector->id) }}">@csrf<label for="file-{{ $connector->id }}">{{ strtoupper($connector->format) }} file from this source</label><input type="file" id="file-{{ $connector->id }}" name="file" accept=".{{ $connector->format }}" required><p><button>Extract uploaded file</button></p></form></details></article>
@empty
<p>No sources configured yet. Add a direct URL above to start.</p>
@endforelse
</section><section class="card"><h2>Import history</h2>
@forelse($runs as $run)
<p><a href="{{ route('imports.run',$run->id) }}">#{{ $run->id }} / {{ $run->name }} / {{ str_replace('_',' ',$run->status) }}</a> <span class="muted">{{ $run->created_at }} UTC</span></p>
@empty
<p>No downloads or uploads yet.</p>
@endforelse
{{ $runs->links() }}</section>
@endsection
