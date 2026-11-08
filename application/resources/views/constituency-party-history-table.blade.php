@php
    $ranked = isset($plot['series'][0]['name_key']);
    $hi = app()->getLocale() === 'hi';
    $label = fn($english,$hindi) => $hi ? $hindi : $english;
    $countFormat = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
@endphp
<h4>{{ $label('Chart values','ग्राफ़ के आँकड़े') }}</h4>
<div class="table-scroll atlas-table-scroll" role="region" aria-label="{{ $plot['title'] }}" tabindex="0">
<table class="atlas-party-history" @if($ranked) data-ranks @endif>
<thead><tr>
<th scope="col">{{ $label('Year','वर्ष') }}</th>
@if($ranked)<th scope="col">{{ $label('Rank','स्थान') }}</th>@endif
<th scope="col">{{ $label('Party','दल') }}</th>
<th scope="col">{{ $label('Votes','मत') }}</th>
<th scope="col">{{ $label('Share (%)','हिस्सा (%)') }}</th>
</tr></thead><tbody>
@foreach($plotRows as $point)
@foreach($plot['series'] as $series)
@php
    $share = $point[$series['key']];
    $votes = $point[$series['votes_key']];
    $party = isset($series['name_key']) ? $point[$series['name_key']] : $series['label'];
    $note = $point['review'] ? ' †' : '';
@endphp
<tr @class(['atlas-selected-year'=>(int)$point['year']===(int)($chosen['entry']->year??0),'atlas-year-start'=>$loop->first])>
<th scope="row">{{ $point['year'] }}</th>
@if($ranked)<td>{{ isset($series['name_key']) ? $loop->iteration : '—' }}</td>@endif
<td>{{ $party ?? '—' }}</td>
<td class="atlas-number" data-sort-value="{{ $votes }}">{{ $votes===null?'—':$countFormat->format($votes).$note }}</td>
<td class="atlas-number" data-sort-value="{{ $share }}">{{ $share===null?'—':number_format($share,2).$note }}</td>
</tr>
@endforeach
@endforeach
</tbody></table></div>
