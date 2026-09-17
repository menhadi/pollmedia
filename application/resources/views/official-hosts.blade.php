@extends('seo-layout')
@section('content')
<h1>Official source hosts</h1><p>Register verified government or public-institution hosts for any country. Approval permits downloads; each dataset still needs validation and review before publication.</p>
<p class="notice">Existing configured host families: {{ implode(', ', config('imports.official_host_suffixes', [])) }}. New entries below permit only the exact host, not its subdomains. HTTPS and public-network checks remain required.</p>
<form method="post" action="{{ route('official-hosts.store') }}" class="card">@csrf
<div class="field"><label for="host">Exact hostname, without https:// or a path</label><input type="text" id="host" name="host" required maxlength="253"></div>
<div class="field"><label for="country">Two-letter country code</label><input type="text" id="country" name="country_code" required maxlength="2" pattern="[A-Z]{2}"></div>
<div class="field"><label for="publisher">Official publisher</label><input type="text" id="publisher" name="publisher" required maxlength="150"></div>
<div class="field"><label for="verification">How did you verify the official publisher and host?</label><textarea id="verification" name="verification_note" required minlength="20" maxlength="2000"></textarea></div>
<label><input type="checkbox" name="confirmed" value="1" required>I verified that this exact host belongs to the stated official publisher.</label><button>Register official host</button>
</form>
<section class="card"><h2>Registered hosts</h2>@forelse($hosts as $host)<div class="row"><h3>{{ $host->host }}</h3><p>{{ $host->publisher }} · {{ $host->country_code }} · {{ $host->enabled ? 'Enabled' : 'Disabled' }}</p><p>{{ $host->verification_note }}</p>
<form method="post" action="{{ route('official-hosts.toggle', $host->id) }}">@csrf<input type="hidden" name="enabled" value="{{ $host->enabled ? 0 : 1 }}"><button>{{ $host->enabled ? 'Disable downloads' : 'Enable downloads' }}</button></form></div>@empty<p>No additional hosts registered.</p>@endforelse</section>
@endsection
