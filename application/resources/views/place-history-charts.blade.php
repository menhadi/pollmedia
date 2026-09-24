@php
$chartRows=$rows->sortBy('entry.year')->values()->map(function($row){
 $summary=$row['record']?app(\App\Services\HistoricalElectionAnalytics::class)->summarize([$row['record']]):[];
 return ['year'=>$row['entry']->year,'label'=>$row['entry']->edition_label,'turnout'=>$summary['turnout']??null,'margin'=>$summary['margin']??null];
});
@endphp
<section class="panel history-charts" aria-label="Historical election charts"><div class="panel-heading"><div><p class="kicker">Across the years</p><h2>How voting has changed</h2></div><a href="#history">View the tables ↓</a></div>
@foreach(['turnout'=>['Voter turnout','Turnout (%)'],'margin'=>['Winning margin','Margin (votes)']] as $metric=>$labels)
@php $values=$chartRows->pluck($metric)->filter(fn($v)=>$v!==null); $maximum=max(1,$values->max()??1); $minimum=$values->min()??0; $plotWidth=max(560,$chartRows->count()*64); $step=($plotWidth-100)/max(1,$chartRows->count()); @endphp
<div class="history-chart"><h3>{{ $labels[0] }}</h3><div class="chart-scroll" tabindex="0" role="region" aria-label="{{ $labels[0] }} by election year"><svg viewBox="0 0 {{ $plotWidth }} 300" role="img" aria-label="{{ $labels[0] }}. Years on the horizontal axis; {{ $labels[1] }} on the vertical axis." style="min-width:{{ $plotWidth }}px"><title>{{ $labels[0] }} by election year</title>
@for($tick=0;$tick<=4;$tick++)@php $y=240-$tick*48; @endphp<line x1="70" y1="{{ $y }}" x2="{{ $plotWidth-20 }}" y2="{{ $y }}" stroke="#d8e3df"/><text x="60" y="{{ $y+4 }}" text-anchor="end">{{ number_format($maximum*$tick/4,$metric==='turnout'?1:0) }}</text>@endfor
<text x="12" y="130" transform="rotate(-90 12 130)" text-anchor="middle">{{ $labels[1] }}</text>
@foreach($chartRows as $point)@php $value=$point[$metric]; $x=70+$loop->index*$step; $height=$value===null?0:192*$value/$maximum; $ratio=$maximum===$minimum?0.5:($value-$minimum)/($maximum-$minimum); $color=$ratio<0.33?'#936000':($ratio<0.66?'#487000':'#00665d'); @endphp
@if($value!==null)<rect x="{{ $x+8 }}" y="{{ 240-$height }}" width="{{ max(12,$step-16) }}" height="{{ $height }}" rx="4" fill="{{ $color }}"><title>{{ $point['label'] }}: {{ number_format($value,$metric==='turnout'?2:0) }}{{ $metric==='turnout'?'%':' votes' }}</title></rect>@else<text x="{{ $x+$step/2 }}" y="230" text-anchor="middle">—</text>@endif
<text x="{{ $x+$step/2 }}" y="260" text-anchor="middle">{{ $point['year'] }}</text>@endforeach<text x="{{ $plotWidth/2 }}" y="290" text-anchor="middle">Election year</text></svg></div><p class="small">Gold → green → teal: lower to higher values in this chart. A dash means unavailable or under review; it is not zero. Each bar represents an available election report.</p></div>@endforeach</section>
