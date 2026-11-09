@php
    $hi = app()->getLocale() === 'hi';
    $tr = fn($en,$hindi) => $hi ? $hindi : $en;
    $record = $chosen['record'] ?? null;
    $result = $chosen['result'] ?? null;
    $metrics = $atlasAnalytics;
    $fmt = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
    $sourcePolled = $record['votes_polled'] ?? null;
    $polled = $metrics['polled'] ?? (is_int($sourcePolled) && $sourcePolled > 0 ? $sourcePolled : null);
    $comparable = isset($metrics['polled']) && $metrics['polled'] > 0 && !empty($metrics['parties']);
    $leaders = collect($record['candidates'] ?? [])->reject(fn($c)=>!empty($c['is_nota']) || !isset($c['votes']))->sortByDesc('votes')->take(3);
    $alternatives = $rows->groupBy('entry.year')->map(fn($g)=>$g->first(fn($item)=>$item['entry']->edition_id===($chosen['entry']->edition_id??null))??$g->first(fn($item)=>$item['record']!==null)??$g->first());
@endphp
<div class="atlas-mast">
@if($mapStateInfo && (array_sum($mapStateInfo['counts']??[])>0 || $mapStateInfo['slug']==='telangana'))
<a class="atlas-map-jump" href="#atlas-state-maps">{{ $tr('State constituency maps','राज्य के निर्वाचन क्षेत्र मानचित्र') }} <span aria-hidden="true">↓</span></a>
@endif
<header class="atlas-title"><p>{{ $state }}</p><h1>{{ \Illuminate\Support\Str::title($name) }} {{ $kind==='pc'?$tr('Lok Sabha (PC)','लोक सभा (PC)'):$tr('Assembly Constituency','विधान सभा निर्वाचन क्षेत्र') }}</h1></header>
<div class="atlas-controls">
<nav aria-label="{{ $tr('Constituency type','निर्वाचन क्षेत्र का प्रकार') }}">
@foreach(['pc'=>$tr('Lok Sabha (PC)','लोक सभा (PC)'),'ac'=>$tr('Assembly (AC)','विधान सभा (AC)')] as $type=>$text)
@php
    $known = collect($atlasFamily[$type]);
    $options = $known;
    if ($known->isEmpty() && $type!==$kind) {
        $options=$related->where('type',$type)->filter(fn($item)=>!empty($item->url))->map(fn($item)=>['name'=>$item->name,'archive'=>$item->name,'url'=>url($item->url).(str_contains($item->url,'?')?'&':'?').'lang='.app()->getLocale()])->values();
    }
    $canSwitch=$options->whereNotNull('url');
    $stateWide=$options->isEmpty();
@endphp
@if($type===$kind && ($type==='pc' || $canSwitch->count()<2))
<span aria-current="page">{{ $text }}</span>
@elseif($type==='pc' && $canSwitch->count()===1)
<a href="{{ $canSwitch->first()['url'] }}">{{ $text }}</a>
@else
@php
    $stateNames=$stateWide?\Illuminate\Support\Facades\Cache::remember('atlas-options-list-v2:'.$type.':'.$state,3600,fn()=>\Illuminate\Support\Facades\DB::table('historical_constituency_index')->where('kind',$type)->whereRaw(\App\Services\ElectionPlaceIdentity::stateSql().' = ?',[mb_strtolower($state)])->distinct()->orderBy('constituency_name')->pluck('constituency_name')->all()):[];
    $enabled=$stateWide?count($stateNames)>0:$canSwitch->isNotEmpty();
    $label=$stateWide?$text.' · '.$state:($type==='ac'?$tr('Assembly constituency in this PC','इस लोक सभा क्षेत्र में विधान सभा क्षेत्र'):$tr('Parliamentary constituency','लोक सभा निर्वाचन क्षेत्र'));
@endphp
<form method="get" class="atlas-seat-control" data-atlas-switch data-manual-submit action="{{ route('constituency.overview') }}">
<input type="hidden" name="state" value="{{ $state }}"><input type="hidden" name="kind" value="{{ $type }}"><input type="hidden" name="lang" value="{{ app()->getLocale() }}">
<select name="name" aria-label="{{ $label }}" required @disabled(!$enabled)>
<option value="">{{ $enabled?$text:$text.' · '.$tr('Unavailable','अनुपलब्ध') }}</option>
@if($stateWide)<optgroup label="{{ $state }}">@foreach($stateNames as $seatName)<option>{{ $seatName }}</option>@endforeach</optgroup>
@else
@foreach($options as $item)
<option value="{{ $item['archive'] }}" data-url="{{ $item['url'] }}" @selected($type===$kind && !empty($item['current'])) @disabled(empty($item['url']))>{{ $type===$kind?strtoupper($type).' · ':'' }}{{ $item['name'] }}</option>
@endforeach
@endif
</select>
@if($enabled)<noscript><button type="submit">{{ $tr('Show','देखें') }}</button></noscript>@endif
</form>
@endif
@endforeach
</nav>

