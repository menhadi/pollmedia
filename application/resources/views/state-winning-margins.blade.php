@php
    $hi = app()->getLocale() === 'hi';
    $tr = fn ($en, $hn) => $hi ? $hn : $en;
    $contests = $seatRows->filter(function ($seat) {
        $result = $seat['result'] ?? [];
        return empty($result['uncontested']) && is_numeric($result['margin'] ?? null) && $result['margin'] >= 0;
    });
    $margins = $contests->pluck('result.margin');
    $formatMargin = fn ($votes) => number_format($votes, round($votes, 1) == round($votes) ? 0 : 1);
@endphp
<section class="panel state-margin-summary" aria-labelledby="margin-summary-title">
    <h2 id="margin-summary-title">{{ $tr('Winning margins', 'जीत का अंतर') }}</h2>
    <p>{{ $title }} · {{ $sectionLabel }} · {{ $election['label'] }}<br>
        {{ $hi ? 'उपलब्ध '.number_format($election['tables']).' निर्वाचन क्षेत्रों में से '.number_format($contests->count()).' में जीत का अंतर दर्ज है।' : 'Margins available for '.number_format($contests->count()).' of '.number_format($election['tables']).' constituencies in this edition.' }}
    </p>
    @if($contests->isNotEmpty())
        <table>
            <thead><tr><th scope="col">{{ $tr('Measure', 'माप') }}</th><th scope="col">{{ $tr('Margin (votes)', 'अंतर (मत)') }}</th><th scope="col">{{ $tr('Constituency', 'निर्वाचन क्षेत्र') }}</th></tr></thead>
            <tbody>
            @foreach([[$tr('Largest win', 'सबसे बड़ी जीत'), $margins->max()], [$tr('Closest win', 'सबसे करीबी जीत'), $margins->min()]] as [$label, $votes])
                <tr><th scope="row">{{ $label }}</th><td>{{ number_format($votes) }}</td><td>
                    @foreach($contests->where('result.margin', $votes) as $seat)
                        <a href="{{ \App\Services\ConstituencyUrls::url(['kind'=>$kind, 'state'=>$title, 'name'=>$seat['name'], 'edition'=>$edition, 'code'=>$seat['code']]) }}">{{ $seat['name'] }}</a>{{ ($seat['has_warning'] ?? false) ? ' †' : '' }}{{ $loop->last ? '' : ', ' }}
                    @endforeach
                </td></tr>
            @endforeach
            @foreach([[$tr('Median', 'मध्यिका'), $margins->median()], [$tr('Mean', 'औसत'), $margins->avg()]] as [$label, $votes])
                <tr><th scope="row">{{ $label }}</th><td>{{ $formatMargin($votes) }}</td><td>{{ $tr('Across included seats', 'शामिल निर्वाचन क्षेत्र') }}</td></tr>
            @endforeach
            </tbody>
        </table>
        @if($contests->contains('has_warning', true))
            <p><a href="#{{ $kind }}-source-notes">† {{ $tr('Source notes for this edition', 'इस संस्करण के स्रोत नोट') }}</a></p>
        @endif
    @endif
</section>
