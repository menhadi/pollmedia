@php
    $hi = app()->getLocale() === 'hi';
    $tr = fn ($en, $hn) => $hi ? $hn : $en;
    $summary = \App\Services\StateWinningMargins::summarize($seatRows);
@endphp
<section class="panel state-margin-summary" aria-labelledby="margin-summary-title">
    <h2 id="margin-summary-title">{{ $tr('Winning margins', 'जीत का अंतर') }}</h2>
    <p>{{ $title }} · {{ $sectionLabel }} · {{ $election['label'] }}<br>
        {{ $hi ? 'उपलब्ध '.number_format($election['tables']).' निर्वाचन क्षेत्रों में से '.number_format($summary['count']).' में जीत का अंतर दर्ज है।' : 'Margins available for '.number_format($summary['count']).' of '.number_format($election['tables']).' constituencies in this edition.' }}
    </p>
    @if($summary['count'])
        <div class="table-scroll" role="region" aria-labelledby="margin-summary-title" tabindex="0">
        <table>
            <thead><tr>
                <th scope="col">{{ $tr('Measure', 'माप') }}</th><th scope="col">{{ $tr('Margin (votes)', 'अंतर (मत)') }}</th>
                <th scope="col">{{ $tr('Constituency', 'निर्वाचन क्षेत्र') }}</th><th scope="col">{{ $tr('Winning candidate', 'विजयी उम्मीदवार') }}</th><th scope="col">{{ $tr('Party', 'दल') }}</th>
            </tr></thead>
            <tbody>
            @foreach(['largest_wins' => $tr('Largest win', 'सबसे बड़ी जीत'), 'closest_wins' => $tr('Closest win', 'सबसे करीबी जीत')] as $key => $label)
                @foreach($summary[$key] as $seat)
                    <tr><th scope="row">{{ $label }}</th><td>{{ number_format($seat['result']['margin']) }}</td>
                        <td><a href="{{ \App\Services\ConstituencyUrls::url(['kind'=>$kind, 'state'=>$title, 'name'=>$seat['name'], 'edition'=>$edition, 'code'=>$seat['code']]) }}">{{ $seat['name'] }}</a>{{ ($seat['has_warning'] ?? false) ? ' †' : '' }}</td>
                        <td>{{ $seat['result']['winner'] ?? '—' }}</td><td>{{ $seat['result']['party'] ?? '—' }}</td>
                    </tr>
                @endforeach
            @endforeach
            @foreach(['median' => $tr('Median', 'मध्यिका'), 'mean' => $tr('Mean', 'औसत')] as $key => $label)
                <tr><th scope="row">{{ $label }}</th><td>{{ number_format($summary[$key]) }}</td><td colspan="3">{{ $tr('Across included seats', 'शामिल निर्वाचन क्षेत्र') }}</td></tr>
            @endforeach
            <tr><th scope="row">{{ $tr('Mode', 'बहुलक') }}</th><td>{{ $summary['modes'] ? implode(', ', array_map('number_format', $summary['modes'])) : '—' }}</td>
                <td colspan="3">{{ $summary['modes'] ? $tr('Most frequent margin', 'सबसे अधिक बार दर्ज अंतर') : $tr('No repeated margin', 'कोई अंतर दोहराया नहीं गया') }}</td></tr>
            </tbody>
        </table>
        </div>
    @endif
</section>
