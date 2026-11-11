<?php $topic = \App\Services\PlaceTopics::selected('census'); ?>
<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">@include('seo-metadata',['breadcrumbs'=>['India'=>route('home'),'Census'=>route('civic.index')],'canonical'=>$place->level==='STATE'?route('civic.state',['state'=>\App\Services\PlaceFactTables::stateSlug($place->name),'census'=>(int)$year===2011?null:$year]):app(\App\Services\NamedPlaceUrls::class)->census($place->id),'seoTitle'=>\Illuminate\Support\Str::title($title).' Census '.$year.': Population & Literacy | Pollmedia','seoDescription'=>'Explore '.\Illuminate\Support\Str::title($title).' Census '.$year.' population, literacy, households, maps, graphs and district comparisons, plus available water, soil and rainfall records.'])
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}"><link rel="stylesheet" href="{{ asset('css/census-area-dashboard.css') }}?v={{ substr(hash_file('sha256',public_path('css/census-area-dashboard.css')),0,12) }}">@include('site-theme')<?php if (in_array($topic, ['map'], true)): ?>
@include('india-map-assets')
<?php endif; ?><script src="{{ asset('js/census-profile.js') }}" defer></script><script src="{{ asset('js/history-lines.js') }}?v={{ substr(hash_file('sha256',public_path('js/history-lines.js')),0,12) }}" defer></script>@include('place-layout-assets')@if($place->level==='STATE')<link rel="stylesheet" href="{{ asset('css/public-landings.css') }}?v={{ substr(hash_file('sha256',public_path('css/public-landings.css')),0,12) }}"><script src="{{ asset('js/public-landings.js') }}?v={{ substr(hash_file('sha256',public_path('js/public-landings.js')),0,12) }}" defer></script>@endif @if($place->level==='STATE')<link rel="stylesheet" href="{{ asset('css/compact-area-pages.css') }}?v={{ substr(hash_file('sha256',public_path('css/compact-area-pages.css')),0,12) }}">@endif<link rel="stylesheet" href="{{ asset('css/static-district-map.css') }}?v={{ substr(hash_file('sha256',public_path('css/static-district-map.css')),0,12) }}"><script src="{{ asset('js/static-district-map.js') }}?v={{ substr(hash_file('sha256',public_path('js/static-district-map.js')),0,12) }}" defer></script></head><body class="place-layout election-ui census-area-ui">@include('public-header', ['summaryLayout'=>$place->level==='STATE','fullHeaderDictionary'=>true,'compactCensusHeader'=>in_array($place->level,['DISTRICT','VILLAGE'],true)])
@php
$isState = $place->level === 'STATE';
$isVillage = in_array($place->level,['VILLAGE','TOWN'],true);
$areaRawValues=$areaPresentation['values'];
$districtCatalogue=app(\App\Services\DistrictDashboardUrls::class);
$districtProfiles=collect();
if ($isState && (int)$year===2011) {
    foreach ($districtOptions as $option) {
        $profile=$districtCatalogue->identity($option->state_code,$option->district_code);
        if ($profile) {
            $profile['source_name']=\Illuminate\Support\Str::title($option->name);
            $profile['url']=url($profile['path']).(app()->getLocale()==='hi'?'?lang=hi':'');
            $districtProfiles->put($option->district_code,$profile);
        }
    }
}
$stateDistrictRows=$isState && $topic==='places'?$dashboardChildren->groupBy('level'):collect();
$previewChildren=$stateDistrictRows->isNotEmpty()?$stateDistrictRows->map(fn($group)=>\App\Services\StateTablePreview::initial($group))->collapse():$dashboardChildren;
$areaRows=($topic==='places'?$previewChildren:collect())->map(function ($row) use ($isState,$districtProfiles) {
    $values=\App\Services\VillageMapMetrics::values(json_decode($row->values,true));
    $profile=$row->level==='DISTRICT'?$districtProfiles->get($row->district_code):null;
    return [
        'id'=>$row->id,
        'name'=>preg_replace('/\s+district$/i','',$profile['name']??$row->name),
        'seats'=>$profile?app(\App\Services\DistrictConstituencyLinks::class)->forDistrict($profile['name'],$profile['state_name']):null,
        'level'=>$row->level,
        'code'=>$row->district_code,
        'village_code'=>$row->level==='VILLAGE'?(int)(json_decode($row->geography,true)['Town/Village']??0):null,
        'url'=>$isState?($profile['url']??null):app(\App\Services\NamedPlaceUrls::class)->census($row->id),
    ]+$values;
});
$areaValues=\App\Services\VillageMapMetrics::values($areaRawValues);
$areaStateSlug=\App\Services\PlaceFactTables::stateSlug($place->name);
$villageGps=(int)$year===2011 ? app(\App\Services\GramPanchayatDirectory::class)->forCensusVillages($areaRows->pluck('village_code')->all()) : [];
@endphp
<main class="area-dashboard {{ $isState?'pm-state-census':'' }}"><header class="area-intro district-title {{ $isState?'landing-intro':'' }}"><p class="eyebrow">Census {{ $year }} · {{ $isState?'State / Union territory':($isVillage?'Village / town':'Subdistrict') }}</p><h1>{{ $isState && in_array($topic,['overview','places'],true)?\Illuminate\Support\Str::title($title).' '.\App\Services\PublicLanguage::text('Districts'):$title }}</h1>
@if($isState)<form class="landing-controls" action="{{ route('civic.index') }}"><input type="hidden" name="state" value="{{ $areaStateSlug }}"><label>Census year<select name="year" data-submit-choice>@foreach($years as $option)<option value="{{ $option }}" @selected((int)$year===(int)$option)>{{ $option }}</option>@endforeach</select></label><button>View year</button>@if($districtProfiles->isNotEmpty())
<label>{{ app()->getLocale()==='hi'?'ज़िला':'District' }}<select data-go-url aria-label="{{ app()->getLocale()==='hi'?'ज़िला चुनें':'Choose a district' }}">
<option value="">{{ app()->getLocale()==='hi'?'सभी ज़िले':'All districts' }}</option>
@foreach($districtProfiles as $profile)<option value="{{ $profile['url'] }}">{{ $profile['source_name'] }}</option>@endforeach
</select></label>
@endif

