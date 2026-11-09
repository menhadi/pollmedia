@php($record = $selectedByElection)
<section class="panel" id="year-detail" aria-labelledby="by-result-title">
    <h2 id="by-result-title">{{ $tr('Election results','चुनाव परिणाम') }}</h2>
    <h3>{{ $record['year'] }} · {{ $tr('By-election','उपचुनाव') }}</h3>
    @if(!empty($record['source_date']))<p>{{ $record['source_date'] }}</p>@endif
    @if(!empty($record['reason']))<p>{{ $record['reason'] }}</p>@endif
    @if(!empty($record['candidates']))
    <div class="table-scroll atlas-table-scroll" role="region" aria-labelledby="by-result-title" tabindex="0"><table data-sortable>
        <thead><tr><th scope="col">{{ $tr('Candidate','उम्मीदवार') }}</th><th scope="col">{{ $tr('Party','दल') }}</th><th scope="col">{{ $tr('Recorded votes','दर्ज मत') }}</th></tr></thead>
        <tbody>@foreach($record['candidates'] as $candidate)
            <tr><td><span translate="no">{{ $candidate['name'] ?? '—' }}</span> @if(($candidate['role']??null)==='reported_winner')<small>{{ $tr('Reported winner','दर्ज विजेता') }}</small>@endif</td>
                <td translate="no">{{ ($candidate['party'] ?? '') !== '' ? $candidate['party'] : '—' }}</td><td translate="no">{{ is_numeric($candidate['votes']??null)?number_format($candidate['votes']):'—' }}</td></tr>
        @endforeach</tbody>
    </table></div>
    @else<p>{{ $tr('Candidate rows are unavailable for this by-election.','इस उपचुनाव के उम्मीदवारों के आँकड़े उपलब्ध नहीं हैं।') }}</p>@endif
    <details class="seat-by-notes"><summary>{{ $tr('Report and source notes','रिपोर्ट और स्रोत नोट') }}</summary>
        <p>{{ $record['period'] ?? '' }} · <a href="{{ $record['source_url'] }}">{{ $tr('Official source','आधिकारिक स्रोत') }}</a> ·
        <a href="{{ route('elections.by-election-results',['year'=>$record['year'],'record'=>$record['id'],'format'=>'report','lang'=>$hi?'hi':null]) }}">{{ $tr('Printable result','प्रिंट योग्य परिणाम') }}</a></p>
        @include('by-election-source-review')
        @if(!empty($record['notes']))<ul>@foreach($record['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>@endif
    </details>
</section>
@if($byFigures['groups']->isNotEmpty())
<section class="panel" aria-labelledby="by-party-title">
<h2 id="by-party-title">{{ $tr('Party vote shares','दलगत मत प्रतिशत') }}</h2>
<p class="small">{{ $tr('Share of votes in listed rows','सूचीबद्ध मतों में हिस्सा') }}</p>
<div class="table-scroll"><table class="atlas-party-shares" data-sortable><thead><tr><th>{{ $tr('Party','दल') }}</th><th>{{ $tr('Votes','मत') }}</th><th>{{ $tr('Share (%)','प्रतिशत (%)') }}</th></tr></thead><tbody>
@foreach($byFigures['groups'] as $group=>$votes)<tr><td translate="no">{{ $group }}</td><td>{{ number_format($votes) }}</td><td>{{ number_format(100*$votes/$byFigures['total'],2) }}%</td></tr>@endforeach
</tbody></table></div></section>
@endif
