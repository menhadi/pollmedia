<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $village['name'] }} · LGD village · Pollmedia</title><meta name="description" content="{{ $village['name'] }} village population, literacy, household tap connections and available soil sample facts, with reference dates and official sources."><link rel="canonical" href="{{ app(\App\Services\NamedPlaceUrls::class)->village($village) }}"><link rel="stylesheet" href="{{ asset('css/electoral-village-map.css') }}"><link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}"><link rel="stylesheet" href="{{ asset('css/census-area-dashboard.css') }}?v={{ substr(hash_file('sha256',public_path('css/census-area-dashboard.css')),0,12) }}">@include('site-theme')@include('india-map-assets')<link rel="stylesheet" href="{{ asset('css/census-profile.css') }}"><script src="{{ asset('js/history-lines.js') }}" defer></script>@include('place-layout-assets')</head><body class="place-layout election-ui census-area-ui">@include('public-header')
@php $issueService=app(\App\Services\CommunityAreaIssues::class); $issueArea=$issueService->context('village',$village['name'],'',(string)$village['lgd']); @endphp
@include('community-area-issues')


<main class="place-page area-dashboard" data-local-dashboard><div class="village-breadcrumb-bar">@include('location-breadcrumbs',['locationTrail'=>app(\App\Services\PlaceBreadcrumbs::class)->village($village)])</div><h1 class="village-heading">{{ $village['name'] }}</h1><p><a href="{{ route('community.village',$village['lgd']) }}">Edit data</a></p><p>{{ $village['subdistrict'] }} · {{ $village['district'] }} · {{ $village['state_name'] }}</p><p data-methodology-note>Official LGD village code: {{ $village['lgd'] }}</p>
@php($censusMapContext=app(\App\Services\CensusAreaPresentation::class)->lgdMap((string)$village['lgd']))
@php($urbanOverview = app(\App\Services\UrbanCensusLinks::class)->overview($village))
@if($urbanOverview)
<section class="coverage" id="urban-overview-boundaries"><h2>{{ $urbanOverview['label'] }} · Census 2011 {{ strtolower($urbanOverview['level']) }} boundaries</h2><p>The figures below refer to <a href="{{ $urbanOverview['url'] }}">{{ $urbanOverview['record_name'] }}</a> in the 2011 Census. They are not a population estimate for today's LGD village or current ward boundaries.</p></section>
@endif
@php($overviewFigures = $urbanOverview['figures'] ?? ($village['census'] ?? []))
<section class="metrics" id="village-overview" aria-label="Census figures">@foreach(['population'=>'Population','households'=>'Households','literacy_rate'=>'Literacy age 7+','literate'=>'Literate people'] as $key=>$label)<article><span>{{ $label }}</span><strong>{{ isset($overviewFigures[$key])?number_format($overviewFigures[$key],$key==='literacy_rate'?2:0).($key==='literacy_rate'?'%':''):'Unavailable' }}</strong><small>{{ $urbanOverview ? 'Census 2011 · '.strtolower($urbanOverview['level']).' boundaries' : 'Census 2011' }}</small></article>@endforeach</section>

@include('census-local-map',['localMapTitle'=>$village['name'].' · map & filters'])

@isset($village['village_url'])<p><a href="{{ $village['village_url'] }}">Open full village profile, administration and historical records →</a></p>@endisset
@if(!empty($village['navigation']))<section class="coverage"><h2>Explore this area</h2>@foreach($village['navigation'] as $destination)<p><a href="{{ $destination['url'] }}">{{ $destination['label'] }} →</a></p>@endforeach</section>@endif
@include('place-village-facts')
@include('census-local-history')
@include('place-market-panel', ['marketPageScope'=>app(\App\Services\PageMarketContext::class)->lgdVillages([(string)$village['lgd']])])

