@php
    $hi = app()->getLocale() === 'hi' || request('lang') === 'hi';
    $t = fn ($en, $hindi) => $hi ? $hindi : $en;
    $percent = fn ($number, $digits = 1) => $number === null ? '—' : $display($number, $digits).'%';
    $period = $t('Census', 'जनगणना').' '.$data['year'];
    $rows = [
        [$t('Population','जनसंख्या'), $display($total['population'])],
        [$t('Households','परिवार'), $display($total['households'])],
        [$t('Literacy age 7+','साक्षरता (आयु 7+)'), $percent($total['literacy'],2)],
        [$t('Female per 1,000 male','प्रति 1,000 पुरुषों पर महिलाएँ'), $display($total['female'] !== null && $total['male'] > 0 ? 1000*$total['female']/$total['male'] : null)],
        [$t('Rural population share','ग्रामीण जनसंख्या का हिस्सा'), $percent($share($data['totals']['Rural']['population'] ?? null, $total['population']))],
        [$t('Male literacy age 7+','पुरुष साक्षरता (आयु 7+)'), $percent($total['male_literacy'])],
        [$t('Female literacy age 7+','महिला साक्षरता (आयु 7+)'), $percent($total['female_literacy'])],
        [$t('Population aged 0–6','आयु 0–6 की जनसंख्या'), $display($total['children'])],
        [$t('Population aged 0–6 (%)','आयु 0–6 की जनसंख्या (%)'), $percent($share($total['children'],$total['population']))],
        [$t('Worker participation','कार्य सहभागिता'), $percent($share($total['workers'],$total['population']))],
    ];
    $water = null;
    if (($data['census_district_code'] ?? '') === '151' && (request()->attributes->get('district_identity')['state'] ?? request()->route('state')) === '09') {
        try {
            $water = app(\App\Services\JjmWaterSnapshot::class)->pilibhit();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 503) { throw $error; }
            $water = null;
        }
    }
@endphp
<section id="overview" class="panel pm-overview-table"><h2>{{ $t('District overview','ज़िले का सारांश') }}</h2>
<div class="table-wrap"><table class="compact-summary-table">
<thead><tr><th scope="col">{{ $t('Measure','माप') }}</th><th scope="col">{{ $t('Value','मान') }}</th><th scope="col">{{ $t('Reference','संदर्भ') }}</th></tr></thead>
<tbody>
@foreach($rows as [$measure,$value])
<tr><th scope="row">{{ $measure }}</th><td>{{ $value }}</td><td>{{ $period }}</td></tr>
@endforeach
@if($water)
@foreach([
    $t('JJM village records','JJM ग्राम रिकॉर्ड')=>number_format($water['summary']['villages']),
    $t('Households in the JJM report','JJM रिपोर्ट में परिवार')=>number_format($water['summary']['households']),
    $t('Reported household tap connections','रिपोर्ट किए गए घरेलू नल कनेक्शन')=>number_format($water['summary']['tap_connections']),
    $t('Reported tap connection coverage','रिपोर्ट किया गया नल कनेक्शन कवरेज')=>$percent($share($water['summary']['tap_connections'],$water['summary']['households']),2)
] as $measure=>$value)
<tr><th scope="row">{{ $measure }}</th><td>{{ $value }}</td><td><a href="{{ $water['source_url'] }}">JJM · {{ substr($water['retrieved_at'],0,10) }}</a></td></tr>
@endforeach
@endif
</tbody></table></div></section>
