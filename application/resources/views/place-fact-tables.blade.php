<link rel="stylesheet" href="{{ asset('css/place-facts.css') }}?v={{ substr(hash_file('sha256', public_path('css/place-facts.css')), 0, 12) }}">
<section class="place-facts" id="place-facts" aria-labelledby="place-facts-title" data-facts-scope="{{ $facts['scope'] ?? '' }}">
    <header class="place-facts-heading">
        <p class="place-facts-eyebrow">People · water · soil</p>
        <h2 id="place-facts-title">Facts about {{ $facts['name'] }}</h2>
        <p>Population, literacy, water and soil highlights from available records.</p>
    </header>
    @foreach($facts['sections'] as $section)
        @continue(!empty($onlyTopic) && ($onlyTopic==='soil' ? !str_contains(strtolower($section['title']),'soil') : ($onlyTopic==='water' ? !str_contains(strtolower($section['title']),'water') : preg_match('/soil|water/i',$section['title']))))
        @php
            $visibleRows = array_values(array_filter($section['rows'], fn ($row) => isset($row['value']) ? $row['value'] !== 'Unavailable' : !str_starts_with($row['answer'] ?? 'Unavailable', 'Unavailable')));
            $hasPlaces = collect($visibleRows)->contains(fn ($row) => !empty($row['place']));
        @endphp
        @if($visibleRows)
        <section class="place-facts-topic" id="facts-{{ \Illuminate\Support\Str::slug($section['title']) }}">
            <h3>{{ $section['title'] }}</h3>
            <div class="place-facts-scroll" tabindex="0" role="region" aria-label="{{ $section['title'] }} table">
                <table class="{{ $hasPlaces ? 'place-facts-comparison' : 'place-facts-values' }}">
                    <caption>{{ $section['title'] }} — {{ $facts['name'] }}</caption>
                    <thead><tr><th scope="col">Fact</th>@if($hasPlaces)<th scope="col">Place</th>@endif<th scope="col" class="place-facts-number">Value</th></tr></thead>
                    <tbody>
                    @foreach($visibleRows as $row)
                        <tr data-fact-period="{{ $row['period'] ?? '' }}" data-fact-source="{{ $row['source_url'] ?? '' }}"><th scope="row">{{ str_replace(' · source district', '', $row['label'] ?? $row['question']) }}</th>@if($hasPlaces)<td class="place-facts-place">{{ $row['place'] ?? $facts['name'] }}</td>@endif<td class="place-facts-number"><strong>{{ $row['value'] ?? $row['answer'] }}</strong></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        @endif
    @endforeach
</section>
<?php $factCanonical = url()->current().(request()->routeIs('villages.show', 'villages.named') && (string) request('year') === '2001' ? '?year=2001' : ''); ?>
<?php $factPage = ['@context' => 'https://schema.org', '@type' => 'WebPage', '@id' => $factCanonical.'#place-facts', 'url' => $factCanonical, 'name' => 'Facts about '.$facts['name'], 'about' => ['@type' => 'Place', 'name' => $facts['name']], 'description' => 'Population, literacy, water and soil highlights from available records.']; ?>
<script type="application/ld+json">{!! json_encode($factPage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