</form>@endif</header>
@include('place-topic-nav',['topicGroup'=>'census','topicOptions'=>array_values(array_filter(\App\Services\PlaceTopics::GROUPS['census'],fn($k)=>($k!=='elections'||$isState)&&($k!=='markets'||$place->level==='VILLAGE')&&(!in_array($k,['water','soil','rainfall'],true)||($place->level==='VILLAGE'&&(int)$year===2011)||($isState&&$k!=='rainfall'&&(int)$year===2011))) )])
<div data-topic-body>
@if(in_array($topic,['people','water','soil'],true) && $isState && (int)$year===2011 && $residence==='Total')
@include('place-fact-tables',['onlyTopic'=>$topic,'facts'=>app(\App\Services\PlaceFactTables::class)->state($areaStateSlug,$place->name)])
@elseif($place->level==='VILLAGE' && (int)$year===2011 && $topic==='soil')
@include('place-village-facts',['onlyTopic'=>'soil'])
@elseif($place->level==='VILLAGE' && (int)$year===2011 && $topic==='rainfall')
@include('rainfall-panel')
@endif

<?php if (in_array($topic, ['issues'], true)): ?>
<?php $issueArea = app(\App\Services\CommunityAreaIssues::class)->censusContext($place, $censusMapContext); ?>
@include('community-area-issues')
<?php endif; ?>
@if($isState && in_array($topic,['overview'],true))
@include('state-district-glance',['showDistrictMap'=>$isState && $topic==='overview' && (int)$year===2011])
@if($topic==='overview')
<section class="landing-card"><h2>Population history</h2>
@foreach(collect($censusSeries['rows'])->whereNotNull('population')->sortByDesc('year')->take(3)->reverse() as $point)<div class="landing-stat"><span>{{ $point['year'] }}</span><strong>{{ number_format($point['population']) }}{{ !empty($point['notes'])?' †':'' }}</strong></div>@endforeach
<a href="{{ request()->fullUrlWithQuery(['topic'=>'history']) }}">History and source notes →</a></section>
@endif
@else
<?php if (in_array($topic, ['overview'], true)): ?>
<section id="area-overview" class="metrics">@foreach(['population'=>'Population','households'=>'Households','literacy'=>'Literacy age 7+'] as $key=>$label)<article><span>{{ $label }}</span><strong>{{ isset($areaValues[$key])?number_format($areaValues[$key],$key==='literacy'?2:0).($key==='literacy'?'%':''):'Unavailable' }}</strong><small>Census {{ $year }} · {{ $residence }}</small></article>@endforeach @unless($isState)<article><span>{{ $isVillage?'Females per 1,000 males':($isState?'Districts in this source':'Villages & towns in this source') }}</span><strong>{{ $isVillage?((($areaRawValues['TOT_M']??0)>0 && isset($areaRawValues['TOT_F']))?number_format(1000*$areaRawValues['TOT_F']/$areaRawValues['TOT_M']):'Unavailable'):$areaRows->count() }}</strong><small>{{ $isVillage?'Census '.$year.' · '.$residence:'Unique source identities' }}</small></article>@endunless</section>
<?php endif; ?>
@endif

