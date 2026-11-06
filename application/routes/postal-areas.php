<?php

use App\Http\Controllers\PlacePostalController;
use Illuminate\Support\Facades\Route;

Route::get('/postal-areas', [PlacePostalController::class, 'index'])->name('postal-areas.index');
Route::get('/postal-areas/states/{state}', [PlacePostalController::class, 'state'])->whereNumber('state')->name('postal-areas.state');
Route::get('/postal-areas/{kind}/{code}', [PlacePostalController::class, 'show'])
    ->where('kind', 'village|local-body')->whereNumber('code')->name('postal-areas.show');

Route::get('/postal-areas/geographies/{state}/{level}/{code}', [PlacePostalController::class, 'geography'])->whereNumber('state')->whereNumber('code')->where('level', 'district|subdistrict')->name('postal-areas.geography');
