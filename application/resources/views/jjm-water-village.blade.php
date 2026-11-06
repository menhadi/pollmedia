@php
$waterService = app(\App\Services\JjmWaterSnapshot::class);
$waterCodes = isset($currentRecords) ? collect($currentRecords)->pluck('code')->all() : (isset($village['lgd']) ? [(string)$village['lgd']] : []);
$waterCensus = isset($place) && $place ? (string)(json_decode($place->geography, true)['Town/Village'] ?? '') : null;
$waterRow = $waterService->forVillage($waterCodes, $waterCensus);
@endphp
<style>.jjm-water{background:var(--site-surface,#fff);border:1px solid var(--site-border,#d8e3df);border-radius:18px;padding:24px;margin:24px 0}.jjm-water h2{margin:0 0 20px}.jjm-water .stats{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px!important}.jjm-water .stat{padding:20px;background:var(--site-primary-soft,#edf5f1);border:1px solid var(--site-border,#d8e3df);border-radius:14px;min-width:0}.jjm-water .stat span{display:block;font-size:13px;line-height:1.5;color:var(--site-muted,#526866)}.jjm-water .stat strong{display:block;font-size:clamp(24px,3vw,34px);line-height:1.2;margin-top:12px;color:var(--site-primary,#176b5c);font-variant-numeric:tabular-nums}.jjm-source-notes{margin:16px 0}@media(max-width:600px){.jjm-water .stats{grid-template-columns:1fr}.jjm-water .stat{padding:16px}}</style>
<section class="card coverage jjm-water" id="village-water"><h2>Water · Jal Jeevan Mission</h2>
@if($waterRow)
<div class="stats"><div class="stat"><span>Households</span><strong>{{ number_format($waterRow['households']) }}</strong></div><div class="stat"><span>Homes with tap connections</span><strong>{{ number_format($waterRow['tap_connections']) }}</strong></div><div class="stat"><span>Tap water coverage</span><strong>{{ $waterRow['coverage_pct'] === null ? 'Unavailable' : number_format($waterRow['coverage_pct'], 2).'%' }}</strong></div></div>
@else
<p>Water figures are not available for this village yet.</p><p data-methodology-note>{{ count($waterCodes) ? 'No accepted stored JJM observation is linked to this profile.' : 'No unique official village identity is linked to this profile.' }} Missing values remain unavailable.</p>
@endif
@if(($waterRow['state'] ?? '') === 'Uttar Pradesh' && ($waterRow['district'] ?? '') === 'Pilibhit')
<p><a href="{{ url('/india/districts/uttar-pradesh/pilibhit') }}#water">Pilibhit district water dashboard →</a></p>
@endif
</section>
<script>document.addEventListener('DOMContentLoaded',()=>{const method=document.querySelector('#area-methodology,#local-methodology');if(method)for(const item of document.querySelectorAll('[data-methodology-note]'))method.append(item);const note=document.getElementById('water-disclaimer');const main=document.querySelector('main');if(note&&(method||main))(method||main).append(note);});</script>
<details class="jjm-source-notes" data-methodology-note><summary>Water · sources &amp; dates</summary>
@if($waterRow)
<p><a href="{{ $waterRow['source_url'] }}" target="_blank" rel="noopener">Official JJM village source ↗</a></p>
<p>JJM source retrieved: {{ $waterRow['retrieved_at'] ?? 'Date unavailable' }}. This observation is independent of the Census year.</p>
@endif
</details>
@include('jjm-water-disclaimer')

@include('rainfall-panel')

@php($groundwaterVillage = count(array_unique($waterCodes))===1 ? (string)$waterCodes[0] : null)
@include('water-evidence-panel',['waterVillage'=>$groundwaterVillage])
