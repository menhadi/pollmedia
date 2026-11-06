@once
<link rel="stylesheet" href="{{ asset('css/mobile-map-controls.css').'?v='.substr(hash_file('sha256',public_path('css/mobile-map-controls.css')),0,12) }}">
<script src="{{ asset('js/mobile-map-controls.js').'?v='.substr(hash_file('sha256',public_path('js/mobile-map-controls.js')),0,12) }}" defer></script>
@endonce
