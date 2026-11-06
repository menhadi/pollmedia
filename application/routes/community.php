<?php

use App\Http\Controllers\CommunityAuthController;
use App\Http\Controllers\CommunityAuthSettingsController;
use App\Http\Controllers\CommunityDataController;
use App\Http\Controllers\CommunityListingController;
use App\Http\Middleware\AdminTransport;
use App\Http\Middleware\RequireAdministrator;
use Illuminate\Support\Facades\Route;

Route::get('/data/census', [CommunityDataController::class, 'index'])->name('community.data');
Route::get('/data/census/records/{record}', [CommunityDataController::class, 'show'])->whereNumber('record')->name('community.record');
Route::post('/data/census/records/{record}/suggest', [CommunityDataController::class, 'propose'])->whereNumber('record')->middleware('throttle:10,10')->name('community.propose');
Route::get('/sign-in', [CommunityAuthController::class, 'login'])->name('community.login');
Route::post('/sign-in/password', [CommunityAuthController::class, 'passwordLogin'])->middleware('throttle:10,1')->name('community.password.login');
Route::post('/account/password', [CommunityAuthController::class, 'setPassword'])->middleware('throttle:5,10')->name('community.password.set');
Route::get('/contribute/place/{place}', [CommunityListingController::class, 'show'])->whereNumber('place')->name('community.listing');
Route::post('/contribute/place/{place}', [CommunityListingController::class, 'propose'])->whereNumber('place')->middleware('throttle:10,10')->name('community.listing.propose');
Route::get('/account', [CommunityDataController::class, 'account'])->name('community.account');
Route::post('/sign-in/email', [CommunityAuthController::class, 'sendCode'])->middleware('throttle:10,10')->name('community.email');
Route::post('/sign-in/verify', [CommunityAuthController::class, 'verifyCode'])->middleware('throttle:20,10')->name('community.verify');
Route::get('/sign-in/google', [CommunityAuthController::class, 'google'])->middleware('throttle:10,1')->name('community.google');
Route::get('/sign-in/google/callback', [CommunityAuthController::class, 'googleCallback'])->middleware('throttle:20,1')->name('community.google.callback');
Route::post('/sign-out', [CommunityAuthController::class, 'logout'])->name('community.logout');
Route::middleware([AdminTransport::class, RequireAdministrator::class])->prefix('admin')->group(function () {
    Route::get('/contributor-login', [CommunityAuthSettingsController::class, 'index'])->name('community.auth.settings');
    Route::post('/contributor-login', [CommunityAuthSettingsController::class, 'save'])->middleware('throttle:10,1')->name('community.auth.save');
    Route::get('/data-suggestions', [CommunityDataController::class, 'review'])->name('community.review');
    Route::post('/data-suggestions/{proposal}', [CommunityDataController::class, 'decide'])->whereNumber('proposal')->middleware('throttle:20,1')->name('community.decide');
});

Route::get('/contribute/census', [CommunityListingController::class, 'scope'])->name('community.scope');
Route::get('/contribute/election/{entry}', [CommunityListingController::class, 'election'])->whereNumber('entry')->name('community.election');
Route::post('/contribute/election/{entry}', [CommunityListingController::class, 'proposeElection'])->whereNumber('entry')->middleware('throttle:10,10')->name('community.election.propose');

Route::get('/api/community/map-data', [CommunityListingController::class, 'mapData'])->middleware('throttle:120,1')->name('community.map-data');

Route::get('/contribute/village/{code}', [CommunityListingController::class, 'village'])->whereNumber('code')->name('community.village');
