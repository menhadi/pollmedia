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
<div class="atlas-controls">
<nav aria-label="{{ $tr('Constituency type','निर्वाचन क्षेत्र का प्रकार') }}">
@foreach(['pc'=>$tr('Lok Sabha (PC)','लोक सभा (PC)'),'ac'=>$tr('Assembly (AC)','विधान सभा (AC)')] as $type=>$text)
@php
    $known = collect($atlasFamily[$type]);
    $options = $known->whereNotNull('url')->values();
    if ($known->isEmpty() && $type!==$kind) {
        $options=$related->where('type',$type)->filter(fn($item)=>!empty($item->url))->map(fn($item)=>['name'=>$item->name,'archive'=>$item->name,'url'=>url($item->url).(str_contains($item->url,'?')?'&':'?').'lang='.app()->getLocale()])->values();
    }
@endphp
@if($type===$kind)
<span aria-current="page">{{ $text }}</span>
@elseif($type==='pc' && $options->count()===1)
<a href="{{ $options->first()['url'] }}">{{ $text }}</a>
@else
<details class="atlas-other" data-atlas-picker><summary>{{ $text }}</summary>
<form method="get" data-atlas-switch data-manual-submit action="{{ route('constituency.overview') }}">
<input type="hidden" name="state" value="{{ $state }}"><input type="hidden" name="kind" value="{{ $type }}"><input type="hidden" name="lang" value="{{ app()->getLocale() }}">
@if($known->isNotEmpty() || $options->isNotEmpty())
<label>{{ $type==='ac'?$tr('Assembly constituency in this PC','इस लोक सभा क्षेत्र में विधान सभा क्षेत्र'):$tr('Parliamentary constituency','लोक सभा निर्वाचन क्षेत्र') }}
<select name="name" required><option value="">{{ $tr('Choose a seat','क्षेत्र चुनें') }}</option>
@foreach(($known->isEmpty()?$options:$known) as $item)<option value="{{ $item['archive'] }}" data-url="{{ $item['url'] }}" @disabled(empty($item['url']))>{{ $item['name'] }}</option>@endforeach</select></label>
@else
@php($availableNames=\Illuminate\Support\Facades\Cache::remember('atlas-options-list-v2:'.$type.':'.$state,3600,fn()=>\Illuminate\Support\Facades\DB::table('historical_constituency_index')->where('kind',$type)->whereRaw(\App\Services\ElectionPlaceIdentity::stateSql().' = ?',[mb_strtolower($state)])->distinct()->orderBy('constituency_name')->pluck('constituency_name')->all()))
@if($availableNames)<label>{{ $text }} · {{ $state }}<select name="name" required><option value="">{{ $tr('Browse state seats','राज्य के क्षेत्र देखें') }}</option>@foreach($availableNames as $seatName)<option>{{ $seatName }}</option>@endforeach</select></label>@else<p>{{ $tr('No archived seats in this chamber.','इस सदन के क्षेत्र उपलब्ध नहीं हैं।') }}</p>@endif
@endif
@if($known->isNotEmpty() || $options->isNotEmpty() || !empty($availableNames))<button type="submit">{{ $tr('Show','देखें') }}</button>@endif</form></details>
@endif
@endforeach
</nav>
@if($kind==='ac' && count($atlasFamily['ac'])>1)
<form method="get" data-atlas-switch data-manual-submit action="{{ route('constituency.overview') }}">
<input type="hidden" name="state" value="{{ $state }}"><input type="hidden" name="kind" value="ac"><input type="hidden" name="lang" value="{{ app()->getLocale() }}">
<label>{{ $tr('Assembly seat','विधान सभा क्षेत्र') }} <select name="name" required>@foreach($atlasFamily['ac'] as $item)<option data-url="{{ $item['url'] }}" value="{{ $item['archive'] }}" @selected(!empty($item['current'])) @disabled(empty($item['url']))>{{ $item['name'] }}</option>@endforeach</select></label><button type="submit">{{ $tr('Show','देखें') }}</button></form>@endif

