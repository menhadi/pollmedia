@extends('geography-layout')
@section('title', 'Citizen issues')
@section('content')
<h1>Citizen issues</h1>
<p>Report a local service problem and follow reviewed reports. These are citizen accounts, not official statistics or verified findings.</p>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="notice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form class="card filters" method="get">
<div><label for="country">Country</label><select id="country" name="country">@foreach($countries as $option)<option @selected($country === $option)>{{ $option }}</option>@endforeach</select></div>
<div><label for="type">Area type</label><select id="type" name="type">@foreach($types as $option)<option value="{{ $option }}" @selected($type === $option)>{{ \Illuminate\Support\Str::headline($option) }}</option>@endforeach</select></div>
<button>Load areas</button>
</form>
<form class="card filters" method="get"><input type="hidden" name="country" value="{{ $country }}"><input type="hidden" name="type" value="{{ $type }}">
<div><label for="place">Area</label><select id="place" name="place"><option value="">All loaded areas</option>@foreach($places as $area)<option value="{{ $area->id }}" @selected((string)$place === (string)$area->id)>{{ $area->name }} · {{ $area->slug }}</option>@endforeach</select></div><button>Filter reports</button></form>
<section class="card"><h2>Published reports</h2>@forelse($issues as $issue)<div class="row"><a href="{{ route('issues.show', $issue->id) }}">{{ $issue->title }}</a><p>{{ \Illuminate\Support\Str::headline($issue->category) }} · {{ \Illuminate\Support\Str::headline($issue->status) }}</p></div>@empty<p>No published reports match these areas.</p>@endforelse{{ $issues->links() }}</section>
<section class="card"><h2>Report a problem</h2><p>Choose a country and area type above to load locations. Submit only information you agree can be published. Do not include phone numbers, addresses of individuals, identity documents or other private details. Reports are reviewed before publication. This form does not contact emergency services or authorities.</p>
<form method="post" action="{{ route('issues.store') }}">@csrf
<p><label for="place_id">Affected area</label><select name="place_id" id="place_id" required><option value="">Choose an area</option>@foreach($places as $area)<option value="{{ $area->id }}" @selected((string)old('place_id', $place) === (string)$area->id)>{{ $area->name }} · {{ $area->slug }}</option>@endforeach</select></p>
<p><label for="category">Category</label><select id="category" name="category" required>@foreach($categories as $category)<option value="{{ $category }}" @selected(old('category') === $category)>{{ ucfirst($category) }}</option>@endforeach</select></p>
<p><label for="title">Short title</label><input id="title" name="title" value="{{ old('title') }}" minlength="8" maxlength="160" required></p>
<p><label for="description">What happened and where?</label><textarea id="description" name="description" rows="6" minlength="30" maxlength="5000" required>{{ old('description') }}</textarea></p>
<p><label for="evidence_url">Public evidence link (optional, HTTPS)</label><input id="evidence_url" type="url" name="evidence_url" value="{{ old('evidence_url') }}" maxlength="2048"></p>
<p><label><input type="checkbox" name="consent" value="1" required @checked(old('consent'))> I agree that this report and its evidence link may be published after review.</label></p><button @disabled($places->isEmpty())>Submit for review</button></form></section>
@endsection
