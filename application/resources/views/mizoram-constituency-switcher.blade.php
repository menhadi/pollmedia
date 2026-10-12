@if($state==='Mizoram' && !$exactSeatOnly)
@php($mizoramSwitchFixture=json_decode(file_get_contents(database_path('fixtures/mizoram-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR))
<link rel="stylesheet" href="{{ asset('css/constituency-dashboard-switcher.css') }}">
<nav class="constituency-dashboard-switcher" aria-label="Mizoram district and constituency dashboards">
<a href="{{ route('district-dashboard-directory') }}">Change district</a>
<label>Change constituency<select data-constituency-switcher><option value="" selected>{{ strtoupper($kind) }} · {{ $name }}</option>
@foreach(['pcs'=>'pc','district_rows'=>'ac','separate_electoral_constituencies'=>'ac'] as $mizoramGroup=>$mizoramKind)
@foreach($mizoramSwitchFixture[$mizoramGroup]??[] as $mizoramOption)
<option value="{{ route('constituency.overview',['kind'=>$mizoramKind,'state'=>'Mizoram','name'=>trim(preg_replace('/\s*\((?:BL|SC|ST)\)\s*$/i','',$mizoramOption['name']))],false) }}">{{ strtoupper($mizoramKind) }} {{ $mizoramOption['code'] }} · {{ $mizoramOption['name'] }}</option>
@endforeach
@endforeach
</select></label><a href="#census-context">District maps & source notes</a><a href="#history">History & results</a>
</nav>
<script src="{{ asset('js/constituency-dashboard-switcher.js') }}" defer></script>
@endif
