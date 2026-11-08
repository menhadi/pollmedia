<aside class="state-atlas-map" aria-label="{{ $title }} constituency map">
@if(app(\App\Services\ElectoralMapCatalogue::class)->stateFromQuery($title))
@if($election)
@include('election-map',['stateMapFilters'=>true,'mapKind'=>$kind,'mapStateName'=>$title,'mapEdition'=>$edition,'mapYear'=>$election['year']])
@else
@include('election-map',['stateMapFilters'=>true,'mapKind'=>$kind,'mapStateName'=>$title,'mapMode'=>'focus','mapLabels'=>true])
@endif
@else
<p>Constituency boundaries are unavailable for {{ $title }}.</p>
@endif
</aside>
