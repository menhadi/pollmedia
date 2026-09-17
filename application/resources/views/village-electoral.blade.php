<section class="card" id="electoral-connections"><div class="kicker">LGD electoral mapping · {{ $electoral['checked_on'] }}</div><h2>Assembly & parliamentary constituency</h2>
@forelse($electoralMatches as $match)
<p>LGD village {{ $match['lgd_code'] }} · Official spreadsheet row {{ $match['source_row'] }}</p>
<div class="grid">@foreach(['ac'=>'Assembly constituency', 'pc'=>'Parliamentary constituency'] as $kind=>$label)
<article><h3>{{ ucfirst($match[$kind]) }} {{ strtoupper($kind) }}</h3><p>{{ $label }}</p><div class="links"><a href="{{ route('places.show',['type'=>$kind,'slug'=>$match[$kind]]) }}">Constituency & election results →</a><a href="{{ route('places.show',['type'=>$kind,'slug'=>$match[$kind]]) }}#people">{{ $kind==='ac' ? 'MLA' : 'MP' }} & dated public profiles →</a><a href="{{ route('villages.index',['year'=>$year,$kind=>$match[$kind]]) }}">Browse linked Census villages →</a></div></article>
@endforeach</div>
@empty
<p>No village-code match was found in the imported LGD electoral report. No AC or PC assignment is inferred from the village name or gram panchayat.</p>
@endforelse
<p class="small">These are directory relationships checked {{ $electoral['checked_on'] }}, separate from Census {{ $year }} geography. Constituency results describe the whole constituency, not this village. Polling-part assignments and village-level voting results have not been verified.</p>
<a href="{{ $electoral['url'] }}" target="_blank" rel="noreferrer">Official LGD PC/AC mapping report ↗</a>
</section>
