<?php

use App\Http\Controllers\LocalBoardController;
use App\Http\Middleware\AdminTransport;
use App\Http\Middleware\LocalBoardPrivacy;
use App\Http\Middleware\RequireAdministrator;
use Illuminate\Support\Facades\Route;

Route::middleware(LocalBoardPrivacy::class)->group(function () {
    Route::get('/local-board', [LocalBoardController::class, 'index'])->name('local-board.index');
    Route::get('/local-board/places', [LocalBoardController::class, 'places'])->middleware('throttle:90,1')->name('local-board.places');
    Route::get('/local-board/create', [LocalBoardController::class, 'create'])->name('local-board.create');
    Route::post('/local-board', [LocalBoardController::class, 'store'])->middleware('throttle:5,10')->name('local-board.store');
    Route::get('/local-board/mine', [LocalBoardController::class, 'mine'])->name('local-board.mine');
    Route::get('/local-board/{listing}/edit', [LocalBoardController::class, 'edit'])->whereUlid('listing')->name('local-board.edit');
    Route::post('/local-board/{listing}/update', [LocalBoardController::class, 'update'])->whereUlid('listing')->middleware('throttle:10,10')->name('local-board.update');
    Route::post('/local-board/{listing}/close', [LocalBoardController::class, 'close'])->whereUlid('listing')->middleware('throttle:10,10')->name('local-board.close');
    Route::get('/local-board/{listing}', [LocalBoardController::class, 'show'])->whereUlid('listing')->name('local-board.show');
});
Route::middleware([AdminTransport::class, RequireAdministrator::class])->prefix('admin')->group(function () {
    Route::get('/local-board', [LocalBoardController::class, 'review'])->name('local-board.review');
    Route::post('/local-board/{listing}/review', [LocalBoardController::class, 'decide'])->whereUlid('listing')->middleware('throttle:30,1')->name('local-board.decide');
});