<?php if (in_array($topic, ['map'], true)): ?>
@if($isState)
<?php $censusMapContext = ['state' => $place->state_code]; ?>
<div id="area-map">@include('census-local-map',['localMapTitle'=>$title.' · map & filters','stateMap'=>true])</div>
@endif
@unless($isState)@include('census-local-map',['localMapTitle'=>$title.' · map & filters'])@endunless
<?php endif; ?>
<?php if (in_array($topic, ['people'], true)): ?>
<section class="panel"><form method="get" class="filters"><input type="hidden" name="topic" value="people"><label>Data group<select name="group">@foreach(['population'=>'Population','households'=>'Households','literacy'=>'Literacy','work'=>'Work'] as $key=>$label)<option value="{{ $key }}" @selected($group===$key)>{{ $label }}</option>@endforeach</select></label><label>Population group<select name="residence">@foreach($residenceOptions as $option)<option @selected($residence===$option)>{{ $option }}</option>@endforeach</select></label><button>Apply filters</button></form></section>

<section class="panel area-literacy"><h2>Literacy among people aged 7+</h2><div class="metrics">@foreach(['literacy'=>'All people','male_literacy'=>'Male','female_literacy'=>'Female'] as $key=>$label)<article><span>{{ $label }}</span><strong>{{ $areaPresentation['rates'][$key]===null?'Unavailable':number_format($areaPresentation['rates'][$key],2).'%' }}</strong><small>Census {{ $year }} · {{ $residence }}</small></article>@endforeach</div><p class="small">Literate people divided by the corresponding population aged seven and above.</p></section>
<section id="area-state-data" class="panel"><h2>{{ $title }} · {{ $isState?'state':'place' }} figures</h2><p>Census {{ $year }} · {{ $residence }}</p><details class="area-data-table" @if($isState) open @endif><summary>Population, education, households and work · available stored measures</summary><div class="table-wrap"><table><thead><tr><th>Measure</th><th>Value</th></tr></thead><tbody>
<?php $stateLabels = ['TOT_P' => 'Population', 'TOT_M' => 'Male population', 'TOT_F' => 'Female population', 'No_HH' => 'Households', 'P_06' => 'Children aged 0–6', 'M_06' => 'Male children aged 0–6', 'F_06' => 'Female children aged 0–6', 'P_LIT' => 'Literate people', 'M_LIT' => 'Literate males', 'F_LIT' => 'Literate females', 'P_ILL' => 'Illiterate people', 'M_ILL' => 'Illiterate males', 'F_ILL' => 'Illiterate females', 'TOT_WORK_P' => 'Total workers', 'TOT_WORK_M' => 'Male workers', 'TOT_WORK_F' => 'Female workers', 'MAINWORK_P' => 'Main workers', 'MAINWORK_M' => 'Male main workers', 'MAINWORK_F' => 'Female main workers', 'MARGWORK_P' => 'Marginal workers', 'MARGWORK_M' => 'Male marginal workers', 'MARGWORK_F' => 'Female marginal workers', 'NON_WORK_P' => 'Non-workers', 'P_SC' => 'Scheduled Caste population', 'M_SC' => 'Scheduled Caste males', 'F_SC' => 'Scheduled Caste females', 'P_ST' => 'Scheduled Tribe population', 'M_ST' => 'Scheduled Tribe males', 'F_ST' => 'Scheduled Tribe females']; ?>
<?php $workerLabels = ['CL' => 'cultivators', 'AL' => 'agricultural labourers', 'HH' => 'household industry workers', 'OT' => 'other workers']; ?>
<?php $stateFieldLabel = function ($field) use ($stateLabels, $workerLabels) {
    if (isset($stateLabels[$field])) {
        return $stateLabels[$field];
    } if (preg_match('/^(MAIN|MARG)_(CL|AL|HH|OT)_([PMF])$/', $field, $parts)) {
        return ($parts[1] === 'MAIN' ? 'Main' : 'Marginal').' '.$workerLabels[$parts[2]].($parts[3] === 'M' ? ' · male' : ($parts[3] === 'F' ? ' · female' : ''));
    }

return \Illuminate\Support\Str::headline(str_replace('_', ' ', $field));
}; ?>
@foreach($areaRawValues as $field=>$value)@if(is_numeric($value))<tr><th scope="row">{{ $stateFieldLabel($field) }}</th><td>{{ number_format($value,(float)$value===floor((float)$value)?0:2) }}</td></tr>@endif @endforeach
</tbody></table></div></details></section>
<?php endif; ?>
<?php if (in_array($topic, ['water'], true)): ?>
@if($isState)
<section id="area-environment" class="panel"><h2>Water, soil &amp; rainfall</h2><p>Use the map's <strong>Map data</strong> filter to select Water (JJM), Soil or Rainfall. Reporting periods follow each dataset independently of the Census year. Select a place to see its existing observations and missing-data notes.</p><a href="#area-map">Open map &amp; filters ↑</a></section>
@endif
@if($place->level==='VILLAGE' && (int)$year===2011)@include('jjm-water-village',['onlyTopic'=>'water'])@endif
<?php endif; ?>
<?php if (in_array($topic, ['history'], true)): ?>
<section id="area-history">@includeIf('census-area-literacy-chart')@include('census-profile-charts')@include('census-selected-year')</section>
<?php endif; ?>
<?php if (in_array($topic, ['places'], true)): ?>
@if((int)$year===2011 && in_array($place->level,['SUB-DISTRICT','SUBDISTRICT','TEHSIL','DISTRICT'],true))
@php
    $gpSubdistrictCode=(int)(json_decode($place->geography,true)['Subdistt']??0);
