@if($state==='Arunachal Pradesh' && !$exactSeatOnly)
@php($arunachalSwitchFixture=json_decode(file_get_contents(database_path('fixtures/arunachal-pradesh-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR))
<link rel="stylesheet" href="{{ asset('css/constituency-dashboard-switcher.css') }}">
<nav class="constituency-dashboard-switcher" aria-label="Arunachal Pradesh district and constituency dashboards">
<a href="{{ route('district-dashboard-directory') }}">Change district</a>
<label>Change constituency<select data-constituency-switcher><option value="" selected>{{ strtoupper($kind) }} · {{ $name }}</option>
@foreach(['pcs'=>'pc','district_rows'=>'ac','separate_electoral_constituencies'=>'ac'] as $arunachalGroup=>$arunachalKind)
@foreach($arunachalSwitchFixture[$arunachalGroup]??[] as $arunachalOption)
<option value="{{ route('constituency.overview',['kind'=>$arunachalKind,'state'=>'Arunachal Pradesh','name'=>trim(preg_replace('/\s*\((?:BL|SC|ST)\)\s*$/i','',$arunachalOption['name']))],false) }}">{{ strtoupper($arunachalKind) }} {{ $arunachalOption['code'] }} · {{ $arunachalOption['name'] }}</option>
@endforeach
@endforeach
</select></label><a href="#census-context">District maps & source notes</a><a href="#history">History & results</a>
</nav>
<script src="{{ asset('js/constituency-dashboard-switcher.js') }}" defer></script>
@endif
