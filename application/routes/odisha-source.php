<?php

use Illuminate\Support\Facades\Route;

foreach (['odisha' => ['odisha-source-district-outlines.json', 'odisha-source-district-map'], 'odisha-villages' => ['odisha-source-villages.json', 'odisha-source-village-map']] as $prefix => [$fixture, $view]) {
    Route::get('/india/districts/source/'.$prefix.'/{slug}/dashboard', function (string $slug) use ($fixture, $view) {
        $manifest = json_decode(file_get_contents(database_path('fixtures/'.$fixture)), true, 512, JSON_THROW_ON_ERROR);
        $district = collect($manifest['districts'])->firstWhere('slug', $slug);
        abort_unless($district, 404);

        return view($view, ['district' => $district, 'districts' => $manifest['districts']]);
    })->where('slug', '[a-z-]+')->name($view);
}
