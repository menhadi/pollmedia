@php
$chandigarhElectoral = json_decode(file_get_contents(database_path('fixtures/chandigarh-electoral-context.json')), true, 512, JSON_THROW_ON_ERROR);
@endphp
<section id="elections" class="panel"><p class="eyebrow">Chandigarh electoral context</p><h2>Election results &amp; history</h2>
<p><a href="{{ $chandigarhElectoral['pc']['url'] }}">Chandigarh - Lok Sabha PC 1: 2024 results and available election history</a></p>
<p>Assembly constituencies: not applicable. Chandigarh has no Legislative Assembly. The dated ECI seat allocation records one parliamentary seat. District Census figures and source-map geometry retain their own editions; no population is assigned to constituency boundaries.</p>
<p class="source-note"><a href="{{ $chandigarhElectoral['source_url'] }}">ECI delimitation order, 17 December 2008</a> - Schedule I, printed pages 4-5; Schedule II, printed pages 6-7.</p></section>
