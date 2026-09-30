@php $site=app(\App\Services\SiteSettings::class)->appearance(); @endphp
<style>:root{@foreach(['primary','background','surface','text','muted','border','accent'] as $tone)--site-{{ $tone }}:{{ $site[$tone] }};@endforeach
@foreach(config('site.palette',[]) as $key=>$value)--palette-{{ $key }}:{{ $site['palette'][$key]??$value }};@endforeach
}</style>
@if($site['favicon'])<link rel="icon" href="{{ $site['favicon'] }}">@endif
<link rel="stylesheet" href="{{ asset('css/site-controls.css') }}?v=1"><script src="{{ asset('js/site-controls.js') }}?v=1" defer></script>
<style>{!! $site['css']??'' !!}</style>
