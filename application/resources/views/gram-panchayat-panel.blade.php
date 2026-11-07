@php
    $gpSub = ($gpSubdistrict ?? null) ?: null;
    $gpPage = app(\App\Services\GramPanchayatDirectory::class)->listing($gpScope, $gpState, $gpDistrict, $gpSub, '', 1, 8);
    $gpHi = ($language ?? app()->getLocale()) === 'hi' || request('lang') === 'hi';
    $gpParams = array_filter(['scope'=>$gpScope,'state'=>$gpState,'district'=>$gpDistrict,'subdistrict'=>$gpSub,'lang'=>$gpHi?'hi':null]);
@endphp
@if($gpPage['items'])
<section id="gram-panchayats" class="panel place-panel" data-compact-group="Panchayats">
    <h2>{{ $gpHi ? 'ग्राम पंचायत' : 'Gram Panchayats' }}</h2>
    <ul>
        @foreach($gpPage['items'] as $gp)
            <li><a href="{{ route('gram-panchayats.show', ['state'=>$gp['state'],'gp'=>$gp['gp'],'lang'=>$gpHi?'hi':null]) }}">{{ $gp['name'] }}</a></li>
        @endforeach
    </ul>
    <a href="{{ route('gram-panchayats.area', $gpParams) }}">{{ $gpHi ? 'इस क्षेत्र की पंचायत निर्देशिका खोलें' : 'Open the Panchayat directory for this area' }} →</a>
</section>
@endif