@include('place-postal-codes')
@isset($village['administration'])<section class="coverage"><h2>Location</h2><p>LGD district: {{ $village['administration']['district'] }} · code {{ $village['administration']['district_code'] }}<br>LGD subdistrict: {{ $village['administration']['subdistrict'] }} · code {{ $village['administration']['subdistrict_code'] }}</p></section>@endisset
@isset($village['constituencies'])<section class="coverage"><h2>Your constituencies</h2>
@forelse($village['constituencies'] as $seat)<p><a href="{{ $seat['url'] }}">{{ strtoupper($seat['kind']) }}: {{ $seat['name'] }} →</a> · {{ $seat['partial'] ? 'Partly covered' : 'Fully covered' }}<br><small data-methodology-note>LGD export rows: {{ implode(', ', $seat['source_rows']) }}</small></p>@empty<p>No village constituency relationship is available in this imported LGD release.</p>@endforelse
<p data-methodology-note><a href="{{ $village['relationship_source']['url'] }}">Official LGD directory and constituency coverage export →</a> · checked {{ $village['relationship_source']['checked_on'] }}</p><p data-methodology-note>Ward membership requires its own official local-body relationship; a ward is not inferred from this village.</p></section>@endisset
@isset($village['census_directory'])
@isset($village['census_directory']['edit_url'])<p><a href="{{ $village['census_directory']['edit_url'] }}">Edit these Census values →</a></p>@endisset
<section class="coverage"><h2>Census 2001 · village details</h2>
@foreach($village['census_directory']['notes'] as $note)<p data-methodology-note>† {{ $note }}</p>@endforeach
<details><summary>Education, Census water and all reported directory values</summary><table><thead><tr><th>Parameter</th><th>Original 2001 value</th></tr></thead><tbody>
@foreach($village['census_directory']['values'] as $key => $value)<tr><th>{{ $village['census_directory']['fields'][$key] ?? $key }}</th><td @if(preg_match('~/data/census/records/([0-9]+)~',$village['census_directory']['edit_url']??'', $sourceMatch)) data-correction-record="{{ $sourceMatch[1] }}" data-correction-field="{{ $key }}" @endif>{{ $value === null ? 'Not reported' : $value }}</td></tr>@endforeach
</tbody></table></details><p><a href="{{ $village['census_directory']['url'] }}">Original source records and definitions</a></p></section>
@endisset
@foreach($village['census_history'] ?? [] as $history)
@isset($history['pca'])
@isset($history['pca']['edit_url'])<p><a href="{{ $history['pca']['edit_url'] }}">Edit these population and education values →</a></p>@endisset
<section class="coverage"><h2>Census 2001 · population & education</h2>
@foreach($history['pca']['notes'] as $note)<p data-methodology-note>† {{ $note }}</p>@endforeach
<details><summary>All reported population, education and worker values</summary><table><tbody>
@foreach($history['pca']['values'] as $key => $value)<tr><th>{{ $key }}</th><td @if(preg_match('~/data/census/records/([0-9]+)~',$history['pca']['edit_url']??'', $sourceMatch)) data-correction-record="{{ $sourceMatch[1] }}" data-correction-field="{{ $key }}" @endif>{{ $value === null ? 'Not reported' : $value }}</td></tr>@endforeach
</tbody></table></details><p><a href="{{ $history['pca']['url'] }}">Original source record and definitions</a></p></section>
@endisset
@endforeach
<section class="coverage"><h2>Population, literacy and other records</h2>
<p data-methodology-note>Official Census 2011 crosswalk code: {{ $village['census2011'] ?: 'Unavailable' }}. The code identifies a Census record; it does not establish the polygon’s boundary edition.</p>
@isset($village['census'])
<h3>Census 2011</h3>
<p>Population: {{ isset($village['census']['population']) ? number_format($village['census']['population']) : 'Unavailable' }} · Households: {{ isset($village['census']['households']) ? number_format($village['census']['households']) : 'Unavailable' }} · Literate people: {{ isset($village['census']['literate']) ? number_format($village['census']['literate']) : 'Unavailable' }}</p>
<p>Literacy rate: {{ isset($village['census']['literacy_rate']) ? $village['census']['literacy_rate'].'%' : 'Unavailable' }}. </p>
<a data-methodology-note href="{{ $village['census_source'] }}">Official Census source →</a>
@endisset
@isset($village['census_url'])<p><a href="{{ $village['census_url'] }}">Open existing Census profile and all available measures →</a></p>
@else<p>A published Census profile is not currently linked to this village code.</p>@endisset
</section>
<section class="coverage"><h2>Source</h2><p>Official LGD all-India village directory and constituency coverage export, checked 2026-10-13.</p><a href="https://lgdirectory.gov.in/downloadDirectory.do">Official LGD directory →</a></section>@include('jjm-water-village')
</main>@include('community-edit-tools')<script type="module" src="{{ asset('js/census-local-dashboard.js') }}?v={{ substr(hash_file('sha256',public_path('js/census-local-dashboard.js')),0,12) }}"></script>@include('public-footer')</body></html>
