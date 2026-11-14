@if(!empty($boardArea))
@once<link rel="stylesheet" href="{{ asset('css/local-board.css') }}?v={{ substr(hash_file('sha256', public_path('css/local-board.css')), 0, 12) }}">@endonce
<a class="local-board-place-link" href="{{ route('local-board.index', ['area' => $boardArea, 'lang' => app()->getLocale() === 'hi' ? 'hi' : null]) }}">{{ app()->getLocale() === 'hi' ? 'स्थानीय काम और सेवाएँ' : 'Local jobs & services' }} →</a>
@endif
