<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Choose an election record | {{ $name }} | Pollmedia</title><link rel="stylesheet" href="/css/election-dashboard.css">@include('site-theme')</head>
<body class="election-ui public-place-page">@include('public-header')
<div class="dashboard-shell constituency-page"><main class="dashboard-main">
<section class="panel"><h1>{{ $name }}: choose an election record</h1>
<p>Several constituencies share this name in the archived reports. Choose a year and constituency number to open its results.</p>
<div class="table-scroll"><table><thead><tr><th>Year</th><th>Constituency</th><th>Number in report</th><th>Election report</th><th>Results</th></tr></thead><tbody>
@foreach($entries as $entry)
<tr><th>{{ $entry->year }}</th><td>{{ $entry->constituency_name }}</td><td>{{ $entry->record_code }}</td><td>{{ $entry->edition_label }}</td><td><a href="{{ \App\Services\ConstituencyUrls::url(['kind'=>$kind,'state'=>$state,'name'=>$name,'edition'=>$entry->edition_id,'code'=>$entry->record_code,'format'=>request('format')]) }}">Open {{ $entry->year }} results | No. {{ $entry->record_code }}</a></td></tr>
@endforeach
</tbody></table></div>
<p class="small">Each link opens one record. Records with the same name are kept separate; no combined voting history is inferred.</p>
<p><a href="{{ route('elections.constituencies',['kind'=>$kind,'state'=>$state,'q'=>$name]) }}">Browse the election directory</a></p>
</section></main></div>@include('public-footer')</body></html>
