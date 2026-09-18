@extends($admin ? 'seo-layout' : 'geography-layout')
@section('title', 'India Census data')
@section('content')
<h1>India Census data</h1><p>Explore recorded population and household figures using official Census geography. Census boundaries and names can differ between years and from today's administrative areas.</p>
<p><a href="{{ route('census.national-history') }}">Historical population: 1901 to 2011, with original source notes</a></p>
<p><a href="{{ route('census.source-tables') }}">Historical Census source tables: original columns, definitions and district filters</a></p>
@if($admin)
<details class="card"><summary>Prepare an imported table</summary><p>Preparation creates a private preview. Publish extracted data with any discrepancy notes; baseline acceptance is a separate review action. Large tables can also be prepared with <code>php -d memory_limit=512M artisan census:prepare --publish</code>.</p>
@forelse($runs as $run)<form method="post" action="{{ route('census-catalogue.prepare', $run->id) }}" class="row">@csrf<p>#{{ $run->id }} · {{ $run->name }} · {{ $run->status }} · <a href="{{ route('imports.run', $run->id) }}">Review source import</a></p><button>Prepare preview</button></form>@empty<p>No supported extracted imports are ready.</p>@endforelse
</details>
@endif
<form class="card filters" method="get"><div><label for="edition">Census year and source table</label><select name="edition" id="edition">@foreach($editions as $option)<option value="{{ $option->id }}" @selected($edition?->id === $option->id)>{{ $option->year }} · {{ $option->name }} · {{ $option->status === 'superseded' ? 'Earlier snapshot' : $option->status }} · #{{ $option->id }}</option>@endforeach</select></div><button @disabled($editions->isEmpty())>Load table</button></form>
@if($edition)
@if($edition->status === 'superseded')<p class="notice">This is an earlier published snapshot. A newer snapshot is available in the table selector.</p>@endif
<p class="notice">{{ $edition->scope }} No totals are calculated across overlapping geographic units or between Total, Rural and Urban rows.</p>
<form method="get" class="card filters"><input type="hidden" name="edition" value="{{ $edition->id }}">
<div><label for="state">State (Census edition)</label><select name="state" id="state"><option value="">All states / India</option>@foreach($states as $area)<option value="{{ $area->state_code }}" @selected(($input['state'] ?? '') === $area->state_code)>{{ $area->name }} · {{ $area->state_code }}</option>@endforeach</select></div>
<div><label for="district">District</label><select name="district" id="district"><option value="">All districts / state totals</option>@foreach($districts as $area)<option value="{{ $area->district_code }}" @selected(($input['district'] ?? '') === $area->district_code)>{{ $area->name }} · {{ $area->district_code }}</option>@endforeach</select></div>
<div><label for="level">Area type</label><select name="level" id="level"><option value="">All recorded types</option>@foreach($levels as $option)<option @selected(($input['level'] ?? '') === $option)>{{ $option }}</option>@endforeach</select></div>
<div><label for="residence">Residence</label><select name="residence" id="residence"><option value="">All residence categories</option>@foreach($residences as $option)<option @selected(($input['residence'] ?? '') === $option)>{{ $option }}</option>@endforeach</select></div>
<div><label for="field">Measure</label><select name="field" id="field">@foreach($fields as $option)<option value="{{ $option }}" @selected($field === $option)>{{ ['TOT_P'=>'Population — all persons','TOT_M'=>'Population — male','TOT_F'=>'Population — female','No_HH'=>'Households','P_LIT'=>'Literate persons','P_06'=>'Population aged 0–6'][$option] ?? $option }}</option>@endforeach</select></div><button>Show results</button></form>
<section class="card"><h2>{{ $rows->total() }} matching source records</h2><p>Measure: {{ $field }}. Other measure codes are retained exactly as defined in the official workbook's record structure.</p><div class="scroll"><table><thead><tr><th>Area and Census codes</th><th>Type</th><th>Residence</th><th>{{ $field }}</th><th>Record</th></tr></thead><tbody>
@forelse($rows as $row)
@php($values = json_decode($row->values, true))
@php($flags = json_decode($row->flags, true))
<tr><td>{{ $row->name }}<details><summary>Source geography</summary>@foreach(json_decode($row->geography, true) as $key => $value)<div>{{ $key }}: {{ $value }}</div>@endforeach</details></td><td>{{ $row->level }}</td><td>{{ $row->residence }}</td><td>{{ ($values[$field] ?? null) === null ? 'Not reported' : number_format($values[$field]) }} @if($flags)<sup>†</sup>@endif</td><td>Extracted row {{ $row->source_row }} @foreach($flags as $flag)<p>† {{ $flag }}</p>@endforeach</td></tr>
@empty<tr><td colspan="5">No records match these filters.</td></tr>@endforelse
</tbody></table></div>{{ $rows->links() }}<p class="muted">† Source values are displayed with unresolved validation notes. Missing values are not treated as zero. Row numbers refer to the extracted, filtered table, not workbook row numbers.</p></section>
<section class="card"><h2>Official evidence</h2><p><a href="{{ $edition->landing_url }}">Census catalogue and definitions</a> · <a href="{{ $edition->source_url }}">Official source workbook</a></p><p>Source retrieved {{ $edition->retrieved_at }} UTC · Census year {{ $edition->year }}</p></section>
@if($admin)
<section class="card"><h2>Publication review</h2><p>{{ $edition->row_count }} records · {{ $edition->flag_count }} records with validation notes · {{ $edition->status }}</p><a href="{{ route('imports.run', $edition->import_run_id) }}">Inspect source import</a>
@if($edition->status === 'draft')<form method="post" action="{{ route('census-catalogue.publish', $edition->id) }}">@csrf<input type="hidden" name="current" value="{{ $current }}"><p><label><input type="checkbox" name="reviewed" value="1" required> I reviewed the source, geography, scope and any † notes for publication.</label></p><button>Publish this edition</button></form>@endif
@if($edition->status === 'published')<p><a href="{{ route('census-catalogue.index', ['edition'=>$edition->id]) }}">Open public edition</a></p><form method="post" action="{{ route('census-catalogue.withdraw', $edition->id) }}">@csrf<button>Withdraw from public view</button></form>@endif
</section>
@endif
@else<p class="card">No Census tables have been published in this catalogue yet.</p>@endif
@endsection