<form method="get" action="{{ \App\Services\ConstituencyUrls::url(['kind'=>$kind,'state'=>$state,'name'=>$name]) }}" data-atlas-year data-manual-submit>
<input type="hidden" name="lang" value="{{ app()->getLocale() }}">
@if($exactSeatOnly)<input type="hidden" name="code" value="{{ $chosen['entry']->record_code }}">@endif
<label for="edition">{{ $tr('Election year','चुनाव वर्ष') }}</label><select name="edition" id="edition">
@foreach($alternatives as $row)<option value="{{ $row['entry']->edition_id }}" @selected(empty($selectedByElection) && $row['entry']->edition_id===($chosen['entry']->edition_id??null))>{{ $row['entry']->year }}</option>@endforeach
@foreach($byElections as $by)<option value="by-{{ $by['id'] }}" data-url="{{ \App\Services\ConstituencyUrls::url(['kind'=>$kind,'state'=>$state,'name'=>$name,'by_election'=>$by['id'],'lang'=>$hi?'hi':null]) }}#by-election-result" @selected(($selectedByElection['id']??null)===$by['id'])>{{ $by['year'] }} · {{ $tr('By-election','उपचुनाव') }}{{ $byElections->where('year',$by['year'])->count()>1?' · '.$by['period']:'' }}</option>@endforeach
</select><noscript><button type="submit">{{ $tr('Show results','नतीजे देखें') }}</button></noscript></form>
</div>

</div>
@php
    $by = $selectedByElection !== null;
    $featured = $by ? ($byFigures['declared'] ?? $byFigures['leading']) : null;
    $headline = $by ? ($featured['name'] ?? $tr('Recorded result','दर्ज परिणाम')) : ($result['winner'] ?? $tr('Winner unconfirmed','विजेता की पुष्टि नहीं हुई'));
    $party = $by ? ($featured['party'] ?? '') : ($result['party'] ?? '');
    $chartLeaders = $by ? $byFigures['leaders'] : $leaders;
    $denominator = $by ? $byFigures['total'] : ($comparable ? $polled : null);
    $stats = $by
        ? [[$tr('Listed candidates','सूचीबद्ध उम्मीदवार'),$byFigures['candidates']],[$tr('Votes in listed rows','सूचीबद्ध पंक्तियों के मत'),$byFigures['total']],[$tr('NOTA votes','नोटा मत'),$byFigures['nota_votes']]]
        : [[$tr('Registered electors','पंजीकृत मतदाता'),$metrics['electors']??$record['electors']??null],[$tr('Votes polled','पड़े मत'),$polled],[$tr('Turnout','मतदान प्रतिशत'),$metrics['turnout']??null]];
@endphp
<section class="atlas-result" @if($by) id="by-election-result" @endif aria-labelledby="atlas-winner">
<div><p class="atlas-eyebrow">{{ $by ? $selectedByElection['year'] : $chosen['entry']->year }} · {{ $by ? $tr('By-election','उपचुनाव') : $tr('Election result','चुनाव परिणाम') }}</p>
@if($by && $featured)<p class="atlas-caption">{{ $byFigures['declared'] ? $tr('Reported winner','दर्ज विजेता') : $tr('Highest recorded candidate vote count','सर्वाधिक दर्ज उम्मीदवार मत') }}</p>@endif
<h2 id="atlas-winner">@if($by && $featured)<span translate="no">{{ $headline }}</span>@else{{ $headline }}{{ ! $by && !empty($record['has_warning'])?' †':'' }}@endif</h2>
<p @if($by) translate="no" @endif>{{ $party }}</p>
@if($by)
@if(is_numeric($featured['votes']??null))<p class="atlas-margin"><strong>{{ $fmt->format($featured['votes']) }}</strong> {{ $tr('recorded votes','दर्ज मत') }}</p>@endif
@elseif($result['uncontested']??false)<p>{{ $tr('Returned unopposed','निर्विरोध निर्वाचित') }}</p>
@elseif(isset($result['margin']))<p class="atlas-margin"><strong>{{ $fmt->format($result['margin']) }}</strong> {{ $tr('vote winning margin','मतों से जीत') }}</p>@endif</div>
@if($chartLeaders->isNotEmpty() && ($by || empty($result['uncontested'])))
<div><h3>{{ $tr('Leading candidates','प्रमुख उम्मीदवार') }}</h3>
<p class="atlas-caption">{{ $denominator > 0 ? ($by ? $tr('Share of votes in listed rows','सूचीबद्ध मतों में हिस्सा') : $tr('Share of votes polled','पड़े मतों में हिस्सा')) : $tr('Recorded votes','दर्ज मत') }}</p>
@foreach($chartLeaders as $c)<div class="atlas-rank"><div><span @if($by) translate="no" @endif>{{ $by ? ($c['name']??'—') : ($c['display_name']??$c['candidate_name']) }} <small>· {{ $by ? ($c['party']??'—') : ($c['party_at_election']??'—') }}</small></span><b>{{ $denominator > 0 ? number_format(100*$c['votes']/$denominator,2).'%' : $fmt->format($c['votes']) }}</b></div>
@if($denominator > 0)<div class="atlas-track"><i style="width:{{ min(100,100*$c['votes']/$denominator) }}%;background:var(--chart-series-{{ $loop->iteration }})"></i></div>@endif</div>@endforeach
</div>@endif</section>
<dl class="atlas-stats">@foreach($stats as [$metric,$value])<div><dt>{{ $metric }}</dt><dd>{{ is_numeric($value)?(! $by && $loop->last?number_format($value,2).'%':$fmt->format($value)):'—' }}{{ ! $by && $loop->index===1 && !isset($metrics['polled']) && $polled!==null?' ‡':'' }}</dd></div>@endforeach</dl>
@if(! $by && !empty($record['has_warning']))<p class="atlas-caption">† {{ $record['display_data_note']??$record['error']??$tr('The source flags this result for review.','स्रोत में इस परिणाम पर टिप्पणी है।') }}</p>@endif
@if(! $by && !isset($metrics['polled']) && $polled!==null)<p class="atlas-caption">‡ {{ $tr('Source-reported votes polled. Comparable turnout is unavailable.','स्रोत में दर्ज मतदान। तुलनीय मतदान प्रतिशत उपलब्ध नहीं है।') }}</p>@endif
