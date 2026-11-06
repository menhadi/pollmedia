@if(request()->boolean('embed') || request()->header('Sec-Fetch-Dest') === 'iframe')
<link rel="stylesheet" href="{{ asset('css/my-place-embed.css') }}?v={{ substr(hash_file('sha256',public_path('css/my-place-embed.css')),0,12) }}"><script src="{{ asset('js/my-place-embed.js') }}?v={{ substr(hash_file('sha256',public_path('js/my-place-embed.js')),0,12) }}" defer></script>
@endif