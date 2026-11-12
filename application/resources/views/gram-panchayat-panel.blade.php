@php
    $gpSub = ($gpSubdistrict ?? null) ?: null;
    $gpChoices = app(\App\Services\GramPanchayatDirectory::class)->areaChoices($gpScope, $gpState, $gpDistrict, $gpSub);
    $gpHi = ($language ?? app()->getLocale()) === 'hi' || request('lang') === 'hi';
    $gpParams = array_filter(['scope'=>$gpScope,'state'=>$gpState,'district'=>$gpDistrict,'subdistrict'=>$gpSub,'lang'=>$gpHi?'hi':null]);
@endphp
@if($gpChoices)
<section id="gram-panchayats" class="panel place-panel" data-compact-group="Panchayats">
    <h2>{{ $gpHi ? 'ग्राम पंचायत' : 'Gram Panchayats' }}</h2>
    <script type="application/json" data-gp-choices>{!! json_encode($gpChoices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <div data-gp-browser><ul>
        @foreach(array_slice($gpChoices, 0, 8) as $gp)
            <li><a href="{{ app(\App\Services\GramPanchayatUrls::class)->link($gp['state'], $gp['name'] ?? null, $gp['gp'], ['lang'=>$gpHi?'hi':null]) }}">{{ $gp['name'] }}</a></li>
        @endforeach
    </ul></div>
    <a href="{{ route('gram-panchayats.area', $gpParams) }}">{{ $gpHi ? 'इस क्षेत्र की पंचायत निर्देशिका खोलें' : 'Open the Panchayat directory for this area' }} →</a>
</section>
@endif
