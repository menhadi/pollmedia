<section class="panel card coverage" id="rainfall" data-rainfall-panel data-district="{{ !empty($rainfallDistrict) ? '1' : '0' }}" data-census="{{ $waterCensus ?? '' }}" data-lgd="{{ implode(',', $waterCodes ?? []) }}" hidden>
<h2>Rainfall · India Meteorological Department</h2>
<p>Area estimate from the official 0.25° daily rainfall grid.</p>
<label for="rainfall-year">Rainfall year</label> <select id="rainfall-year"></select>
<p id="rainfall-total" aria-live="polite"></p>
<div class="table-wrap"><table><thead><tr><th>Month</th><th>Rainfall (mm)</th><th>Previous year (mm)</th></tr></thead><tbody id="rainfall-months"></tbody></table></div>
<p><a href="https://imdpune.gov.in/cmpg/Griddata/Rainfall_25_NetCDF.html" target="_blank" rel="noopener">Official IMD rainfall source ↗</a> · <a href="{{ url('/india/districts/uttar-pradesh/pilibhit') }}#rainfall">Pilibhit rainfall dashboard →</a></p>
<details id="rainfall-disclaimer"><summary>Rainfall sources, dates and methodology</summary><p id="rainfall-source"></p><p>Grid estimates are shared across areas and are not village rain-gauge observations. Values use area-weighted intersections with the existing polygons. The district estimate covers the available mapped area. Missing daily readings make that month's total unavailable. These two annual editions support year-to-year comparison, not a long-term climate normal. Original identifiers and polygons remain unchanged.</p><p>Pai et al. (2014), MAUSAM 65(1), pp. 1–18. IMD notes that its dataset may contain errors or omissions.</p></details>
</section>
<script src="{{ asset('js/rainfall-panel-20261017-navigation.js').'?v='.filemtime(public_path('js/rainfall-panel-20261017-navigation.js')) }}" defer></script>
