@extends('geography-layout')
@section('title', $indiaContext ? 'Explore India' : 'Explore countries and places')
@section('content')
<h1>{{ $indiaContext ? 'Explore India' : 'Explore countries and places' }}</h1><p>Browse imported geography using the country and place types recorded by each source. Administrative and electoral boundaries can overlap.</p>
@php($labels = $indiaContext ? ['pc' => 'Parliamentary constituency (PC)', 'ac' => 'Assembly constituency (AC)', 'district' => 'District', 'state' => 'State', 'ut' => 'Union territory', 'block' => 'Block', 'tehsil' => 'Tehsil', 'village' => 'Village', 'ward' => 'Ward'] : [])
@if($indiaContext)<p class="notice">Dropdowns show available imported records only. State/UT, district, tehsil, block, village and constituency coverage will expand as official mappings are imported. A geographic link does not mean the two areas have identical boundaries.</p>@endif
<form method="get" class="filters card">@if(!$indiaContext)<div><label for="country">Country code</label><select id="country" name="country"><option value="">All recorded countries</option>@foreach($countries as $code)<option value="{{ $code }}" @selected($country === $code)>{{ $code }}</option>@endforeach</select></div>@endif
<div><label for="type">Geographic type</label><select id="type" name="type"><option value="">All types</option>@foreach($types as $value)<option value="{{ $value }}" @selected($type === $value)>{{ $labels[$value] ?? \Illuminate\Support\Str::headline($value) }}</option>@endforeach</select></div>
<div><label for="area">Linked to an area</label><select id="area" name="area"><option value="">All available places</option>@foreach($areas as $item)<option value="{{ $item->id }}" @selected((string) $area === (string) $item->id)>{{ $item->name }} · {{ $labels[$item->type] ?? \Illuminate\Support\Str::headline($item->type) }}</option>@endforeach</select></div>
<button>Browse</button><a href="{{ route($indiaContext ? 'geography.india' : 'geography.index') }}">Clear</a></form>
@if($area)<p>Showing direct source-backed links to the selected area. Related areas are not assumed to be administrative subdivisions.</p>@endif
<p>{{ number_format($places->total()) }} recorded places. Coverage may be incomplete.</p><div class="grid">
@forelse($places as $place)<article class="card"><p class="muted">{{ $place->country_code }} · {{ \Illuminate\Support\Str::headline($place->type) }}</p><h2><a href="{{ route('geography.show', $place->slug) }}">{{ $place->name }}</a></h2></article>@empty<p>No recorded places match these filters.</p>@endforelse
</div>{{ $places->links() }}
@endsection
