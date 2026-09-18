@extends('geography-layout')
@section('title', 'By-election official source archive')
@section('content')
<h1>By-election official source archive</h1>
<p>Browse collected ECI reports and their original tables. <a href="{{ route('elections.assembly') }}">Assembly general elections</a> · <a href="{{ route('elections.history') }}">Lok Sabha general elections</a>.</p>
<p class="notice">{{ $entries->count() }} catalogue entries. Coverage follows the official reporting periods, including multi-year archives. Polling-station results are a separate collection and are not implied by these reports.</p>
@if($coverage->isNotEmpty())<p>Source collections: {{ $coverage->get('collected', 0) }} collected; {{ $coverage->get('partial', 0) }} partial; {{ $coverage->get('missing_official_link', 0) }} without a usable official link; {{ $coverage->get('failed', 0) + $coverage->get('pending', 0) }} pending. Extracted tables still require structured validation.</p>@endif
<form method="get" class="card filters"><div><label for="year">Year / start of archive period</label><select id="year" name="year"><option value="">All years</option>@foreach($years as $year)<option value="{{ $year }}" @selected((string)($input['year'] ?? '') === (string)$year)>{{ $year }}</option>@endforeach</select></div><button>Choose year</button></form>
<form method="get" class="card filters">@if(isset($input['year']))<input type="hidden" name="year" value="{{ $input['year'] }}">@endif<div><label for="edition">Official report period</label><select id="edition" name="edition">@foreach($choices as $choice)<option value="{{ $choice['id'] }}" @selected(($edition['id'] ?? '') === $choice['id'])>{{ $choice['label'] }}</option>@endforeach</select></div><button @disabled($choices->isEmpty())>Open period</button></form>
@if($edition)
<section class="card"><h2>{{ $edition['label'] }}</h2>
@if($edition['url'])<p><a href="{{ $edition['url'] }}" target="_blank" rel="noopener noreferrer">Official ECI report page</a></p>@else<p class="notice">The official catalogue lists this period without a usable download link. Data collection remains pending.</p>@endif
<p>Source collection: {{ $manifest['status'] }}. {{ count($manifest['files']) }} preserved files; {{ $sources->count() }} extracted source files.</p>
@if($sources->isNotEmpty())
<form method="get" class="filters"><input type="hidden" name="edition" value="{{ $edition['id'] }}"><div><label for="source">Source table file</label><select id="source" name="source">@foreach($sources as $index => $option)<option value="{{ $option['file'] }}" @selected($source['file'] === $option['file'])>{{ $option['name'] ?? 'Source file '.($index + 1) }} — {{ $option['tables'] }} tables</option>@endforeach</select></div><button>Open source</button></form>
@endif
@if($data && $table)
<form method="get" class="filters"><input type="hidden" name="edition" value="{{ $edition['id'] }}"><input type="hidden" name="source" value="{{ $source['file'] }}"><div><label for="table">Worksheet / table</label><select id="table" name="table">@foreach($data['tables'] as $index => $option)<option value="{{ $index }}" @selected((int)($input['table'] ?? 0) === $index)>{{ $option['name'] }}</option>@endforeach</select></div><button>Show table</button></form>
<h3>{{ $table['name'] }} <a href="#data-note"><sup>†</sup></a></h3>
<p><a href="{{ $data['source_url'] }}" target="_blank" rel="noopener noreferrer">Official source</a> · Page {{ $page }} of {{ $pages }}</p>
<div class="scroll" role="region" aria-label="Original by-election table" tabindex="0"><table><tbody>@foreach($rows as $row)<tr><th scope="row">{{ $row['row'] }}</th>@foreach($row['cells'] as $cell)<td>{{ $cell === null || $cell === '' ? '—' : (is_bool($cell) ? ($cell ? 'TRUE' : 'FALSE') : $cell) }}</td>@endforeach</tr>@endforeach</tbody></table></div>
<p id="data-note" class="notice">† These are original source cells awaiting structured validation. Headings, totals and notes remain in their source rows. Blank cells appear as —; zero remains 0. Formula cells are text. Merged HTML cells are listed in source order; consult the original for their layout. Do not add totals across overlapping reports.</p>
<nav class="filters" aria-label="Source table pages">@if($page > 1)<a href="{{ route('elections.by-elections', [...$navigation, 'page' => $page - 1]) }}">Previous</a>@endif @if($page < $pages)<a href="{{ route('elections.by-elections', [...$navigation, 'page' => $page + 1]) }}">Next</a>@endif</nav>
@else<p class="notice">No extracted table is available for this selection yet. Available original reports are listed below.</p>@endif
<h3>Preserved official files</h3><ul>@foreach($manifest['files'] as $file)<li><a href="{{ route('elections.by-elections', ['edition' => $edition['id'], 'file' => $file['file']]) }}">{{ $file['name'] }}</a>@if(isset($file['source_url']) || isset($file['source_page'])) · <a href="{{ $file['source_url'] ?? $file['source_page'] }}" target="_blank" rel="noopener noreferrer">Official link</a>@endif</li>@endforeach</ul>
@if(!empty($manifest['errors']) || !empty($manifest['extraction_errors']))<p class="notice">† Some downloads or extractions remain incomplete. Available files are preserved; missing results do not mean zero votes.</p>@endif
</section>
@else<p class="notice">The by-election collection is not yet installed on this server.</p>@endif
@endsection
