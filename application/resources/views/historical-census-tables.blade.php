@extends('geography-layout')
@section('title', 'Historical Census source tables')
@section('content')
<h1>Historical Census source tables</h1>
<p>Read the collected official workbooks in their original structure. Choose a year, recorded area and population group, then a worksheet or district. <a href="{{ route('census-catalogue.index') }}">Return to Census indicators</a>.</p>
<p class="notice">{{ $index['scope_note'] }} {{ $all->count() }} workbooks are available here; {{ count($index['pending']) }} catalogue entries are still awaiting collection or preparation. Missing entries do not mean zero population.</p>
<form method="get" class="card filters" action="{{ route('census.source-tables') }}">
<div><label for="year">Census year</label><select id="year" name="year"><option value="">All available years</option>@foreach($years as $year)<option value="{{ $year }}" @selected((string)($input['year'] ?? '') === (string)$year)>{{ $year }}</option>@endforeach</select></div>
<div><label for="area">State / area as recorded</label><select id="area" name="area"><option value="">All collected areas</option>@foreach($areas as $area)<option value="{{ $area }}" @selected(($input['area'] ?? '') === $area)>{{ $area }}</option>@endforeach</select></div>
<div><label for="group">Source population group</label><select id="group" name="group"><option value="">All source groups</option>@foreach($groups as $group)<option value="{{ $group }}" @selected(($input['group'] ?? '') === $group)>{{ ['SC'=>'Scheduled Castes (SC)', 'ST'=>'Scheduled Tribes (ST)'][$group] ?? $group }}</option>@endforeach</select></div><button>Find tables</button>
</form>
<form method="get" class="card filters" action="{{ route('census.source-tables') }}">
@foreach(['year','area','group'] as $filter)@if(isset($input[$filter]))<input type="hidden" name="{{ $filter }}" value="{{ $input[$filter] }}">@endif @endforeach
<div><label for="source">Collected source table</label><select id="source" name="source">@foreach($sources as $option)<option value="{{ $option['id'] }}" @selected(($source['id'] ?? null) === $option['id'])>{{ $option['name'] }}</option>@endforeach</select></div><button @disabled($sources->isEmpty())>Open table</button>
</form>
@if($metadata && $sheet)
<section class="card"><h2>{{ $metadata['name'] }}</h2><p><a href="{{ $metadata['landing'] }}" target="_blank" rel="noopener noreferrer">Official catalogue and definitions</a> · <a href="{{ $metadata['source_url'] }}" target="_blank" rel="noopener noreferrer">Official Excel workbook</a></p>
<p class="muted">Collected {{ $metadata['retrieved_at'] }}. Source names and codes belong to this Census edition; current administrative or electoral mappings are not implied.</p>
<form method="get" class="filters" action="{{ route('census.source-tables') }}"><input type="hidden" name="source" value="{{ $source['id'] }}"><div><label for="sheet">Worksheet (including source definitions)</label><select id="sheet" name="sheet">@foreach($metadata['sheets'] as $number => $option)<option value="{{ $number }}" @selected($sheetNumber === $number)>{{ $option['name'] }}</option>@endforeach</select></div><button>Choose worksheet</button></form>
@if($sheet['districts'])<form method="get" class="filters" action="{{ route('census.source-tables') }}"><input type="hidden" name="source" value="{{ $source['id'] }}"><input type="hidden" name="sheet" value="{{ $sheetNumber }}"><div><label for="district">District name as recorded</label><select id="district" name="district"><option value="">All source districts / areas</option>@foreach($sheet['districts'] as $district)<option value="{{ $district['id'] }}" @selected(($input['district'] ?? '') === $district['id'])>{{ $district['name'] }}</option>@endforeach</select></div><button>Show district</button></form>@endif
</section>
<section class="card"><h2>{{ $sheet['name'] }}</h2><p>{{ number_format($rowCount) }} source rows below the heading row; page {{ $page }} of {{ max(1, $pageCount) }}. Blank cells are shown as “Not reported”; zero remains zero. Scroll horizontally to see all original columns.</p>
<p><a href="{{ route('census.source-tables', [...$navigation, 'page'=>$page, 'format'=>'csv']) }}">Download this page (CSV)</a></p>
<div class="scroll" role="region" aria-label="Historical Census source table" tabindex="0"><table><thead><tr><th scope="col">Workbook row</th>@foreach($sheet['headers'] as $column => $heading)<th scope="col">{{ $heading === null || $heading === '' ? 'Column '.($column + 1).' (blank heading)' : $heading }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr><th scope="row">{{ $row['source_row'] }}@if($row['flags']) <a href="#source-notes" aria-label="See source note for row {{ $row['source_row'] }}"><sup>†</sup></a>@endif</th>@foreach($sheet['headers'] as $column => $heading)<td>{{ ($row['cells'][$column] ?? null) === null || ($row['cells'][$column] ?? null) === '' ? 'Not reported' : (is_bool($row['cells'][$column]) ? ($row['cells'][$column] ? 'TRUE' : 'FALSE') : $row['cells'][$column]) }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($sheet['headers']) + 1 }}">No rows in this worksheet or selection.</td></tr>@endforelse
</tbody></table></div>
@if($notes)<section id="source-notes"><h3>† Source notes on this page</h3>@foreach($notes as $note => $numbers)<p>Rows {{ implode(', ', $numbers) }}: {{ $note }}</p>@endforeach</section>@endif
<nav aria-label="Table pages" class="filters">@if($page > 1)<a href="{{ route('census.source-tables', [...$navigation, 'page'=>$page-1]) }}">Previous page</a>@endif @if($page < $pageCount)<a href="{{ route('census.source-tables', [...$navigation, 'page'=>$page+1]) }}">Next page</a>@endif</nav>
<p class="muted">Heading cells come from workbook row {{ $sheet['header_source_row'] }}. All source columns and review notes are retained. Formula cells are displayed as text.</p>
</section>
@else<p class="notice">No prepared source tables match this selection.</p>@endif
@if($index['pending'])<details class="card"><summary>{{ count($index['pending']) }} catalogue entries awaiting collection or preparation</summary><ul>@foreach($index['pending'] as $entry)<li><a href="{{ $entry['landing'] }}" target="_blank" rel="noopener noreferrer">{{ $entry['name'] }}</a></li>@endforeach</ul></details>@endif
@endsection
