<aside class="dashboard-sidebar"><details open><summary>Related links</summary><h2 class="related-heading">Explore Pollmedia</h2><p class="small">Find election records, places and their official sources.</p><nav aria-label="Related records">
@if(isset($relations, $type) && in_array($type,['pc','ac']))
@php($electoralLinks=$relations->filter(fn($related)=>$related->relationship_type==='assembly_segment_of' && $related->type===($type==='pc'?'ac':'pc')))
<p class="sidebar-label">{{ $type==='pc'?'Assembly seats in this PC':'Parent Parliament seat' }}</p>
@forelse($electoralLinks as $linked)<a href="{{ $linked->url }}">{{ $linked->name }} · {{ strtoupper($linked->type) }} →</a>@empty<p class="small">This electoral link is awaiting verification.</p>@endforelse
<p class="small">Links follow the official relationship sources shown on this page.</p>
<p class="sidebar-label">Explore election results</p>
@endif
<a href="{{ route('elections.constituencies') }}">Find a constituency →</a>
<a href="{{ route('elections.history') }}">Lok Sabha results →</a>
<a href="{{ route('elections.assembly') }}">State Assembly results →</a>
<a href="{{ route('elections.by-election-results') }}">By-election results →</a>
<a href="{{ route('elections.polling-stations') }}">Polling-station tables →</a>
<a href="{{ route('geography.index') }}">Explore places →</a>
<a href="{{ route('census-catalogue.index') }}">Census tables →</a>
<a href="{{ route('sources.index') }}">Official sources →</a>
</nav></details></aside>
