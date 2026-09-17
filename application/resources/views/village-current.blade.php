<section class="card" id="current-administration">
<div class="kicker">Official LGD snapshot · {{ $lgd['checked_on'] }}</div><h2>Current village administration</h2>
@forelse($currentRecords as $record)
<h3>{{ $record['name'] }} · LGD {{ $record['code'] }}</h3>
<p>District: {{ $record['district_name'] }} · LGD {{ $record['district_code'] }}<br>Subdistrict: {{ $record['subdistrict_name'] }} · LGD {{ $record['subdistrict_code'] }}</p>
<div class="grid"><article><h3>Gram panchayat</h3>@forelse($record['panchayats'] as $panchayat)<p><a href="{{ route('villages.index', ['year'=>$year,'panchayat'=>$panchayat['code']]) }}">{{ $panchayat['name'] }} · LGD {{ $panchayat['code'] }} →</a></p>@empty<p>No gram panchayat mapping was present in this export.</p>@endforelse</article><article><h3>Development block</h3>@forelse($record['blocks'] as $currentBlock)<p><a href="{{ route('villages.index', ['year'=>$year,'current_block'=>$currentBlock['code']]) }}">{{ $currentBlock['name'] }} · LGD {{ $currentBlock['code'] }} →</a></p>@empty<p>No block mapping was present in this export.</p>@endforelse</article></div>
<p class="small">Matched using the export's Census {{ $year }} code {{ $village['code'] }}. LGD village status: {{ $record['status'] }}. Multiple records or mappings are shown when present; this does not establish unchanged Census boundaries.</p>
@empty
<p>No current LGD village record with this Census {{ $year }} code was found in the downloaded Pilibhit records.</p>
@endforelse
<p class="small">Downloaded {{ $lgd['checked_on'] }}. This snapshot is not a live feed. Available AC and PC connections appear in the electoral section below. Polling-part assignments remain pending.</p>
<div class="links"><a href="{{ $lgd['url'] }}" target="_blank" rel="noreferrer">Official LGD exports ↗</a><a href="https://panchayatiraj.up.nic.in/pblc_pg/Reports/PB2FormReport?District=PILIBHIT&amp;ReportType=Filled" target="_blank" rel="noreferrer">Official gram panchayat directory ↗</a><a href="https://pilibhit.nic.in/constituencies-2/" target="_blank" rel="noreferrer">Official constituency directory ↗</a></div>
</section>
