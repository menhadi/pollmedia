@php
    $electoral = json_decode(file_get_contents(database_path('fixtures/ladakh-electoral-source-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $archive = $electoral['archive'];
    $pcUrl = route('constituency.overview', ['state' => 'Ladakh', 'kind' => 'pc', 'name' => $archive['constituency_name'], 'edition' => $archive['edition_id'], 'code' => $archive['record_code']]);
@endphp
<section class="panel" id="ladakh-electoral-context"><h2>Parliamentary constituency and election history</h2>
<p><a data-pc-dashboard="1" href="{{ $pcUrl }}">1 · Ladakh parliamentary constituency dashboard</a> · 2024 archive edition</p>
<p>Ladakh has one parliamentary constituency shared across its districts. This single link represents the shared constituency, not separate district seats. The dated district outlines above are not a verified electoral boundary layer.</p>
<h3>Assembly constituency context</h3><p>Ladakh is a Union Territory without a legislature, as recorded by the Ministry of Home Affairs. There are no current Ladakh Legislative Assembly constituency dashboards to list. Former Jammu and Kashmir Assembly constituencies belong to an older institutional and geographic edition; hill-council seats are a different institution.</p>
<p><a href="https://ceo.ladakh.gov.in/ro/">Official CEO · 1st Ladakh PC</a> · <a href="https://www.mha.gov.in/en/divisionofmha/jammu-kashmir-and-ladakh-affairs">MHA · Ladakh institutional context</a></p></section>
