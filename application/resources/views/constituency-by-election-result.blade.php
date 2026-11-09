@php($record = $selectedByElection)
<section class="panel seat-by-result" id="by-election-result" aria-labelledby="by-result-title" tabindex="-1">
    <div class="seat-by-heading"><span class="seat-by-badge">{{ $tr('By-election','उपचुनाव') }}</span>
        <h2 id="by-result-title">{{ $record['year'] }} · <span translate="no">{{ $record['constituency'] }}</span></h2>
    </div>
    @if(!empty($record['source_date']))<p>{{ $record['source_date'] }}</p>@endif
    @if(!empty($record['reason']))<p>{{ $record['reason'] }}</p>@endif
    @if(!empty($record['candidates']))
    <div class="atlas-table-scroll" role="region" aria-labelledby="by-result-title" tabindex="0"><table>
        <thead><tr><th scope="col">{{ $tr('Candidate','उम्मीदवार') }}</th><th scope="col">{{ $tr('Party','दल') }}</th><th scope="col">{{ $tr('Recorded votes','दर्ज मत') }}</th></tr></thead>
        <tbody>@foreach($record['candidates'] as $candidate)
            <tr><th scope="row"><span translate="no">{{ $candidate['name'] ?? '—' }}</span> @if(($candidate['role']??null)==='reported_winner')<small>{{ $tr('Reported winner','दर्ज विजेता') }}</small>@endif</th>
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
