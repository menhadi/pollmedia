@if($foldStateMethodology??false)
<details class="report-notes state-methodology" id="state-methodology"><summary>{{ app()->getLocale()==='hi'?'कार्यप्रणाली और अस्वीकरण':'Methodology & disclaimer' }}</summary>
@else
<section class="report-notes"><h2>Methodology & disclaimer</h2>
@endif
<p>Available records are not a claim of complete historical coverage. Election winners may differ from current representatives. Historical names and boundaries belong to their reference years; a shared name does not establish unchanged boundaries.</p>
@if($exactSeatOnly??false)<p>Several seats share this name. This report covers only the selected seat and edition.</p>@endif
<ul><li>The first chart follows the same three party labels, selected by combined recorded votes across available years, counting each year once. Their shares need not total 100%.</li><li>The second chart ranks the top two parties separately in each year. Lines represent rank; the party names can change. Equal votes use alphabetical party order and do not establish a winner.</li><li>Party shares use all recorded candidate votes plus NOTA as the denominator. Others includes remaining parties, independents and NOTA. Party labels remain as reported; alliances and renamed parties are not merged.</li><li>Turnout and party shares are percentages. Margin, registered electors and votes polled are absolute counts.</li><li>Missing or conflicting figures leave gaps and are not zero. Conflicting editions in one year are omitted from the affected chart value; original report references are retained.</li><li>† marks a figure with a source note. Read the source notes and original reports before sharing or relying on the figures.</li><li>Each point represents an election year. Curves connect the recorded points; they do not provide values for intervening years.</li></ul>
@if($isStateHistory??false)<p>State coverage varies by year. Turnout combines votes and electors from the same constituencies with available figures. Winning margin is the average gap between winner and runner-up across available single-seat results. Coverage counts and original reports identify the records included.</p>@endif
@if($foldStateMethodology??false)
<section id="{{ $kind }}-source-notes">
@php($warningGroups=collect($election['constituency_results']??[])->filter(fn($seat)=>!empty($seat['has_warning']) && filled($seat['note']??null))->groupBy('note'))
@if($warningGroups->isNotEmpty())
<h3>{{ app()->getLocale()==='hi'?'निर्वाचन क्षेत्र के स्रोत संबंधी नोट':'Constituency source notes' }} · {{ $election['year'] }}</h3>
@foreach($warningGroups as $note=>$seats)
<p><strong>† {{ $seats->pluck('name')->implode(', ') }}</strong><br>{{ $note }}</p>
@endforeach
@endif
</section>
@if(!empty($history))
@include('state-edition-coverage')
@endif
</details>
@else
</section>
@endif