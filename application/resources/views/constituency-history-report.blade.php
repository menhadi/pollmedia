<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>{{ \Illuminate\Support\Str::title($name) }} · Election history report · Pollmedia</title>
@include('site-theme')
<link rel="stylesheet" href="/css/history-report.css?v={{ substr(hash_file('sha256',public_path('css/history-report.css')),0,12) }}"></head><body class="history-report">
<div class="report-actions"><button onclick="window.print()">Print / Save as PDF</button><p>Choose “Save as PDF” in the print dialog. All chart values and sources are included.</p></div>
<header class="report-masthead"><div class="report-brand">{{ app(\App\Services\SiteSettings::class)->appearance()['name'] }}<span>Historical election report</span></div><h1>{{ \Illuminate\Support\Str::title($name) }}</h1><p class="report-subtitle">{{ $state }} · {{ $kind==='pc'?'Parliamentary constituency':'Assembly constituency' }}</p><div class="report-meta"><span><strong>{{ $rows->min('entry.year') }}–{{ $rows->max('entry.year') }}</strong> Reference years</span><span><strong>{{ $rows->pluck('entry.year')->unique()->count() }}</strong> Available years</span><span>Prepared {{ now()->format('d M Y') }}</span></div></header>
<main>@include('printable-election-map',['printMapState'=>$state,'printMapSeat'=>$name])
@include('place-history-charts',['reportMode'=>true])
@php $hasSourceReportedPolled=false; @endphp
<section id="history"><h2>Election history and official references</h2><table><thead><tr><th>Year</th><th>Winner / party</th><th>Votes polled</th><th>Turnout</th><th>Margin (votes)</th></tr></thead><tbody>
@foreach($rows as $row)
@php $summary=$row['record']?app(\App\Services\HistoricalElectionAnalytics::class)->summarize([$row['record']]):[]; $sourceReportedPolled=($summary['polled']??null)===null && is_int($row['record']['votes_polled']??null) && $row['record']['votes_polled']>0 ? $row['record']['votes_polled'] : null; $hasSourceReportedPolled=$hasSourceReportedPolled || $sourceReportedPolled!==null; @endphp
<tr><th>{{ $row['entry']->year }}</th><td>{{ $row['result']['winner']??'Not established' }}<br>{{ $row['result']['party']??'—' }}@if($row['record']['has_warning']??false) †@endif</td><td>{{ isset($summary['polled'])?number_format($summary['polled']):($sourceReportedPolled===null?'—':number_format($sourceReportedPolled).' ‡') }}</td><td>{{ isset($summary['turnout'])?number_format($summary['turnout'],2).'%':'—' }}</td><td>{{ isset($summary['margin'])?number_format($summary['margin']):'—' }}</td></tr>
@endforeach
</tbody></table>@if($hasSourceReportedPolled)<p>‡ Vote count from the linked source record; it is not used for a comparable turnout percentage. See the source and data note below.</p>@endif<h2>Sources and data notes</h2>
@foreach($rows as $row)<article class="source-note"><h3>{{ $row['entry']->edition_label }} · record {{ $row['entry']->record_code }}</h3>@if($row['source'])<a href="{{ $row['source'] }}">{{ $row['source'] }}</a>@else<p>Original report is currently unavailable here.</p>@endif
@if($row['record']['has_warning']??false)<p>† {{ $row['record']['error']??'This source record requires review.' }}</p>@endif
@foreach(['detail_page'=>'Detailed results PDF page','summary_page'=>'Summary PDF page','source_locator'=>'Source location','summary_locator'=>'Summary location'] as $key=>$label)@if(isset($row['record'][$key]))<p>{{ $label }}: {{ $row['record'][$key] }}</p>@endif @endforeach
</article>@endforeach</section>
@include('election-methodology',['isStateHistory'=>false])
</main>
<footer><strong>Pollmedia</strong><a href="{{ route('constituency.overview',array_filter(['kind'=>$kind,'state'=>$state,'name'=>$name,'edition'=>$exactSeatOnly?$chosen['entry']->edition_id:null,'code'=>$exactSeatOnly?$chosen['entry']->record_code:null])) }}">View the interactive history and updated sources</a></footer></body></html>
