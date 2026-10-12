@php
    $electoralSource = json_decode(file_get_contents(database_path('fixtures/assam-2023-source-membership.json')), true, 512, JSON_THROW_ON_ERROR);
    $electoralHeadings = array_values(array_unique(array_column($electoralSource['acs'], 'source_district')));
    $candidateHeading = strtoupper(trim($district['source_name']));
    if ($district['slug'] === 'sribhumi') {
        $candidateHeading = 'KARIMGANJ';
    }
    if ($district['slug'] === 'sivasagar') {
        $candidateHeading = 'SIBSAGAR';
    }
    if ($district['slug'] === 'marigaon') {
        $candidateHeading = 'MORIGAON';
    }
    $initialHeading = in_array($candidateHeading, $electoralHeadings, true) ? $candidateHeading : 'all';
    $electoralAcs = collect($electoralSource['acs'])->keyBy('code');
    $districtAcs = $initialHeading === 'all' ? collect() : $electoralAcs->where('source_district', $initialHeading);
    $districtPcs = collect($electoralSource['pcs'])->filter(fn ($pc) => in_array($initialHeading, $pc['source_districts'], true));
@endphp
<section class="panel" id="assam-2023-electoral-context"><h2>Assam 2023 PC and AC details</h2>
<p>ECI final Order No.2, O.N.33(E), 11 August 2023. District labels and membership below follow that order.</p>
@if($initialHeading !== 'all')
<div data-district-electoral-summary="{{ $initialHeading }}"><h3>{{ $initialHeading }} · 2023 electoral district heading</h3>
<p>{{ $districtAcs->count() }} ACs under this heading, associated with {{ $districtPcs->count() }} unique PCs. Their full membership is expanded below, including ACs from other district headings.</p>
@if($district['slug'] === 'sivasagar')
<p><a href="https://sivasagar.assam.gov.in/about-district/district-glance">Sivasagar district administration’s profile</a> also uses Sibsagar for the district. The 2023 ECI heading SIBSAGAR is retained below; this name reference does not establish equal boundary extents.</p>
@endif
@if($district['slug'] === 'marigaon')
<p>The <a href="https://fcsca.assam.gov.in/about-us/detail/district-officials-of-fpdca-department">Assam government district directory</a> lists MARIGAON and links it to the Morigaon district administration. The ECI 2023 MORIGAON heading is retained here; this identifies a name reference, not equal boundary extents.</p>
@endif
<p class="notice">Shown by matching source name{{ $district['slug'] === 'sribhumi' ? ' through the verified Karimganj–Sribhumi rename' : ($district['slug'] === 'sivasagar' ? ' using the official Sivasagar/Sibsagar name reference' : ($district['slug'] === 'marigaon' ? ' using the official Marigaon/Morigaon directory reference' : '')) }}. This is dated electoral context; it does not certify that these constituencies cover the village shapes or newer district outline.</p></div>
@else
<p class="notice">No exact 2023 district heading is matched to this map’s source name. Choose the dated heading below; no constituency membership has been inferred for this map.</p>
@endif
<p>Territorial references are dated 1 January 2023; Guwahati Municipal Corporation ward extents use Census 2001. Effective-date and subsequent corrigendum checks remain pending. Older election figures and maps are not assigned to these 2023 constituencies.</p>
<label>District heading in the 2023 order<select id="assam-electoral-district"><option value="all" @selected($initialHeading === 'all')>All 2023 district headings</option>
@foreach($electoralHeadings as $heading)<option value="{{ $heading }}" @selected($initialHeading === $heading)>{{ $heading }}</option>@endforeach
</select></label>
<p id="assam-electoral-filter-note" role="status">{{ $initialHeading === 'all' ? 'Choose a dated district heading to view associated PCs.' : 'Associated PCs are expanded below. Bold ACs belong to the selected 2023 district heading.' }} All ACs in each associated PC are retained.</p>
@foreach($electoralSource['pcs'] as $pc)
@php($matchesHeading = $initialHeading === 'all' || in_array($initialHeading, $pc['source_districts'], true))
<details data-assam-pc="{{ $pc['code'] }}" @if(!$matchesHeading) hidden @endif @if($matchesHeading && $initialHeading !== 'all') open @endif><summary>{{ $pc['code'] }}. {{ $pc['source_name'] }} · {{ count($pc['ac_codes']) }} ACs</summary><p>District headings in this order: {{ implode(', ', $pc['source_districts']) }}</p><ul>
@foreach($pc['ac_codes'] as $acCode)
@php($ac = $electoralAcs[$acCode])
<li data-assam-ac="{{ $ac['code'] }}" data-electoral-district="{{ $ac['source_district'] }}" style="font-weight: {{ $initialHeading === $ac['source_district'] ? '700' : '400' }}">{{ $ac['code'] }}. {{ $ac['source_name'] }} — {{ $ac['source_district'] }}</li>
@endforeach
</ul></details>
@endforeach
<p><a href="https://www.eci.gov.in/Documents/Delimitation/DELIMITATIONASSAM_UPDATED.pdf">Official ECI book: Table-A and Table-B, printed pages 230–258</a></p></section>
<script type="module" src="{{ asset('js/assam-electoral-district-filter.js').'?v=district-context-1' }}"></script>
