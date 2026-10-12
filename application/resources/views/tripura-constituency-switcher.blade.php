@if($state==='Tripura' && !$exactSeatOnly)
@php($tripuraSwitchFixture=json_decode(file_get_contents(database_path('fixtures/tripura-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR))
<link rel="stylesheet" href="{{ asset('css/constituency-dashboard-switcher.css') }}">
<nav class="constituency-dashboard-switcher" aria-label="Tripura district and constituency dashboards">
<a href="{{ route('district-dashboard-directory') }}">Change district</a>
<label>Change constituency<select data-constituency-switcher><option value="" selected>{{ strtoupper($kind) }} · {{ $name }}</option>
@foreach(['pcs'=>'pc','district_rows'=>'ac','separate_electoral_constituencies'=>'ac'] as $tripuraGroup=>$tripuraKind)
@foreach($tripuraSwitchFixture[$tripuraGroup]??[] as $tripuraOption)
<option value="{{ route('constituency.overview',['kind'=>$tripuraKind,'state'=>'Tripura','name'=>trim(preg_replace('/\s*\((?:BL|SC|ST)\)\s*$/i','',$tripuraOption['name']))],false) }}">{{ strtoupper($tripuraKind) }} {{ $tripuraOption['code'] }} · {{ $tripuraOption['name'] }}</option>
@endforeach
@endforeach
</select></label><a href="#census-context">District maps & source notes</a><a href="#history">History & results</a>
</nav>
<script src="{{ asset('js/constituency-dashboard-switcher.js') }}" defer></script>
@endif
