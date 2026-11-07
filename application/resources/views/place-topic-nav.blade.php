@php
    $topicHi=app()->getLocale()==='hi' || request('lang')==='hi';
    $topics=$topicOptions??\App\Services\PlaceTopics::GROUPS[$topicGroup];
    $topicKey=$topicGroup==='member'?'vtopic':'topic';
    $activeTopic=\App\Services\PlaceTopics::selected($topicGroup);
    $params=request()->except(['pm_panel',$topicKey]);
    if($topicGroup==='member') { $params['topic']='village-data'; }
    $baseUrl=request()->routeIs('my-place.summary')?route('my-place.index'):request()->url();
    $targets=[];
    foreach($topics as $key) {
        $targets[$key]=[$baseUrl.'?'.http_build_query(array_merge($params,[$topicKey=>$key])),\App\Services\PlaceTopics::LABELS[$key][$topicHi?1:0]];
    }
@endphp
<nav class="pm-topics" data-place-topics="{{ $topicGroup }}" data-active-topic="{{ $activeTopic }}" aria-label="{{ $topicHi?'विषय':'Topics' }}">
    <div class="pm-topic-links">@foreach($targets as $key=>[$href,$label])
        <a href="{{ $href }}" data-topic="{{ $key }}" @if($activeTopic===$key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach</div>
    <label class="pm-topic-picker">{{ $topicHi?'विषय':'Topic' }} <select aria-label="{{ $topicHi?'विषय चुनें':'Choose a topic' }}">@foreach($targets as $key=>[$href,$label])
        <option value="{{ $href }}" @selected($activeTopic===$key)>{{ $label }}</option>
    @endforeach</select></label>
</nav>
