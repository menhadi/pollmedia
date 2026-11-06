@php
    $rainStored = null;
    if (count($factCodes ?? []) === 1 && is_file(public_path('maps/pilibhit-rainfall.json'))) {
        $rainPilot = json_decode(file_get_contents(public_path('maps/pilibhit-rainfall.json')), true);
        $rainSource = $rainPilot['lgd_to_source'][(string) $factCodes[0]] ?? null;
        if (is_string($rainSource) || is_int($rainSource)) { $rainStored = $rainPilot['villages'][(string) $rainSource] ?? null; }
    }
    $rainShapes = [];
    if (count($factCodes ?? []) === 1) {
        $rainQuery = app(\App\Services\ElectoralVillageMap::class)->database()->prepare('SELECT DISTINCT s.file,s.feature_index FROM shapes s JOIN directory d ON d.lgd=s.lgd AND d.state=s.state WHERE s.lgd=?');
        $rainQuery->execute([(string) $factCodes[0]]);
        $rainShapes = $rainQuery->fetchAll(\PDO::FETCH_ASSOC);
    }
@endphp
<section class="panel coverage" id="village-rainfall-annual" data-village-rainfall>
<details class="place-data-fold"><summary>Rainfall · 2023 &amp; 2024 · annual area estimates</summary>
@if($rainStored)
<p>Annual IMD grid area estimates from the existing linked village rainfall record.</p><div class="table-wrap"><table><thead><tr><th>Rainfall year</th><th>Annual rainfall (mm)</th></tr></thead><tbody>@foreach($rainStored as $rainYear => $rainValues)<tr><td>{{ $rainYear }}</td><td>{{ isset($rainValues['annual_mm']) ? number_format($rainValues['annual_mm'], 2) : 'Unavailable' }}</td></tr>@endforeach</tbody></table></div>
@else
<p data-rainfall-status>{{ count($rainShapes) === 1 ? 'Open to calculate rainfall from the existing IMD grids.' : (count($rainShapes) > 1 ? 'Rainfall unavailable: multiple mapped shapes require identity review.' : 'Rainfall unavailable: no unique verified village geometry is linked.') }}</p>
@if(count($rainShapes) === 1)<script type="application/json" data-rainfall-shape>{!! json_encode(['url'=>route('electoral-villages.geometry',['layer'=>hash('sha256',$rainShapes[0]['file'])]),'index'=>(int)$rainShapes[0]['feature_index']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>@endif
@endif
</details></section>
<script type="module" src="{{ asset('js/rainfall-village-annual.js') }}?v={{ substr(hash_file('sha256',public_path('js/rainfall-village-annual.js')),0,12) }}"></script>
