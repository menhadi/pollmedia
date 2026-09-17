@extends('seo-layout')
@section('content')
<h1>Countries and report areas</h1><p>Configure country-wide coverage or a jurisdiction defined by accepted source identifiers. Names and geographic types come from your datasets; no particular administrative or electoral structure is required.</p>
<section class="card"><h2>Add a report area</h2><form method="post" action="{{ route('report-scopes.store') }}">@csrf
<div class="field"><label for="key">URL key</label><input id="key" name="key" type="text" required maxlength="100" placeholder="country-or-jurisdiction"></div>
<div class="field"><label for="label">Area name</label><input id="label" name="label" type="text" required maxlength="150"></div>
<div class="field"><label for="country">Country code from imported geography</label><select id="country" name="country_code" required>@foreach($countries as $country)<option>{{ $country }}</option>@endforeach</select></div>
<div class="field"><label for="timezone">Report time zone</label><select id="timezone" name="timezone">@foreach(timezone_identifiers_list() as $zone)<option value="{{ $zone }}" @selected($zone === 'UTC')>{{ $zone }}</option>@endforeach</select></div>
<div class="field"><label for="selection">Geographic scope</label><select id="selection" name="selection"><option value="country">All recorded places in this country</option><option value="identifiers">Places covered by selected source identifiers</option></select></div>
<fieldset><legend>Identifiers for jurisdiction reports</legend>@forelse($namespaces as $namespace)<label><input type="checkbox" name="namespaces[]" value="{{ $namespace->namespace }}">{{ $namespace->country_code }} · {{ $namespace->namespace }}</label>@empty<p>No accepted identifiers are available yet.</p>@endforelse</fieldset>
<p class="muted">Choose identifiers that describe the intended jurisdiction. An identifier group is not evidence of unchanged historical boundaries.</p>
<label><input type="checkbox" name="automatic" value="1">Generate quarter-end and year-end drafts at 23:50 in this area's time zone</label><button>Create report area</button>
</form></section>
<section class="card"><h2>Configured areas</h2>@foreach($scopes as $scope)<div class="row"><h3>{{ $scope->label }} ({{ $scope->country_code }})</h3><p>{{ $scope->timezone }} · {{ $scope->automatic ? 'Automatic drafts enabled' : 'Manual drafts only' }}</p>
<a href="{{ $scope->adapter === 'coverage' ? route('reports.coverage', $scope->key) : route('reports.pilibhit') }}">Open report</a>
<form method="post" action="{{ route('report-scopes.schedule', $scope->key) }}">@csrf<input type="hidden" name="automatic" value="{{ $scope->automatic ? 0 : 1 }}"><button>{{ $scope->automatic ? 'Pause automatic drafts' : 'Enable automatic drafts' }}</button></form></div>@endforeach</section>
@endsection
