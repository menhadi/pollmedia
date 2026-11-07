@if($gp)
<a href="{{ route('gram-panchayats.show',['state'=>$gp['state'],'gp'=>$gp['gp'],'lang'=>app()->getLocale()==='hi'?'hi':null]) }}">{{ $gp['name'] }}</a>
@else
<span aria-label="{{ app()->getLocale()==='hi'?'सत्यापित संबंध उपलब्ध नहीं':'Verified link unavailable' }}">—</span>
@endif
