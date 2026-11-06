<?php

use App\Http\Controllers\MyPlaceController;
use App\Http\Controllers\MyPlaceHubController;
use App\Http\Controllers\MyPlacePanchayatController;
use App\Http\Controllers\MyPlaceSchoolsController;
use App\Http\Controllers\SchemeFinderController;
use Illuminate\Support\Facades\Route;

Route::get('/my-place', [MyPlaceController::class, 'start'])->name('my-place.index');
Route::get('/my-place/options', [MyPlaceController::class, 'options'])->middleware('throttle:120,1')->name('my-place.options');
Route::get('/my-place/summary', [MyPlaceController::class, 'summary'])->middleware('throttle:60,1')->name('my-place.summary');
Route::post('/my-place/locate', [MyPlaceController::class, 'locate'])->middleware('throttle:10,1')->name('my-place.locate');
Route::get('/my-place/pilibhit', [MyPlaceController::class, 'index'])->name('my-place.pilibhit');
Route::get('/my-place/schemes', [SchemeFinderController::class, 'index'])->name('my-place.schemes');
Route::post('/my-place/schemes/autofill', [SchemeFinderController::class, 'autofill'])->middleware('throttle:5,10')->name('my-place.autofill');
Route::get('/my-place/markets', [MyPlaceHubController::class, 'markets'])->middleware('throttle:30,1,mandi-prices')->name('my-place.markets');
Route::get('/my-place/market-history', [MyPlaceHubController::class, 'marketHistory'])->middleware('throttle:15,1,mandi-history')->name('my-place.market-history');
Route::post('/my-place/report', [MyPlaceHubController::class, 'report'])->middleware('throttle:3,10')->name('my-place.report');

Route::get('/my-place/schemes/pincode', [SchemeFinderController::class, 'pincode'])->middleware('throttle:30,1,scheme-pincode')->name('my-place.pincode');

Route::get('/my-place/school-records', [MyPlaceSchoolsController::class, 'index'])->middleware('throttle:60,1')->name('my-place.school-records');
Route::get('/my-place/panchayat-records', [MyPlacePanchayatController::class, 'index'])->middleware('throttle:60,1')->name('my-place.panchayat-records');
