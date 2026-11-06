@if($data['census_village_rows']>0)
<section class="panel"><h2>Newly available Census village records</h2><p>{{ number_format($data['census_village_rows']) }} village records are published for this Census district. Their demographic profiles update independently of the LGD and map crosswalk.</p><div class="source-grid">
@foreach(array_slice($data['census_villages'],0,12) as $village)
<article><h3><a href="https://pollmedia.org/india/census/places/{{ $village['id'] }}">{{ $village['name'] }} →</a></h3><p>{{ $village['population']===null?'Population unavailable':number_format($village['population']).' people' }} · Census {{ $data['year'] }}</p></article>
@endforeach
</div><a href="https://pollmedia.org/india/census/places/{{ $data['totals']['Total']['id'] }}">Explore every published place in this district →</a></section>
@endif
