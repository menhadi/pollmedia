<header class="hub-hero">
<div class="hub-intro"><h1>{{ $title }}</h1>
<div class="hub-lead-number">{{ number_format($total) }}</div>
<p>{{ $tr('Constituency records across Lok Sabha, Assembly and by-elections.','लोक सभा, विधान सभा और उपचुनावों के निर्वाचन क्षेत्र रिकॉर्ड।') }}</p>
<div class="hub-actions"><a href="#national-records">{{ $tr('Find a constituency','निर्वाचन क्षेत्र खोजें') }} ↓</a><a href="#election-states">{{ $tr('State election results','राज्य चुनाव परिणाम') }} ↓</a></div></div>
<section class="hub-chart"><h2>{{ $tr('Records by election type','चुनाव प्रकार के अनुसार रिकॉर्ड') }}</h2>
<ul class="hub-bars">@foreach($counts as $key=>$count)<li><a href="{{ route('elections.all-india',['type'=>$key]+$lang) }}#national-records"><span>{{ $labels[$key] }}</span><strong>{{ number_format($count) }}</strong></a><div><i style="width:{{ 100*$count/max(1,max($counts)) }}%"></i></div></li>@endforeach</ul>
</section>
</header>
<div class="hub-topics">
<section><h2>{{ $tr('Lok Sabha','लोक सभा') }}</h2><p>{{ $tr('Explore parliamentary constituencies, general election results and report editions.','संसदीय निर्वाचन क्षेत्र, आम चुनाव परिणाम और रिपोर्ट संस्करण देखें।') }}</p><a href="{{ route('elections.history',$lang) }}">{{ $tr('Lok Sabha reports','लोक सभा रिपोर्ट') }} →</a></section>
<section><h2>{{ $tr('Assembly elections','विधान सभा चुनाव') }}</h2><p>{{ $tr('Compare state election reports and open individual Assembly seat results.','राज्य चुनाव रिपोर्टों की तुलना करें और विधान सभा सीटों के परिणाम देखें।') }}</p><a href="{{ route('elections.assembly',$lang) }}">{{ $tr('Assembly reports','विधान सभा रिपोर्ट') }} →</a></section>
<section><h2>{{ $tr('By-elections','उपचुनाव') }}</h2><p>{{ $tr('Look up individual contests by year and see the candidates recorded in each source.','वर्ष के अनुसार चुनाव और स्रोत में दर्ज उम्मीदवार देखें।') }}</p><a href="{{ route('elections.by-election-results',$lang) }}">{{ $tr('By-election reports','उपचुनाव रिपोर्ट') }} →</a></section>
</div>
<details id="election-states" class="hub-panel"><summary>{{ $tr('Results by state or UT','राज्य या केंद्र शासित प्रदेश के परिणाम') }}</summary>
<ul class="hub-places">@foreach($stateDirectory as $area)<li><a href="{{ route('states.show',['state'=>$area['slug']]+$lang) }}">{{ $area['name'] }} <span aria-hidden="true">→</span></a></li>@endforeach</ul></details>
