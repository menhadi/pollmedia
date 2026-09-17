@extends('geography-layout')
@section('title', 'Explore countries and places')
@section('content')
<h1>Explore countries and places</h1><p>Browse imported geography using the country and place types recorded by each source. Administrative and electoral boundaries can overlap.</p>
<form method="get" class="filters card"><div><label for="country">Country code</label><select id="country" name="country"><option value="">All recorded countries</option>@foreach($countries as $code)<option value="{{ $code }}" @selected($country === $code)>{{ $code }}</option>@endforeach</select></div>
<div><label for="type">Geographic type</label><select id="type" name="type"><option value="">All types</option>@foreach($types as $value)<option value="{{ $value }}" @selected($type === $value)>{{ \Illuminate\Support\Str::headline($value) }}</option>@endforeach</select></div><button>Browse</button><a href="{{ route('geography.index') }}">Clear</a></form>
<p>{{ number_format($places->total()) }} recorded places. Coverage may be incomplete.</p><div class="grid">
@forelse($places as $place)<article class="card"><p class="muted">{{ $place->country_code }} · {{ \Illuminate\Support\Str::headline($place->type) }}</p><h2><a href="{{ route('geography.show', $place->slug) }}">{{ $place->name }}</a></h2></article>@empty<p>No recorded places match these filters.</p>@endforelse
</div>{{ $places->links() }}
@endsection
