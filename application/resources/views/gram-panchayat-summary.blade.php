@php
    $tr = fn ($en, $hindi) => $hi ? $hindi : $en;
    $num = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
    $gpUrls = app(\App\Services\GramPanchayatUrls::class);
    $profileUrl = fn (array $query = []) => $gpUrls->link($record['state'], $record['name'], $record['gp'], $query + ['lang' => $hi ? 'hi' : null]);
    $detail = fn ($topic) => $profileUrl(['view' => 'details', 'topic' => $topic]);
    $districts = collect($members)->pluck('district_name')->unique()->implode(', ');
    $stateName = $members[0]['state_name'] ?? '';
    $activities = collect($own['reports'])->flatMap(fn ($report) => array_map(fn ($activity) => $activity + ['financial_year' => $report['financial_year'], 'source_url' => $report['sources']['activities']['url'] ?? null], $report['activities'] ?? []));
    $employment = app(\App\Services\RuralEmployment::class)->forPanchayat((int) $record['state'], (int) $record['gp']);
    $hasRecords = $own['payment_totals'] || $own['allocations'] || $own['reports'];
    $mapMarkup = $section === 'overview' ? view('panchayat-village-map', compact('record', 'members'))->render() : '';
    $mapUrl = trim($mapMarkup) !== '' ? '#panchayat-map' : $profileUrl(['view' => 'details', 'topic' => 'village-data', 'vtopic' => 'map']).'#linked-village-data';
