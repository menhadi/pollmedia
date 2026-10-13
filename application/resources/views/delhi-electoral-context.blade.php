@php
$delhiElectoral = json_decode(file_get_contents(database_path('fixtures/delhi-electoral-context.json')), true, 512, JSON_THROW_ON_ERROR);
$delhiAssembly = collect($delhiElectoral['assembly'])->keyBy('code');
@endphp
<section id="elections" class="panel"><p class="eyebrow">Delhi-wide constituency navigation</p><h2>Election results & history · 7 PCs and 70 ACs</h2>
<p class="notice">These are Delhi-wide links grouped by the dated ECI parliamentary membership table. They do not assert that every listed seat belongs to this Census district. District-to-constituency boundary correspondence remains unverified. District population is never assigned to constituencies.</p>
<p>Open a parliamentary group to browse its ten Assembly segments. Links open a verified archive edition; available history and original reports remain on the constituency page.</p>
@foreach($delhiElectoral['pcs'] as $pc)
<details><summary>{{ $pc['name'] }} · PC {{ $pc['code'] }} · 10 Assembly segments</summary><p><a href="{{ $pc['url'] }}">{{ $pc['name'] }} · PC {{ $pc['code'] }} election results ({{ $pc['archive_year'] }}) →</a></p><nav aria-label="{{ $pc['name'] }} Assembly segments">
@foreach($pc['ac_codes'] as $code)
@php($ac = $delhiAssembly[$code])
<p><a href="{{ $ac['url'] }}">{{ $ac['name'] }} · AC {{ $ac['code'] }} ({{ $ac['archive_year'] }}) →</a></p>
@endforeach
</nav></details>
@endforeach
<p class="source-note"><a href="{{ $delhiElectoral['source_url'] }}">ECI delimitation order, 17 December 2008</a> · printed page 556. Territorial references use 15 February 2004. Later district splits and boundaries are not backcast.</p></section>
