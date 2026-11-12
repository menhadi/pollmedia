@if($gp)
<a href="{{ app(\App\Services\GramPanchayatUrls::class)->link($gp['state'], $gp['name'] ?? null, $gp['gp'], ['lang'=>app()->getLocale()==='hi'?'hi':null]) }}">{{ $gp['name'] }}</a>
@else
<span aria-label="{{ app()->getLocale()==='hi'?'सत्यापित संबंध उपलब्ध नहीं':'Verified link unavailable' }}">—</span>
@endif
