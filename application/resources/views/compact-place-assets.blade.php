@once
<link rel="stylesheet" data-compact-place-assets href="{{ asset('css/compact-place.css') }}?v={{ substr(hash_file('sha256', public_path('css/compact-place.css')), 0, 12) }}">
<script type="module" src="{{ asset('js/compact-place.js') }}?v={{ substr(hash_file('sha256', public_path('js/compact-place.js')), 0, 12) }}"></script>
<link rel="stylesheet" href="{{ asset('css/approved-place-design.css') }}?v={{ substr(hash_file('sha256',public_path('css/approved-place-design.css')),0,12) }}">
<script type="module" src="{{ asset('js/approved-place-design.js') }}?v={{ substr(hash_file('sha256',public_path('js/approved-place-design.js')),0,12) }}"></script>
@endonce
