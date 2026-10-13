<?php

use App\Http\Controllers\IndiaVillageMapController;
use Illuminate\Support\Facades\Route;

Route::get('/india/map', [IndiaVillageMapController::class, 'index'])->name('india-map.index');
Route::get('/india/map/places/{id}', [IndiaVillageMapController::class, 'place'])->where('id', '[a-f0-9]{32}')->name('india-map.place');
Route::get('/api/india-map/catalogue', [IndiaVillageMapController::class, 'catalogue'])->name('india-map.catalogue');
Route::get('/api/india-map/places/{id}', [IndiaVillageMapController::class, 'detail'])->where('id', '[a-f0-9]{32}')->middleware('throttle:120,1')->name('india-map.detail');
Route::get('/api/india-map/search', [IndiaVillageMapController::class, 'search'])->middleware('throttle:120,1')->name('india-map.search');
Route::get('/api/india-map/layers/{layer}', [IndiaVillageMapController::class, 'geometry'])->where('layer', '[a-f0-9]{20}')->name('india-map.geometry');
Route::get('/api/india-map/layers/{layer}/features/{index}', [IndiaVillageMapController::class, 'feature'])->where('layer', '[a-f0-9]{20}')->whereNumber('index')->middleware('throttle:120,1')->name('india-map.feature');
