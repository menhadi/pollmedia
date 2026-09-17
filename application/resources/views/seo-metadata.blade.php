@php
    $override = app(\App\Services\SeoPages::class)->current($canonical);
    $seoTitle = $override?->title ?? $seoTitle;
    $seoDescription = $override?->description ?? $seoDescription;
@endphp
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
<link rel="canonical" href="{{ $canonical }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:url" content="{{ $canonical }}">
@php
    $items = [];
    foreach ($breadcrumbs as $label => $link) {
        $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $label, 'item' => $link];
    }
    $structured = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
@endphp
<script type="application/ld+json">{!! json_encode($structured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
