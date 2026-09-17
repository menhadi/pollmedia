<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
@php
$canonical = route('census.1981');
$breadcrumbs = ['India'=>route('home'), 'Pilibhit district'=>route('places.show',['type'=>'district','slug'=>'pilibhit']), 'Census 1981'=>$canonical];
@endphp
@include('seo-metadata', ['seoTitle'=>'Pilibhit Census 1981: district, tahsil and village sources | Pollmedia','seoDescription'=>'Browse verified 1981 Pilibhit district and tahsil population and households, with official village index and Census table links.'])
<link rel="stylesheet" href="/css/villages.css">
<style>.edition-shell{max-width:1100px;margin:auto;padding:30px 22px}.edition-card{background:white;border:1px solid #d8e2d9;border-radius:14px;padding:24px;margin:24px 0}.edition-filters{display:flex;flex-wrap:wrap;gap:18px;align-items:end}.edition-filters label{display:block}.edition-filters select,.edition-filters button{font:inherit;padding:10px;border-radius:6px;max-width:100%}.edition-filters button{background:#176c55;color:white;border:0}.edition-table{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #d8e2d9}.edition-note{color:#52675e;font-size:14px}h1{font-size:clamp(28px,4vw,46px);line-height:1.15}a{color:#176c55}</style></head>
<body><main class="edition-shell"><a href="{{ route('places.show',['type'=>'district','slug'=>'pilibhit']) }}">Pollmedia / Pilibhit district</a>
<h1>Pilibhit Census 1981</h1><p>Population, households and historical village source tables</p><p>{{ $data['scope'] }}</p>
<form class="edition-filters" method="get" action="{{ route('census.1981') }}">
<div><label for="geography">Historical district / tahsil</label><select id="geography" name="geography">@foreach($data['geographies'] as $entry)<option value="{{ $entry['key'] }}" @selected($geography===$entry['key'])>{{ $entry['name'] }}</option>@endforeach</select></div>
<div><label for="area">Population coverage</label><select id="area" name="area">@foreach(['total'=>'Total','rural'=>'Rural','urban'=>'Urban'] as $key=>$label)<option value="{{ $key }}" @selected($area===$key)>{{ $label }}</option>@endforeach</select></div><button>Show data</button></form>
<section class="edition-card"><h2>{{ $selected['name'] }} / {{ ucfirst($area) }}</h2><p class="edition-note">1981 source location code: {{ $selected['code'] }}. Historical codes are scoped to this edition and geography.</p><div class="edition-table"><table><caption>{{ $data['source_locator'] }}</caption><thead><tr><th>Persons</th><th>Male</th><th>Female</th><th>Households</th></tr></thead><tbody>@foreach($rows as $row)<tr>@foreach(['population','male','female','households'] as $field)<td>{{ $row[$field] === null ? 'Withheld: source discrepancy' : number_format($row[$field]) }}</td>@endforeach</tr>@endforeach</tbody></table></div>
@foreach($rows as $row)@if(isset($row['quality_note']))<p role="note">{{ $row['quality_note'] }}</p>@endif
@endforeach
<p><a href="{{ $data['source_url'] }}#page=36" target="_blank" rel="noreferrer">View official summary table (PDF page 36)</a></p></section>
<section class="edition-card" id="historical-villages"><h2>1981 village records / source coverage</h2>
@if($villageData)
<p>{{ $villageData['coverage_by_tahsil'][$geography] ?? $villageData['coverage'] }}</p>
@if($villageOptions->isNotEmpty())
<p>These are rural village records, independent of the total/rural/urban summary filter above.</p>
<form class="edition-filters" method="get" action="{{ route('census.1981') }}#historical-villages">
<input type="hidden" name="geography" value="{{ $geography }}"><input type="hidden" name="area" value="{{ $area }}">
<div><label for="village">Historical village (1981)</label><select id="village" name="village"><option value="">All {{ $villageOptions->count() }} source records</option>
@foreach($villageOptions as $village)<option value="{{ $village['code'] }}" @selected($villageCode===$village['code'])>{{ $village['name'] }} / {{ $village['code'] }}</option>@endforeach
</select></div><button>Show village records</button></form>
<div class="edition-table"><table><caption>1981 / {{ $selected['name'] }} / {{ $villageRows->count() }} source records shown</caption><thead><tr><th scope="col">Village / historical code</th><th scope="col">Source status</th><th scope="col">Persons</th><th scope="col">Male</th><th scope="col">Female</th><th scope="col">Households</th><th scope="col">Official source</th></tr></thead><tbody>
@foreach($villageRows as $village)<tr><th scope="row">{{ $village['name'] }} / {{ $village['tahsil_code'] }}:{{ $village['code'] }}</th><td>{{ ucfirst($village['status']) }}@if(isset($village['quality_note']))<p class="edition-note">{{ $village['quality_note'] }}</p>@endif</td>
@foreach(['population','male','female','households'] as $field)<td>{{ $village[$field] === null ? (isset($village['quality_note']) && !($village['tahsil'] === 'puranpur' && $village['code'] === '372') ? 'Withheld: see note' : 'Not tabulated') : number_format($village[$field]) }}</td>@endforeach
<td><a href="{{ $villageData['source_url'] }}#page={{ $village['pdf_page'] }}" target="_blank" rel="noreferrer">Population page {{ $village['pdf_page'] }}</a><br><a href="{{ $villageData['source_url'] }}#page={{ $village['name_pdf_page'] }}" target="_blank" rel="noreferrer">Name/code page {{ $village['name_pdf_page'] }}</a></td></tr>@endforeach
</tbody></table></div>@if($geography === 'puranpur' && !empty($villageData['forest_ranges']))
<h3>Forest ranges / separately listed in the source</h3><p>These six ranges are not included in the 388 numbered village entries or the village dropdown.</p>
<div class="edition-table"><table><caption>Forest ranges / PDF pages 191-192</caption><thead><tr><th scope="col">Range</th><th scope="col">Status</th><th scope="col">Persons</th><th scope="col">Male</th><th scope="col">Female</th><th scope="col">Households</th></tr></thead><tbody>
@foreach($villageData['forest_ranges'] as $range)<tr><th scope="row">{{ $range['name'] }} / {{ $range['code'] }}</th><td>{{ ucfirst($range['status']) }}</td>
@foreach(['population','male','female','households'] as $field)<td>{{ $range[$field] === null ? 'Not tabulated' : number_format($range[$field]) }}</td>@endforeach
</tr>@endforeach
</tbody></table></div><p><a href="{{ $villageData['source_url'] }}#page=191" target="_blank" rel="noreferrer">Official forest range population table</a> / <a href="{{ $villageData['source_url'] }}#page=192" target="_blank" rel="noreferrer">Range names and codes</a></p>
@endif
<p class="edition-note">Source spellings are retained. Uninhabited rows have no numeric counts printed; these cells are shown as not tabulated, not zero. No current village or electoral mapping is implied.</p>
@else
<p>Select Puranpur or Bisalpur tahsil above to browse imported village records. Pilibhit village transcription remains pending; its official PDF links are available below.</p>
@endif
@else
<p>Verified village transcription has not yet been imported.</p>
@endif
</section>
<section class="edition-card"><h2>Village records in the official handbook</h2>
@if(isset($selected['index_page']))<p>Open the {{ $selected['name'] }} village index, then use its historical location code to find the village in the population tables.</p><p><a href="{{ $data['source_url'] }}#page={{ $selected['index_page'] }}" target="_blank" rel="noreferrer">Alphabetical village index / PDF page {{ $selected['index_page'] }}</a></p><p><a href="{{ $data['source_url'] }}#page={{ $selected['table_page'] }}" target="_blank" rel="noreferrer">Village population tables / PDF page {{ $selected['table_page'] }}</a></p>
@else<p>Select a historical tahsil above to open its village index and population tables.</p>@endif
<p class="edition-note">The village tables are publicly available in the official PDF. Puranpur's numbered village section is transcribed above with cell-level quality notes. Bisalpur transcription is partial; Pilibhit village transcription remains pending. The table above retains historical identifiers. No 1981 village has been matched to a current village code, block, gram panchayat, PC or AC.</p></section>
<section class="edition-card"><h2>Official reference and data quality</h2><p><a href="{{ $data['landing'] }}" target="_blank" rel="noreferrer">Census of India 1981, Pilibhit District Census Handbook, Part XIII-B</a>. Published 1983; source checked {{ $data['checked_on'] }}.</p><p>{{ $data['extraction_method'] }}</p><p>The district rural female cell has a source discrepancy and is withheld. Missing or disputed figures are never displayed as zero.</p><p class="edition-note" style="overflow-wrap:anywhere">Archived file SHA-256: {{ $data['sha256'] }}</p></section>
</main></body></html>