@endphp
@if($place->level==='DISTRICT' || $gpSubdistrictCode>0)
@include('gram-panchayat-panel',['gpScope'=>'census','gpState'=>(int)$place->state_code,'gpDistrict'=>(int)$place->district_code,'gpSubdistrict'=>$place->level==='DISTRICT'?null:$gpSubdistrictCode])
@endif
@endif
@if($areaRows->isNotEmpty())
<section id="area-tables" class="panel"><h2>{{ $isState?'District':'Village & town' }} data</h2><label>Find a place<input type="search" data-area-table-search placeholder="Filter by name"></label>
@foreach($areaRows->groupBy('level') as $level=>$rows)<details class="area-data-table" @if($isState) open @endif><summary>{{ \Illuminate\Support\Str::headline(strtolower($level)) }} · {{ $stateDistrictRows->get($level)?->count()??$rows->count() }} records available</summary><div class="table-wrap" @if($isState) role="region" aria-label="{{ app()->getLocale()==='hi'?'ज़िले के जनगणना आँकड़े':'District Census figures' }}" tabindex="0" @endif><table @if($isState) data-total-rows="{{ $stateDistrictRows->get($level)?->count()??$rows->count() }}" @endif><thead><tr><th scope="col">{{ $isState?\App\Services\PublicLanguage::text('District'):'Place' }}</th>@if($level==='VILLAGE' && $villageGps)<th>Gram Panchayat</th>@endif<th scope="col">Population</th><th scope="col">Households</th><th scope="col">Literacy age 7+</th></tr></thead><tbody>@foreach($rows as $row)<tr data-area-row><th scope="row">@if($row['url'])<a href="{{ $row['url'] }}">{{ $row['name'] }}</a>@else{{ $row['name'] }}@endif
@if($row['seats']['available']??false)
<details class="district-seat-names"><summary>{{ app()->getLocale()==='hi'?'संबंधित निर्वाचन क्षेत्र':'Linked constituencies' }}</summary>
@foreach(['parliamentary'=>'Lok Sabha (PC)','assembly'=>'Assembly (AC)'] as $kind=>$label)
@if(!empty($row['seats'][$kind]))<div><b>{{ $label }}</b> · {{ collect($row['seats'][$kind])->pluck('name')->implode(', ') }}</div>@endif
@endforeach
</details>
@endif
</th>@if($level==='VILLAGE' && $villageGps)<td>@include('gram-panchayat-cell',['gp'=>$villageGps[$row['village_code']]??null])</td>@endif<td>{{ isset($row['population'])?number_format($row['population']):'Unavailable' }}</td><td>{{ isset($row['households'])?number_format($row['households']):'Unavailable' }}</td><td>{{ isset($row['literacy'])?number_format($row['literacy'],2).'%':'Unavailable' }}</td></tr>@endforeach</tbody></table></div></details>@endforeach</section>
@endif

