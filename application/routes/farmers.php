<?php

use App\Http\Controllers\FarmerAssessmentController;
use App\Http\Middleware\FarmerPrivacy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(FarmerPrivacy::class)->group(function (): void {
    Route::get('/agri-insights', [FarmerAssessmentController::class, 'index'])->name('farmers.index');
    Route::get('/agri-insights/example', [FarmerAssessmentController::class, 'example'])->name('farmers.example');
    Route::get('/agri-insights/examples/{report}/{language}', [FarmerAssessmentController::class, 'exampleReport'])->where('report', 'crop-condition|field-planning|crop-options')->where('language', 'en|hi')->name('farmers.examples.show');
    Route::get('/agri-insights/examples/{report}/{language}/download', [FarmerAssessmentController::class, 'exampleDownload'])->where('report', 'crop-condition|field-planning|crop-options')->where('language', 'en|hi')->name('farmers.examples.download');
    Route::get('/farmers', fn (): RedirectResponse => redirect()->route('farmers.index', request()->query(), 301));
    Route::get('/farmers/language/{language}', [FarmerAssessmentController::class, 'language'])->middleware('throttle:20,1')->name('farmers.language');
    Route::post('/farmers/drafts', [FarmerAssessmentController::class, 'draft'])->middleware('throttle:5,10')->name('farmers.draft');
    Route::post('/farmers/{assessment}/suggest', [FarmerAssessmentController::class, 'suggest'])->whereUlid('assessment')->middleware('throttle:5,10')->name('farmers.suggest');
    Route::post('/farmers/{assessment}/report', [FarmerAssessmentController::class, 'generate'])->whereUlid('assessment')->middleware('throttle:5,10')->name('farmers.generate');
    Route::get('/farmers/{assessment}', [FarmerAssessmentController::class, 'show'])->whereUlid('assessment')->name('farmers.show');
    Route::get('/farmers/{assessment}/photos/{photo}', [FarmerAssessmentController::class, 'media'])->whereUlid('assessment')->whereNumber('photo')->name('farmers.photo');
    Route::post('/farmers/{assessment}/follow-up', [FarmerAssessmentController::class, 'followup'])->whereUlid('assessment')->middleware('throttle:5,10')->name('farmers.followup');
    Route::delete('/farmers/{assessment}', [FarmerAssessmentController::class, 'destroy'])->whereUlid('assessment')->name('farmers.destroy');

});
