<?php

use Illuminate\Support\Facades\Route;

foreach (['district' => ['source-district-outlines', 'ut-source-district-map'], 'villages' => ['source-villages', 'ut-source-village-map']] as $kind => [$fixture, $view]) {
    Route::get('/india/districts/ut-source/{region}/'.$kind.'/{slug}/dashboard', function (string $region, string $slug) use ($fixture, $view) {
        $manifest = json_decode(file_get_contents(database_path('fixtures/'.$region.'-'.$fixture.'.json')), true, 512, JSON_THROW_ON_ERROR);
        $district = collect($manifest['districts'])->firstWhere('slug', $slug);
        abort_unless($district, 404);

        return view($view, ['district' => $district, 'districts' => $manifest['districts'], 'manifest' => $manifest, 'region' => $region, 'regionLabel' => $manifest['label']]);
    })->where('region', 'chandigarh|dnh-daman-diu')->where('slug', '[a-z-]+')->name($view);
}

foreach (['chandigarh', 'dnh-daman-diu'] as $legacyRegion) {
    foreach (['district' => 'ut-source-district-map', 'villages' => 'ut-source-village-map'] as $kind => $routeName) {
        Route::get('/india/districts/source/'.$legacyRegion.'/'.$kind.'/{slug}/dashboard', function (string $slug) use ($legacyRegion, $routeName) {
            return redirect()->route($routeName, ['region' => $legacyRegion, 'slug' => $slug]);
        })->where('slug', '[a-z-]+');
    }
}
