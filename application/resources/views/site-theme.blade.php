@php $site=app(\App\Services\SiteSettings::class)->appearance(); @endphp
<style>:root{@foreach(['primary','background','surface','text','muted','border','accent','header_background','header_text'] as $tone)--site-{{ $tone }}:{{ $site[$tone] }};@endforeach
@foreach(config('site.palette',[]) as $key=>$value)--palette-{{ $key }}:{{ $site['palette'][$key]??$value }};@endforeach
}</style>
@if($site['favicon'])<link rel="icon" href="{{ $site['favicon'] }}">@else<link rel="icon" href="{{ asset('pollmedia-profile.png') }}" type="image/png">@endif
<link rel="stylesheet" href="{{ asset('css/site-controls.css') }}?v={{ substr(hash_file('sha256', public_path('css/site-controls.css')),0,12) }}"><script src="{{ asset('js/site-controls.js') }}?v={{ substr(hash_file('sha256', public_path('js/site-controls.js')),0,12) }}" defer></script>
<style>{!! $site['css']??'' !!}</style>
