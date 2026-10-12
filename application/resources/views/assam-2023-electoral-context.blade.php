@php
    $electoralSource = json_decode(file_get_contents(database_path('fixtures/assam-2023-source-membership.json')), true, 512, JSON_THROW_ON_ERROR);
    $datedElectionLinks = json_decode(file_get_contents(database_path('fixtures/assam-dated-election-links.json')), true, 512, JSON_THROW_ON_ERROR);
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
    if ($district['slug'] === 'kamrup-metro') {
        $candidateHeading = 'KAMRUP (METROPOLITIAN)';
    }
    if ($district['slug'] === 'south-salmara-mancachar') {
        $candidateHeading = 'SOUTH SALMARA';
    }
    $initialHeading = in_array($candidateHeading, $electoralHeadings, true) ? $candidateHeading : 'all';
    $electoralAcs = collect($electoralSource['acs'])->keyBy('code');
    $districtAcs = $initialHeading === 'all' ? collect() : $electoralAcs->where('source_district', $initialHeading);
    $districtPcs = collect($electoralSource['pcs'])->filter(fn ($pc) => in_array($initialHeading, $pc['source_districts'], true));
@endphp
<section class="panel" id="assam-2023-electoral-context"><h2>Assam 2023 PC and AC details</h2>
<p>ECI final Order No.2, O.N.33(E), 11 August 2023. District labels and membership below follow that order.</p>
<div class="source-grid" aria-label="Assam election archives">
<article><h3>Parliamentary election records</h3><p><a href="{{ route('elections.constituencies', ['state' => 'Assam', 'kind' => 'pc', 'year' => 2024]) }}">Browse Assam PC records · 2024</a></p><p><a href="{{ route('elections.constituencies', ['state' => 'Assam', 'kind' => 'pc']) }}">All indexed Assam parliamentary editions</a></p></article>
<article><h3>Assembly election records</h3><p><a href="{{ route('elections.constituencies', ['state' => 'Assam', 'kind' => 'ac', 'year' => 2026]) }}">Browse Assam AC records · 2026</a></p><p><a href="{{ route('elections.constituencies', ['state' => 'Assam', 'kind' => 'ac']) }}">All indexed Assam assembly editions</a></p></article>
</div>
<p>These links open the statewide archive with its election year and source edition. Coverage varies by edition. Earlier records retain their original constituencies; matching names or numbers do not establish continuity with the 2023 membership below.</p>
@if($initialHeading !== 'all')
<div data-district-electoral-summary="{{ $initialHeading }}"><h3>{{ $initialHeading }} · 2023 electoral district heading</h3>
<p>{{ $districtAcs->count() }} ACs under this heading, associated with {{ $districtPcs->count() }} unique PCs. Their full membership is expanded below, including ACs from other district headings.</p>
@if($district['slug'] === 'sivasagar')
<p><a href="https://sivasagar.assam.gov.in/about-district/district-glance">Sivasagar district administration’s profile</a> also uses Sibsagar for the district. The 2023 ECI heading SIBSAGAR is retained below; this name reference does not establish equal boundary extents.</p>
@endif
@if($district['slug'] === 'marigaon')
<p>The <a href="https://fcsca.assam.gov.in/about-us/detail/district-officials-of-fpdca-department">Assam government district directory</a> lists MARIGAON and links it to the Morigaon district administration. The ECI 2023 MORIGAON heading is retained here; this identifies a name reference, not equal boundary extents.</p>
@endif
@if($district['slug'] === 'kamrup-metro')
<p>The official district profile uses Kamrup Metropolitan and Kamrup (Metro). The original ECI spelling KAMRUP (METROPOLITIAN) is retained below. <a href="https://kamrupmetro.assam.gov.in/about-us/about-district">Official district profile</a>. This provides dated context, not matching map extents.</p>
@endif
@if($district['slug'] === 'south-salmara-mancachar')
<p>The district profile lists AC11 Mankachar; the 2023 ECI order places that AC under SOUTH SALMARA and in PC2 Dhubri. The profile page shows an update date of 24 June 2024. <a href="https://southsalmaramankachar.assam.gov.in/about-us/district-profile">Official district profile</a>. This provides dated context, not matching map extents.</p>
@endif
<p class="notice">The default selection uses an exact source name or the documented official reference above. This is dated electoral context; it does not certify that these constituencies cover the village shapes or newer district outline.</p></div>
@else
<p class="notice">No exact 2023 district heading is matched to this map’s source name. Choose the dated heading below; no constituency membership has been inferred for this map.</p>
@endif
<p>Territorial references are dated 1 January 2023; Guwahati Municipal Corporation ward extents use Census 2001. Effective-date and subsequent corrigendum checks remain pending. Older election figures and maps are not assigned to these 2023 constituencies.</p>
<p>Individual result links below use exact names or documented reservation-label and spelling references to the indexed 2024 parliamentary and 2026 assembly reports. Report codes are kept separately from the 2023 order numbers. All 14 PC and 126 AC report references are available; these links do not certify matching boundary editions.</p>
<p>PC reservation labels are verified against the <a href="https://ceochhattisgarh.nic.in/assets/pdf%20files/Press%20Note%20of%20ECI2024.pdf" target="_blank" rel="noopener">official 2024 election schedule</a> (PDF page 71): Kokrajhar (ST), Diphu (ST), Silchar (SC).</p>
<label>District heading in the 2023 order<select id="assam-electoral-district"><option value="all" @selected($initialHeading === 'all')>All 2023 district headings</option>
@foreach($electoralHeadings as $heading)<option value="{{ $heading }}" @selected($initialHeading === $heading)>{{ $heading }}</option>@endforeach
</select></label>
<p id="assam-electoral-filter-note" role="status">{{ $initialHeading === 'all' ? 'Choose a dated district heading to view associated PCs.' : 'Associated PCs are expanded below. Bold ACs belong to the selected 2023 district heading.' }} All ACs in each associated PC are retained.</p>
@foreach($electoralSource['pcs'] as $pc)
@php($matchesHeading = $initialHeading === 'all' || in_array($initialHeading, $pc['source_districts'], true))
<details data-assam-pc="{{ $pc['code'] }}" @if(!$matchesHeading) hidden @endif @if($matchesHeading && $initialHeading !== 'all') open @endif><summary>{{ $pc['code'] }}. {{ $pc['source_name'] }} · {{ count($pc['ac_codes']) }} ACs</summary><p>District headings in this order: {{ implode(', ', $pc['source_districts']) }}</p><ul>
@php($pcResult = $datedElectionLinks['pc'][$pc['code']] ?? null)
@if($pcResult)
<li><a data-assam-result="pc-{{ $pc['code'] }}" href="{{ route('elections.history', ['edition' => $pcResult['edition'], 'state' => 'Assam', 'code' => $pcResult['record_code']]) }}">{{ $pcResult['record_name'] }} · {{ $pcResult['year'] }} parliamentary results</a></li>
@else
<li>Parliamentary result identity unresolved; use the dated archive above.</li>
@endif
@foreach($pc['ac_codes'] as $acCode)
@php($ac = $electoralAcs[$acCode])
<li data-assam-ac="{{ $ac['code'] }}" data-electoral-district="{{ $ac['source_district'] }}" style="font-weight: {{ $initialHeading === $ac['source_district'] ? '700' : '400' }}">{{ $ac['code'] }}. {{ $ac['source_name'] }} — {{ $ac['source_district'] }}
@php($acResult = $datedElectionLinks['ac'][$ac['code']] ?? null)
@if($acResult)
 · <a data-assam-result="ac-{{ $ac['code'] }}" href="{{ route('elections.assembly', ['edition' => $acResult['edition'], 'state' => 'Assam', 'code' => $acResult['record_code']]) }}">{{ $acResult['record_name'] }} · {{ $acResult['year'] }} assembly results</a>
@if(isset($acResult['identity_evidence']))
<p>Spelling reference: <a href="{{ $acResult['identity_evidence']['url'] }}" target="_blank" rel="noopener">ECI 2026 result page for AC {{ $ac['code'] }}</a> (updated 5 May 2026). The original 2023 label above is retained; this dated report reference does not establish equal boundary extents.</p>
@endif
@else
 · Result identity unresolved; use the dated archive above.
@endif
</li>
@endforeach
</ul></details>
@endforeach
<p><a href="https://www.eci.gov.in/Documents/Delimitation/DELIMITATIONASSAM_UPDATED.pdf">Official ECI book: Table-A and Table-B, printed pages 230–258</a></p></section>
<script type="module" src="{{ asset('js/assam-electoral-district-filter.js').'?v=district-context-1' }}"></script>
