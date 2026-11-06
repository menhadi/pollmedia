<?php

use App\Http\Controllers\IndiaVillageMapController;
use Illuminate\Support\Facades\Route;

Route::get('/india/map', [IndiaVillageMapController::class, 'index'])->name('india-map.index');
Route::get('/api/india-map/catalogue', [IndiaVillageMapController::class, 'catalogue'])->name('india-map.catalogue');
Route::get('/api/india-map/places/{id}', [IndiaVillageMapController::class, 'detail'])->where('id', '[a-f0-9]{32}')->middleware('throttle:120,1')->name('india-map.detail');
Route::get('/api/india-map/search', [IndiaVillageMapController::class, 'search'])->middleware('throttle:120,1')->name('india-map.search');
Route::get('/api/india-map/layers/{layer}', [IndiaVillageMapController::class, 'geometry'])->where('layer', '[a-f0-9]{20}')->name('india-map.geometry');
Route::get('/api/india-map/layers/{layer}/features/{index}', [IndiaVillageMapController::class, 'feature'])->where('layer', '[a-f0-9]{20}')->whereNumber('index')->middleware('throttle:120,1')->name('india-map.feature');

Route::get('/api/india-map/metrics/{layer}', [IndiaVillageMapController::class, 'metrics'])->where('layer', '(?:[a-f0-9]{20}|[a-f0-9]{64})')->middleware('throttle:120,1')->name('india-map.metrics');

Route::get('/india/overview', [\App\Http\Controllers\OverviewController::class, 'index'])->name('india.overview');
Route::get('/api/india-map/hierarchy', [IndiaVillageMapController::class, 'hierarchy'])->middleware('throttle:120,1')->name('india-map.hierarchy');
Route::get('/api/india-map/villages/{code}', [IndiaVillageMapController::class, 'village'])->whereNumber('code')->middleware('throttle:120,1')->name('india-map.village');

Route::get('/api/india-map/area', [IndiaVillageMapController::class, 'area'])->middleware('throttle:120,1')->name('india-map.area');
Route::get('/api/india-map/history', [IndiaVillageMapController::class, 'history'])->middleware('throttle:120,1')->name('india-map.history');

Route::get('/api/water/groundwater', [\App\Http\Controllers\GroundwaterController::class, 'records'])->middleware('throttle:120,1')->name('water.groundwater-records');
Route::get('/india/water/groundwater', [\App\Http\Controllers\GroundwaterController::class, 'show'])->name('water.groundwater');

Route::get('/india/water/pilibhit/groundwater', function () {
    $path = config('groundwater.pilibhit_path', storage_path('app/groundwater/pilibhit.json'));
    abort_unless(is_file($path) && is_file(preg_replace('/\.json$/', '.sha256', $path)), 503, 'Groundwater pilot is unavailable.');
    $expected = trim(file_get_contents(preg_replace('/\.json$/', '.sha256', $path)));
    abort_unless(hash_equals($expected, hash_file('sha256', $path)), 503, 'Groundwater evidence could not be verified.');
    $pilot = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    abort_unless(($pilot['district_lgd_code'] ?? null) === '173' && ($pilot['state_lgd_code'] ?? null) === '9', 503);

    return response()->view('pilibhit-groundwater', ['pilot' => $pilot])->header('X-Robots-Tag', 'noindex');
})->name('water.pilibhit-groundwater');

Route::get('/api/india-map/navigation', [\App\Http\Controllers\MapSelectionNavigationController::class, 'index'])->middleware('throttle:120,1')->name('map-selection.navigation');
Route::get('/india/places/{state}/{district}/{subdistrict?}', [\App\Http\Controllers\MapSelectionNavigationController::class, 'show'])->whereNumber('state')->whereNumber('district')->whereNumber('subdistrict')->name('map-selection.page');
