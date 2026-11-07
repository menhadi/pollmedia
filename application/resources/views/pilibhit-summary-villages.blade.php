@php
    $label = fn ($en, $hindi) => $hi ? $hindi : $en;
    $number = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
@endphp
<div id="pb-results" data-pb-results>
    @if($villages->isNotEmpty())
    <table class="pb-directory-table"><thead><tr><th scope="col">{{ $label('Village','गाँव') }}</th><th scope="col">{{ $label('Population','जनसंख्या') }}</th><th scope="col">{{ $label('Households','परिवार') }}</th></tr></thead><tbody>
    @foreach($villages as $v)
        <tr><th scope="row"><a href="{{ route('civic.place',['record'=>$v['id'],'lang'=>$hi?'hi':null]) }}">{{ $v['name'] }}</a></th>
        <td>{{ $v['population']===null?'—':$number->format($v['population']) }}</td><td>{{ $v['households']===null?'—':$number->format($v['households']) }}</td></tr>
    @endforeach
    </tbody></table>
    @else
    <p>{{ $label('No villages found on this page.','इस पृष्ठ पर कोई गाँव नहीं मिला।') }}</p>
    @endif
    @if($topic === 'overview')
    @php($directoryUrl = route('district-dashboard.named', array_filter(['state'=>'uttar-pradesh','district'=>'pilibhit','topic'=>'places','lang'=>$hi?'hi':null,'q'=>$search],fn($value)=>$value!==null && $value!=='')))
    <a class="pb-more" href="{{ $directoryUrl }}#places">{{ $search !== '' ? $label('View search results in the directory','निर्देशिका में खोज परिणाम देखें') : $label('Browse all villages','सभी गाँव देखें') }} →</a>
    @else
    <nav class="pb-pages" aria-label="{{ $label('Village directory pages','गाँव निर्देशिका के पृष्ठ') }}">
        <span aria-live="polite">{{ $number->format($villages->firstItem()??0) }}–{{ $number->format($villages->lastItem()??0) }} {{ $label('of','/') }} {{ $number->format($villages->total()) }}</span>
        @if($villages->previousPageUrl())<a data-pb-page href="{{ $villages->previousPageUrl() }}#places">{{ $label('Previous','पिछला') }}</a>@endif
        @if($villages->nextPageUrl())<a data-pb-page href="{{ $villages->nextPageUrl() }}#places">{{ $label('Next','अगला') }}</a>@endif
    </nav>
    @endif
</div>
