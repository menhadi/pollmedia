@if($state==='Uttar Pradesh' && !$exactSeatOnly)
@php
$switchFixture=json_decode(file_get_contents(database_path('fixtures/up-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$switchNormalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$switchSeats=collect($kind==='pc'?$switchFixture['pcs']:$switchFixture['district_rows'])->filter(fn(array $seat):bool=>$switchNormalize($seat['name'])===$switchNormalize($name));
$switchSeat=$switchSeats->count()===1?$switchSeats->first():null;
$switchSegments=$switchSeat?($kind==='pc'?collect($switchFixture['district_rows'])->whereIn('code',$switchSeat['ac_codes']):collect([$switchSeat])):collect();
$switchSeen=[];
$switchDistricts=$switchSegments->pluck('district')->unique()->filter(fn(string $district):bool=>in_array($district,['Agra','Rampur','Pilibhit','Aligarh','Prayagraj','Ambedkar Nagar','Auraiya','Azamgarh','Baghpat','Bahraich','Ballia','Balrampur','Banda','Barabanki','Bareilly','Basti','Bijnor','Badaun','Bulandshahar','Chandauli','Chitrakoot','Deoria','Etah'],true));
@endphp
@if($switchDistricts->isNotEmpty())
<link rel="stylesheet" href="{{ asset('css/constituency-dashboard-switcher.css') }}">
<nav class="constituency-dashboard-switcher" aria-label="District and constituency dashboards"><a href="{{ route('district-dashboard-directory') }}">Change district →</a><label>Change constituency<select data-constituency-switcher><option value="" selected>{{ strtoupper($kind) }} · {{ $name }}</option>
@foreach($switchDistricts as $switchDistrict)
@php($switchLinks=app(\App\Services\DistrictConstituencyLinks::class)->forDistrict($switchDistrict))
@foreach(['parliamentary'=>'PC','assembly'=>'AC'] as $switchKey=>$switchLabel)
<optgroup label="{{ $switchDistrict }} · {{ $switchLabel }}">
@foreach($switchLinks[$switchKey] as $switchOption)
@php($switchIdentity=$switchKey.':'.$switchOption['code'])
@continue(isset($switchSeen[$switchIdentity]))
@php($switchSeen[$switchIdentity]=true)
<option value="{{ route('constituency.overview',['kind'=>$switchKey==='assembly'?'ac':'pc','state'=>$state,'name'=>trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$switchOption['name']))],false) }}">{{ $switchLabel }} {{ $switchOption['code'] }} · {{ $switchOption['name'] }}</option>
@endforeach
</optgroup>
@endforeach
@endforeach
</select></label><a href="#census-context">Census context</a><a href="#history">Historical graphs & results</a></nav>
<script src="{{ asset('js/constituency-dashboard-switcher.js') }}" defer></script>
@endif
@endif