<form method="get" action="{{ \App\Services\ConstituencyUrls::url(['kind'=>$kind,'state'=>$state,'name'=>$name]) }}" data-atlas-year data-manual-submit>
<input type="hidden" name="lang" value="{{ app()->getLocale() }}">
@if($exactSeatOnly)<input type="hidden" name="code" value="{{ $chosen['entry']->record_code }}">@endif
<label for="edition">{{ $tr('Election year','चुनाव वर्ष') }}</label><select name="edition" id="edition">
@foreach($alternatives as $row)<option value="{{ $row['entry']->edition_id }}" @selected($row['entry']->edition_id===($chosen['entry']->edition_id??null))>{{ $row['entry']->year }}</option>@endforeach
</select><button type="submit">{{ $tr('Show results','नतीजे देखें') }}</button></form>
</div>
<header class="atlas-title"><p>{{ $state }} · {{ $kind==='pc'?$tr('Lok Sabha','लोक सभा'):$tr('Vidhan Sabha','विधान सभा') }}</p><h1>{{ \Illuminate\Support\Str::title($name) }} {{ $kind==='pc'?$tr('Parliamentary Constituency','लोक सभा निर्वाचन क्षेत्र'):$tr('Assembly Constituency','विधान सभा निर्वाचन क्षेत्र') }}</h1></header>
<section class="atlas-result" aria-labelledby="atlas-winner"><div><p class="atlas-eyebrow">{{ $chosen['entry']->year }} · {{ $tr('Election result','चुनाव परिणाम') }}</p><h2 id="atlas-winner">{{ $result['winner']??$tr('Winner unconfirmed','विजेता की पुष्टि नहीं हुई') }}{{ !empty($record['has_warning'])?' †':'' }}</h2><p>{{ $result['party']??'' }}</p>
@if($result['uncontested']??false)<p>{{ $tr('Returned unopposed','निर्विरोध निर्वाचित') }}</p>
@elseif(isset($result['margin']))<p class="atlas-margin"><strong>{{ $fmt->format($result['margin']) }}</strong> {{ $tr('vote winning margin','मतों से जीत') }}</p>@endif</div>
@if($leaders->isNotEmpty() && empty($result['uncontested']))<div><h3>{{ $tr('Leading candidates','प्रमुख उम्मीदवार') }}</h3><p class="atlas-caption">{{ $comparable?$tr('Share of votes polled','पड़े मतों में हिस्सा'):$tr('Recorded votes','दर्ज मत') }}</p>
@foreach($leaders as $c)<div class="atlas-rank"><div><span>{{ $c['display_name']??$c['candidate_name'] }} <small>· {{ $c['party_at_election']??'—' }}</small></span><b>{{ $comparable?number_format(100*$c['votes']/$metrics['polled'],2).'%':$fmt->format($c['votes']) }}</b></div>
@if($comparable)<div class="atlas-track"><i style="width:{{ min(100,100*$c['votes']/$metrics['polled']) }}%;background:var(--chart-series-{{ $loop->iteration }})"></i></div>@endif</div>@endforeach
</div>@endif</section>
<dl class="atlas-stats">@foreach([[$tr('Registered electors','पंजीकृत मतदाता'),$metrics['electors']??$record['electors']??null],[$tr('Votes polled','पड़े मत'),$polled],[$tr('Turnout','मतदान प्रतिशत'),$metrics['turnout']??null]] as [$metric,$value])<div><dt>{{ $metric }}</dt><dd>{{ is_numeric($value)?($loop->last?number_format($value,2).'%':$fmt->format($value)):'—' }}{{ $loop->index===1 && !isset($metrics['polled']) && $polled!==null?' ‡':'' }}</dd></div>@endforeach</dl>
@if(!empty($record['has_warning']))<p class="atlas-caption">† {{ $record['display_data_note']??$record['error']??$tr('The source flags this result for review.','स्रोत में इस परिणाम पर टिप्पणी है।') }}</p>@endif
@if(!isset($metrics['polled']) && $polled!==null)<p class="atlas-caption">‡ {{ $tr('Source-reported votes polled. Comparable turnout is unavailable.','स्रोत में दर्ज मतदान। तुलनीय मतदान प्रतिशत उपलब्ध नहीं है।') }}</p>@endif