@if($parents->isNotEmpty() || $linked->isNotEmpty() || $authorities->isNotEmpty())
<section class="panel" id="area-connections"><h2>Linked places &amp; available authorities</h2><div class="area-section-links">@foreach($parents as $parent)<a href="{{ app(\App\Services\NamedPlaceUrls::class)->census($parent->id) }}">{{ $parent->name }}</a>@endforeach @foreach($linked as $item)<a href="{{ in_array($item['place']->type,['ac','pc'],true)?\App\Services\ConstituencyUrls::url(['kind'=>$item['place']->type,'state'=>$electoralState,'name'=>$item['place']->name]):app(\App\Services\CensusPlaceNavigation::class)->url($item['place']) }}">{{ $item['place']->name }}</a>@endforeach</div>@if($authorities->isNotEmpty())<div class="table-wrap"><table><thead><tr><th>Office</th><th>Officeholder</th></tr></thead><tbody>@foreach($authorities as $authority)<tr><th>{{ $authority->title }}</th><td>{{ $authority->display_name??'Unavailable' }}</td></tr>@endforeach</tbody></table></div>@endif</section>
@endif
<?php endif; ?>
<?php if (in_array($topic, ['elections'], true)): ?>
@if($isState)
@php
$electionStateName=\App\Services\ElectionPlaceIdentity::state($place->name);
$electionStatePage=app(\App\Services\ElectionGeographySummary::class)->states()->first(fn($row)=>mb_strtolower($row['name'])===mb_strtolower($electionStateName));
$stateSeats=collect();$seatYears=[];
foreach(['pc','ac'] as $seatKind){
    if($seatKind==='ac' && in_array((int)$place->state_code,[4,25,26,31,35],true))continue;
    $seatQuery=\Illuminate\Support\Facades\DB::table('historical_constituency_index')->where('kind',$seatKind)->whereRaw(\App\Services\ElectionPlaceIdentity::stateSql().' = ?',[mb_strtolower($electionStateName)]);
    $seatYears[$seatKind]=(clone $seatQuery)->max('year');
    $seatEdition=(clone $seatQuery)->where('year',$seatYears[$seatKind])->select('edition_id')->groupBy('edition_id')->orderByRaw('COUNT(*) DESC')->orderByDesc('edition_id')->value('edition_id');
    foreach((clone $seatQuery)->where('edition_id',$seatEdition)->select('constituency_name')->distinct()->orderBy('constituency_name')->get() as $seat)$stateSeats->push(['kind'=>$seatKind,'name'=>\Illuminate\Support\Str::title($seat->constituency_name)]);
}
@endphp
<section id="area-elections" class="panel"><h2>Lok Sabha &amp; State Assembly · {{ $title }}</h2>
@if($electionStatePage)<p><a href="{{ url('/india/state/'.$electionStatePage['slug']) }}">{{ $title }} election results &amp; history →</a></p>@endif
@foreach(['pc'=>'Lok Sabha','ac'=>'State Assembly'] as $seatKind=>$seatLabel)
<details class="place-data-fold"><summary>{{ $seatLabel }} · {{ $stateSeats->where('kind',$seatKind)->count() }} available constituencies{{ isset($seatYears[$seatKind])?' · '.$seatYears[$seatKind]:'' }}</summary><div class="table-wrap"><table><thead><tr><th>Constituency</th><th>Results &amp; history</th></tr></thead><tbody>
@forelse($stateSeats->where('kind',$seatKind) as $seat)<tr><td>{{ $seat['name'] }}</td><td><a href="{{ \App\Services\ConstituencyUrls::url(['kind'=>$seatKind,'state'=>$electionStateName,'name'=>$seat['name']]) }}">View {{ $seatLabel }} results →</a></td></tr>@empty<tr><td colspan="2">No corresponding constituency records are available in the stored election index.</td></tr>@endforelse
</tbody></table></div></details>@endforeach</section>
@endif
<?php endif; ?>
<?php if (in_array($topic, ['markets'], true)): ?>
@if($place->level==='VILLAGE')
@include('place-market-panel', ['marketPageScope'=>app(\App\Services\PageMarketContext::class)->censusVillage((int)$year,(string)(json_decode($place->geography,true)['Town/Village']??''))])
@endif
<?php endif; ?>
<?php if (in_array($topic, ['sources'], true)): ?>
<details id="area-methodology" class="panel approved-disclaimer" data-compact-group="Sources"><summary>Methodology and disclaimer</summary><div class="approved-disclaimer-body"><h3>Census geography and dates</h3><p>Figures retain their original {{ $year }} reporting year and source geography. Missing data is unavailable, never zero. Literacy uses people aged seven and above.</p><h3>Map boundaries</h3><p>The map uses existing source district polygons and established dashboard navigation. Later boundaries are geographic context; matching historical boundaries are not asserted. Values are assigned only when navigation identifies one district in this table.</p>@if($edition)<h3>Original source</h3><a href="{{ $edition->source_url }}">{{ $edition->name }}</a>@endif<h3>Water, soil and rainfall</h3><p>JJM measures summarize accepted linked rural observations in the original district identifiers, not complete current district totals. Soil colours describe tested samples for the selected reporting cycle, not all land. IMD rainfall is an area-weighted estimate from existing 0.25° grids for the selected year, not district rain-gauge observations. Missing identities, samples and grid coverage stay unavailable. These sources are independent of Census year.</p><p><a href="https://ejalshakti.gov.in/">Official JJM source</a> · <a href="https://soilhealth.dac.gov.in/">Official Soil Health Card source</a> · <a href="https://imdpune.gov.in/cmpg/Griddata/Rainfall_25_NetCDF.html">Official IMD grid source</a></p><h3>Constituency context</h3><p>Small constituency labels below place names use the existing source-backed electoral directory. Constituency boundaries and reporting dates differ from Census districts; these are navigation links, not population assignments.</p><h3>State count aggregation</h3><p>Missing state count fields are filled only when all {{ $areaPresentation['expected_districts'] }} source districts have one agreed value in the same Census year and population group. Original state values take precedence. Missing or conflicting district counts remain unavailable; percentages are calculated from summed numerator and denominator counts, never averaged.</p><p>{{ count($areaPresentation['aggregated_fields']) }} count fields filled from existing district records.</p><h3>Other records and years</h3><a href="{{ route('civic.index',['year'=>$year]) }}">Census directory</a> · <a href="{{ \App\Services\CensusPlaceNavigation::catalogueUrl(['edition'=>$edition?->id,'state'=>$place->state_code]) }}">Detailed source records</a></div></details>
<?php endif; ?>
</div>
</main>@include('public-footer')@if(is_file(public_path('js/census-area-dashboard.js')))<script type="module" src="{{ asset('js/census-area-dashboard.js') }}?v={{ substr(hash_file('sha256',public_path('js/census-area-dashboard.js')),0,12) }}"></script>@endif</body></html>
