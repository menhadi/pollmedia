<section class="panel approved-office-directory" id="people">
<h2>{{ \App\Services\PublicLanguage::text('Representatives & authorities') }}</h2>
@forelse($directoryPeople as $person)
<article class="approved-office-card">
<h3>{{ $person->display_name }}</h3><p>{{ $person->title }} · {{ $person->area }}</p>
<p><small>Verified {{ substr($person->verified_at,0,10) }}</small></p>
<a href="{{ $person->url }}" target="_blank" rel="noopener noreferrer">Official directory ↗</a>
@foreach($person->profiles as $profile)<a href="{{ $profile->url }}" target="_blank" rel="noopener noreferrer">{{ $profile->kind }} ↗</a>@endforeach
</article>
@empty
<p>No verified directory assignment is available for this constituency.</p>
@endforelse
<details><summary>Verification details</summary><p>Assignments retain their recorded verification dates. Election results appear separately.</p></details>
</section>
