@php
    $hi = app()->getLocale() === 'hi';
    $tr = fn ($en, $hn) => $hi ? $hn : $en;
    $editions = collect($history);
@endphp
<section class="panel state-margin-history" aria-labelledby="margin-history-title">
    <h3 id="margin-history-title">{{ $tr('Winning margins by election year', 'चुनाव वर्ष के अनुसार जीत का अंतर') }}</h3>
    <p>{{ $tr('Margins in votes. Select a report edition to see its constituencies and winning candidates.', 'अंतर मतों में है। निर्वाचन क्षेत्र और विजयी उम्मीदवार देखने के लिए रिपोर्ट संस्करण चुनें।') }}</p>
    <div class="table-scroll" role="region" aria-labelledby="margin-history-title" tabindex="0">
    <table data-total-rows="{{ $editions->count() }}"><thead><tr>
        @foreach([['Election edition','चुनाव संस्करण'], ['Seats with margins','अंतर वाले क्षेत्र'], ['Highest','अधिकतम'], ['Lowest','न्यूनतम'], ['Median','मध्यिका'], ['Mean','औसत']] as [$en, $hn])
            <th scope="col">{{ $tr($en, $hn) }}</th>
        @endforeach
    </tr></thead><tbody>
        @foreach(\App\Services\StateTablePreview::initial($editions) as $entry)
            @php($summary = \App\Services\StateWinningMargins::summarize(collect($entry['constituency_results'] ?? [])))
            <tr>
                <th scope="row"><a href="{{ route('states.show', ['state'=>$state, 'election'=>$kind, $kind.'_edition'=>$entry['id'], 'lang'=>$hi?'hi':null]) }}#margin-summary-title">{{ $entry['label'] }}</a></th>
                <td>{{ number_format($summary['count']) }} / {{ number_format($entry['tables']) }}</td>
                @foreach(['highest', 'lowest', 'median', 'mean'] as $key)
                    <td>{{ $summary[$key] === null ? '—' : number_format($summary[$key]) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody></table>
    </div>
</section>