@endphp
<!doctype html>
<html lang="{{ $hi ? 'hi' : 'en' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $record['name'] }} · {{ $tr('Gram Panchayat', 'ग्राम पंचायत') }} · Pollmedia</title>
<meta name="description" content="{{ $record['name'] }} Gram Panchayat, {{ $districts }}: linked villages, Panchayat payments, development works, local services and community issues.">
<link rel="canonical" href="{{ $profileUrl() }}">
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">
@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/pilibhit-summary.css') }}?v=9">
<link rel="stylesheet" href="{{ asset('css/village-consistent.css') }}?v={{ substr(hash_file('sha256', public_path('css/village-consistent.css')), 0, 12) }}">
<link rel="stylesheet" href="{{ asset('css/gram-panchayat-consistent.css') }}?v={{ substr(hash_file('sha256', public_path('css/gram-panchayat-consistent.css')), 0, 12) }}">
<link rel="stylesheet" href="{{ asset('css/panchayat-summary.css') }}?v={{ substr(hash_file('sha256', public_path('css/panchayat-summary.css')), 0, 12) }}">
@if($section === 'overview')
<link rel="stylesheet" href="{{ asset('css/static-district-map.css') }}?v={{ substr(hash_file('sha256', public_path('css/static-district-map.css')), 0, 12) }}">
<script src="{{ asset('js/static-district-map.js') }}?v={{ substr(hash_file('sha256', public_path('js/static-district-map.js')), 0, 12) }}" defer></script>
<script type="module" src="{{ asset('js/panchayat-summary.js') }}?v={{ substr(hash_file('sha256', public_path('js/panchayat-summary.js')), 0, 12) }}"></script>
@else
<link rel="stylesheet" href="{{ asset('css/issue-post.css') }}?v={{ substr(hash_file('sha256', public_path('css/issue-post.css')), 0, 12) }}">
<script src="{{ asset('js/issue-post.js') }}" defer></script>
@endif
</head>
<body class="election-ui pb-site">
@include('public-header', ['summaryLayout' => true])
<main class="pb-dashboard" data-gp-summary data-gp-section="{{ $section }}" data-pilibhit-native>
<nav class="pb-crumb" aria-label="{{ $tr('Breadcrumb', 'स्थान क्रम') }}">
<a href="{{ route('home') }}">{{ $tr('India', 'भारत') }}</a>
@foreach($related as $place)@if($place['kind'] !== 'subdistrict')<span>›</span><a href="{{ $place['url'] }}">{{ $place['name'] }}</a>@endif @endforeach
</nav>
<header class="gp-page-heading"><div><h1>{{ $record['name'] }}</h1><p class="pb-scope">{{ $tr('Gram Panchayat', 'ग्राम पंचायत') }}</p></div>@if($hasRecords)<a class="gp-action" href="{{ $detail('records') }}">{{ $tr('Accounts & vouchers', 'लेखे और वाउचर') }} →</a>@endif</header>
<nav class="gp-view-tabs" aria-label="{{ $tr('Place views', 'स्थान के पृष्ठ') }}">
<a href="{{ $profileUrl() }}" @if($section === 'overview') aria-current="page" @endif>{{ $tr('Overview & data', 'सारांश और आँकड़े') }}</a>
<a href="{{ $profileUrl(['section' => 'issues']) }}" @if($section === 'issues') aria-current="page" @endif>{{ $tr('Community issues', 'स्थानीय समस्याएँ') }}</a>
<a href="{{ route('local-board.index', ['area' => 'gp:'.$record['state'].':'.$record['gp'], 'lang' => $hi ? 'hi' : null]) }}">{{ $tr('Jobs & services', 'काम और सेवाएँ') }}</a>
</nav>
@if($section === 'issues')
<section class="pb-panel pb-wide" id="community-issues">
<div class="gp-section-heading"><h2>{{ $tr('Community issues', 'स्थानीय समस्याएँ') }}</h2><a class="gp-action" href="{{ route('issues.index') }}">{{ $tr('Report a problem', 'समस्या दर्ज करें') }}</a></div>
@forelse($communityFeed['issues'] as $post)
@include('issue-post-card')
@empty<p class="gp-empty">{{ $tr('No published issues from the linked villages yet.', 'संबंधित गाँवों से अभी कोई प्रकाशित समस्या नहीं है।') }}</p>@endforelse
@if($communityFeed['issues'] instanceof \Illuminate\Contracts\Pagination\Paginator){{ $communityFeed['issues']->links() }}@endif
</section>
@else
<nav class="gp-section-nav" aria-label="{{ $tr('On this page', 'इस पृष्ठ पर') }}">
<a href="#panchayat-overview">{{ $tr('Overview', 'सारांश') }}</a><a href="{{ $mapUrl }}">{{ $tr('Map', 'मानचित्र') }}</a><a href="#member-villages">{{ $tr('Villages', 'गाँव') }}</a>
@if($hasRecords)<a href="#panchayat-records">{{ $tr('Accounts', 'लेखे') }}</a>@endif
@if($activities->isNotEmpty())<a href="#development-works">{{ $tr('Development works', 'विकास कार्य') }}</a>@endif
@if($employment)<a href="#rural-employment">{{ $tr('MGNREGA', 'मनरेगा') }}</a>@endif
<a href="#local-services">{{ $tr('Local services', 'स्थानीय सेवाएँ') }}</a><a href="#related-places">{{ $tr('Related places', 'संबंधित स्थान') }}</a><a href="#methodology">{{ $tr('Sources', 'स्रोत') }}</a>
</nav>
<div class="pb-record-grid pb-anchor-pair">
<section class="pb-panel pb-record-card" id="panchayat-overview">
<h2>{{ $tr('Panchayat overview', 'पंचायत का सारांश') }}</h2>
<table class="pb-metrics"><tbody>
<tr><th scope="row">{{ $tr('State', 'राज्य') }}</th><td>{{ $stateName }}</td></tr>
<tr><th scope="row">{{ $tr('District', 'ज़िला') }}</th><td>{{ $districts }}</td></tr>
<tr><th scope="row">{{ $tr('Subdistrict/Taluka/Tehsil', 'उपज़िला/तालुका/तहसील') }}</th><td>{{ collect($members)->pluck('subdistrict_name')->unique()->implode(', ') }}</td></tr>
<tr><th scope="row">{{ $tr('Linked villages', 'संबंधित गाँव') }}</th><td>{{ $num->format(count($members)) }}</td></tr>
</tbody></table>
</section>
<section class="pb-panel pb-record-card" id="local-services"><h2>{{ $tr('Local services', 'स्थानीय सेवाएँ') }}</h2>
<ul class="gp-service-links">
<li><a href="{{ \App\Services\RuralEmployment::WORKER_LOOKUP }}" target="_blank" rel="noopener noreferrer">{{ $tr('MGNREGA · job-card, work & wage status', 'मनरेगा · जॉब कार्ड, कार्य और मजदूरी') }} ↗</a></li>
<li><a href="https://www.ncs.gov.in/" target="_blank" rel="noopener noreferrer">{{ $tr('Find work or hire · National Career Service', 'काम खोजें या भर्ती करें · राष्ट्रीय करियर सेवा') }} ↗</a></li>
<li><a href="https://www.myscheme.gov.in/" target="_blank" rel="noopener noreferrer">{{ $tr('Find government schemes · myScheme', 'सरकारी योजनाएँ खोजें · myScheme') }} ↗</a></li>
<li><a href="{{ (int) $record['state'] === 9 ? 'https://jansunwai.up.nic.in/' : 'https://pgportal.gov.in/' }}" target="_blank" rel="noopener noreferrer">{{ $tr('Official complaint & tracking', 'सरकारी शिकायत और स्थिति') }} ↗</a></li>
</ul>
</section>
</div>
@if(trim($mapMarkup) !== '')<div id="panchayat-map">{!! $mapMarkup !!}</div>@endif
<section class="pb-panel pb-wide" id="member-villages" data-gp-panel="villages">
<div class="gp-section-heading"><h2>{{ $tr('Linked villages', 'संबंधित गाँव') }}</h2><span>{{ $num->format(count($members)) }}</span></div>
@if(count($members) > 10 || $search !== '')<form class="gp-village-search" method="get" action="{{ $profileUrl() }}#member-villages">
@if($hi)<input type="hidden" name="lang" value="hi">@endif
<label for="gp-village-search">{{ $tr('Find a village', 'गाँव खोजें') }}</label><input id="gp-village-search" name="village_q" value="{{ $search }}" maxlength="120"><button class="gp-action" type="submit">{{ $tr('Search', 'खोजें') }}</button>
</form>@endif
<div class="gp-table-scroll"><table class="gp-members">
<thead><tr><th scope="col">{{ $tr('Village', 'गाँव') }}</th><th scope="col">{{ $tr('Subdistrict/Taluka/Tehsil', 'उपज़िला/तालुका/तहसील') }}</th><th scope="col">{{ $tr('Population · 2011', 'जनसंख्या · 2011') }}</th><th scope="col">{{ $tr('Households · 2011', 'परिवार · 2011') }}</th></tr></thead>
<tbody>@forelse($rows as $row)<tr><th scope="row">@if($row['url'])<a href="{{ $row['url'] }}">{{ $row['name'] }}</a>@else{{ $row['name'] }}@endif</th><td>{{ $row['subdistrict'] }}</td><td>{{ $row['population'] === null ? '—' : $num->format($row['population']) }}</td><td>{{ $row['households'] === null ? '—' : $num->format($row['households']) }}</td></tr>
@empty<tr><td colspan="4">{{ $tr('No matching villages.', 'कोई गाँव नहीं मिला।') }}</td></tr>@endforelse</tbody>
</table></div>
@if($villagesPage->hasPages())<nav class="gp-pagination" aria-label="{{ $tr('Village pages', 'गाँव के पृष्ठ') }}"><span>{{ $villagesPage->firstItem() }}–{{ $villagesPage->lastItem() }} / {{ $num->format($villagesPage->total()) }}</span>@if($villagesPage->previousPageUrl())<a class="gp-action" href="{{ $villagesPage->previousPageUrl() }}">{{ $tr('Previous', 'पिछला') }}</a>@endif<span>{{ $villagesPage->currentPage() }} / {{ $villagesPage->lastPage() }}</span>@if($villagesPage->nextPageUrl())<a class="gp-action" href="{{ $villagesPage->nextPageUrl() }}">{{ $tr('Next', 'अगला') }}</a>@endif</nav>@endif
</section>
@include('rural-employment', ['employment' => $employment])
@if($hasRecords)<section class="pb-panel pb-wide" id="panchayat-records"><div class="gp-section-heading"><h2>{{ $tr('Panchayat accounts', 'पंचायत के लेखे') }}</h2><a href="{{ $detail('records') }}">{{ $tr('Vouchers & detailed records', 'वाउचर और विस्तृत रिकॉर्ड') }} →</a></div>
@if($own['payment_totals'])<h3>{{ $tr('Reported payments', 'दर्ज भुगतान') }}</h3>
<div class="gp-table-scroll"><table id="gp-payments" data-gp-paginate><thead><tr><th scope="col">{{ $tr('Financial year', 'वित्तीय वर्ष') }}</th><th scope="col">{{ $tr('Reported total', 'दर्ज कुल') }}</th><th scope="col">{{ $tr('Reference', 'संदर्भ') }}</th></tr></thead><tbody>
@foreach($own['payment_totals'] as $total)<tr data-gp-year="{{ $total['year'] }}"><th scope="row">{{ $total['year'] }}</th><td translate="no">{{ $total['unit'] === 'INR' ? '₹' : '' }}{{ $total['source_amount'] }}@if($total['unit'] === null)<small>{{ $tr('Unit unconfirmed', 'इकाई अपुष्ट') }}</small>@endif</td><td><a href="{{ $total['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $tr('Official report', 'आधिकारिक रिपोर्ट') }} ↗</a><time>{{ $total['captured_on'] }}</time></td></tr>@endforeach
</tbody></table></div>@endif
@if($own['allocations'])<h3>{{ $tr('State Finance Commission allocations', 'राज्य वित्त आयोग के आवंटन') }}</h3>
<div class="gp-table-scroll"><table id="gp-allocations" data-gp-paginate><thead><tr><th scope="col">{{ $tr('Year', 'वर्ष') }}</th><th scope="col">{{ $tr('Instalment', 'किस्त') }}</th><th scope="col">{{ $tr('Amount', 'राशि') }}</th><th scope="col">{{ $tr('Reference', 'संदर्भ') }}</th></tr></thead><tbody>
@foreach($own['allocations'] as $allocation)@foreach($allocation['installments'] as $instalment)<tr><th scope="row">{{ $allocation['year'] }}</th><td>{{ $instalment['label'] }}</td><td translate="no">{{ $allocation['unit'] === 'INR' ? '₹' : '' }}{{ $instalment['amount'] }}@if($allocation['unit'] === null)<small>{{ $tr('Unit unconfirmed', 'इकाई अपुष्ट') }}</small>@endif</td><td><a href="{{ $allocation['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $tr('Official report', 'आधिकारिक रिपोर्ट') }} ↗</a></td></tr>@endforeach @endforeach
</tbody></table></div>@endif
@if($own['reports'])<h3>{{ $tr('Account records', 'लेखा रिकॉर्ड') }}</h3><div class="gp-table-scroll"><table id="gp-account-records" data-gp-paginate><thead><tr><th scope="col">{{ $tr('Financial year', 'वित्तीय वर्ष') }}</th><th scope="col">{{ $tr('Record', 'रिकॉर्ड') }}</th><th scope="col">{{ $tr('Entries', 'प्रविष्टियाँ') }}</th><th scope="col">{{ $tr('Reference', 'संदर्भ') }}</th></tr></thead><tbody>
@foreach($own['reports'] as $report)@foreach(['receipts' => ['Receipt vouchers', 'प्राप्ति वाउचर'], 'payments' => ['Payment vouchers', 'भुगतान वाउचर'], 'contra' => ['Transfers', 'अंतरण'], 'journal' => ['Journal entries', 'जर्नल प्रविष्टियाँ']] as $kind => $label)@if(!empty($report[$kind]))<tr><th scope="row">{{ $report['financial_year'] }}</th><td>{{ $label[$hi ? 1 : 0] }}</td><td>{{ $num->format(count($report[$kind])) }}</td><td><a href="{{ $detail('records') }}&year={{ $report['financial_year'] }}">{{ $tr('View records', 'रिकॉर्ड देखें') }} →</a></td></tr>@endif @endforeach @endforeach
</tbody></table></div>@endif
</section>@endif
@if($activities->isNotEmpty())<section class="pb-panel pb-wide" id="development-works"><div class="gp-section-heading"><h2>{{ $tr('Development works', 'विकास कार्य') }}</h2><a href="{{ $detail('records') }}">{{ $tr('Plans & activity details', 'योजनाएँ और कार्य विवरण') }} →</a></div>
<div class="gp-table-scroll"><table id="gp-works" data-gp-paginate><thead><tr><th scope="col">{{ $tr('Activity', 'कार्य') }}</th><th scope="col">{{ $tr('Year', 'वर्ष') }}</th><th scope="col">{{ $tr('Source status', 'स्रोत की स्थिति') }}</th><th scope="col">{{ $tr('Reference', 'संदर्भ') }}</th></tr></thead><tbody>
@foreach($activities as $activity)<tr><th scope="row">{{ $activity['name'] }}</th><td>{{ $activity['financial_year'] }}</td><td>{{ $activity['status'] ?? '—' }}</td><td>@if($activity['source_url'])<a href="{{ $activity['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $tr('Official plan', 'आधिकारिक योजना') }} ↗</a>@endif</td></tr>@endforeach
</tbody></table></div></section>@endif
<section class="pb-panel pb-wide" id="related-places"><h2>{{ $tr('Related places', 'संबंधित स्थान') }}</h2><ul class="gp-related-list">@foreach($related as $place)<li><a href="{{ $place['url'] }}">{{ $place['name'] }}</a><span>{{ $place['kind'] === 'state' ? $tr('State', 'राज्य') : ($place['kind'] === 'district' ? $tr('District', 'ज़िला') : $tr('Subdistrict/Taluka/Tehsil', 'उपज़िला/तालुका/तहसील')) }}</span></li>@endforeach</ul></section>
<details class="pb-panel pb-wide gp-methodology" id="methodology"><summary>{{ $tr('Methodology, disclaimer & sources', 'कार्यप्रणाली, अस्वीकरण और स्रोत') }}</summary>
<p>{{ $tr('Village membership comes from the Local Government Directory. Population and households are historical village Census figures, not a current Panchayat population estimate. Unmatched figures remain blank.', 'गाँवों की सदस्यता स्थानीय शासन निर्देशिका से है। जनसंख्या और परिवार गाँव की ऐतिहासिक जनगणना के आँकड़े हैं, वर्तमान पंचायत की जनसंख्या का अनुमान नहीं। बेमेल आँकड़े खाली रहते हैं।') }}</p>
<p>{{ $tr('Panchayat payments, allocations and captured vouchers are separate source records. They are not added together or presented as a bank balance. Activity approval does not establish completion. Detailed records retain costs, units, dates and evidence.', 'पंचायत भुगतान, आवंटन और उपलब्ध वाउचर अलग स्रोत रिकॉर्ड हैं। इन्हें जोड़कर बैंक शेष नहीं दिखाया जाता। कार्य की स्वीकृति का अर्थ पूरा होना नहीं है। विस्तृत रिकॉर्ड में लागत, इकाई, तारीख और प्रमाण सुरक्षित हैं।') }}</p>
<p>{{ $tr('Service links open official portals; Pollmedia does not submit applications or government complaints through these links. Community issues are reports from the linked villages, not confirmation of government action.', 'सेवा लिंक आधिकारिक पोर्टल खोलते हैं; Pollmedia इनके माध्यम से आवेदन या सरकारी शिकायत जमा नहीं करता। स्थानीय समस्याएँ संबंधित गाँवों की रिपोर्ट हैं, सरकारी कार्रवाई की पुष्टि नहीं।') }}</p>
<p>{{ $tr('Financial coverage varies by Panchayat and year. A national directory entry does not imply that payment or activity records are available. MGNREGA links open the official portal; job-card and wage records have not been imported into Pollmedia.', 'वित्तीय जानकारी पंचायत और वर्ष के अनुसार अलग है। राष्ट्रीय निर्देशिका में नाम होने का अर्थ भुगतान या कार्य के रिकॉर्ड उपलब्ध होना नहीं है। मनरेगा लिंक आधिकारिक पोर्टल खोलते हैं; जॉब कार्ड और मजदूरी के रिकॉर्ड अभी Pollmedia में आयात नहीं किए गए हैं।') }}</p>
<a href="https://lgdirectory.gov.in/downloadDirectory.do" target="_blank" rel="noopener noreferrer">{{ $tr('Local Government Directory', 'स्थानीय शासन निर्देशिका') }} ↗</a>
</details>
@endif
</main>
@include('public-footer')
</body></html>
