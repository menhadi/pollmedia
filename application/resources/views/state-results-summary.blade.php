<section id="{{ $kind }}-results" class="panel state-year-results">

@if($election)

<h2 class="visually-hidden">{{ $election['year'] }} {{ $sectionLabel }} results</h2><div class="state-year-metrics compact-facts"><div><span>Available constituencies</span><strong>{{ number_format($election['tables']) }}</strong></div><div><span>Turnout</span><strong>{{ $election['turnout']===null?'—':number_format($election['turnout'],2).'%' }}@if($election['turnout_review_count']??0) †@endif</strong></div><div><span>Votes polled</span><strong>{{ $election['polled']===null?'—':number_format($election['polled']) }}@if($election['turnout_review_count']??0) †@endif</strong></div></div>
<details class="result-coverage"><summary>{{ $election['label'] }} · Coverage &amp; winning margin</summary>
<div><span>Mean winning margin</span><strong>{{ $election['margin']===null?'—':number_format($election['margin']) }}@if($election['margin_review_count']??0) †@endif</strong></div>
<p class="small">{{ $election['label'] }} · turnout covers {{ $election['turnout_count'] }} of {{ $election['tables'] }} constituencies; mean margin covers {{ $election['margin_count'] }}; party vote shares cover {{ $election['party_count'] }}. † marks figures with source notes.</p>
@if(($election['review_count']??0)||($election['party_review_count']??0)||($election['turnout_review_count']??0))<p class="coverage-note">This election includes records with data notes. <a href="{{ '#'.$kind.'-source-notes' }}">Read source notes ↓</a></p>@endif
</details>
@else<p>No {{ $sectionLabel }} results are available for {{ $title }} in this collection yet.</p>@endif
</section>
