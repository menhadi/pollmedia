@if($state === 'Punjab' && !$exactSeatOnly)
@php
$fixture = json_decode(file_get_contents(database_path('fixtures/punjab-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
$normalize = fn(string $value): string => preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/\s*\((?:SC|ST)\)\s*$/i', '', $value)));
$seats = collect($kind === 'pc' ? $fixture['pcs'] : $fixture['district_rows'])->filter(fn(array $seat): bool => $normalize($seat['name']) === $normalize($name));
$seat = $seats->count() === 1 ? $seats->first() : null;
$segments = $seat ? ($kind === 'pc' ? collect($fixture['district_rows'])->whereIn('code', $seat['ac_codes']) : collect([$seat])) : collect();
$districts = $segments->pluck('district')->unique()->values();
@endphp
@if($seat)
<section id="census-context" class="panel"><h2>District dashboards & electoral scope</h2>
<p class="notice">The ECI 2008 order uses district references as of 2004-02-15. Historical district links below are context, not a current boundary overlay. District Census populations are not AC or PC populations. Later splits and map-to-Census crosswalks remain unverified.</p>
<nav aria-label="Historical district dashboards">
@foreach($districts as $district)
<a href="{{ route('district-dashboard', ['state' => '03', 'district' => $fixture['district_codes'][$district]]) }}">{{ $district }} · historical district reference →</a><br>
@endforeach
</nav>
@foreach($fixture['supplemental_districts'] as $district => $source)
@if(count(array_intersect($segments->pluck('code')->all(), $source['ac_codes'])))
<p><a href="{{ route('district-dashboard', ['state' => '03', 'district' => $fixture['district_codes'][$district]]) }}">{{ $district }} district dashboard →</a> · <a href="{{ $source['url'] }}">Separate official district association</a> (retrieved {{ $source['retrieved_at'] }}).</p>
@endif
@endforeach
@if($kind === 'pc')<p>Complete Assembly membership: {{ $segments->map(fn(array $row): string => $row['name'].' (AC '.$row['code'].')')->implode(', ') }}.</p>@endif
<p><a href="{{ $fixture['pc_url'] }}">ECI delimitation order · {{ $fixture['pc_source_date'] }}</a></p>
</section>
@endif
@endif
