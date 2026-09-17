<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\AuthorityReviewController;
use App\Http\Controllers\CensusHistoryController;
use App\Http\Controllers\CensusPublicationController;
use App\Http\Controllers\CoverageReportController;
use App\Http\Controllers\ElectionBatchController;
use App\Http\Controllers\ElectionPublicationController;
use App\Http\Controllers\HistoricalElectionController;
use App\Http\Controllers\HistoricalExtractionController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\ReportArchiveController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\VillageController;
use App\Http\Middleware\AdminTransport;
use App\Http\Middleware\LocalEditorOnly;
use App\Http\Middleware\RequireAdministrator;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/', [OverviewController::class, 'index'])->name('home');
Route::get('/india', [OverviewController::class, 'index'])->name('india');
Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
Route::get('/india/elections/lok-sabha', [HistoricalElectionController::class, 'index'])->name('elections.history');
Route::get('/india/elections/assembly', [HistoricalElectionController::class, 'index'])->name('elections.assembly');
Route::get('/india/pc/{slug}/history', [HistoricalElectionController::class, 'compare'])->name('elections.compare');
Route::get('/india/ac/{slug}/history', [HistoricalElectionController::class, 'compareAssembly'])->name('elections.compare-assembly');
Route::get('/india/district/pilibhit/census-1981', [CensusHistoryController::class, 'edition1981'])->name('census.1981');
Route::get('/india/district/pilibhit/census-history', [CensusHistoryController::class, 'show'])->name('census.history');
Route::get('/reports/pilibhit', [ReportController::class, 'show'])->name('reports.pilibhit');
Route::get('/reports/coverage/{scope}', [CoverageReportController::class, 'show'])->whereIn('scope', ['india', 'uttar-pradesh'])->name('reports.coverage');
Route::get('/reports/archive', [ReportArchiveController::class, 'index'])->name('reports.archive');
Route::post('/reports/archive', [ReportArchiveController::class, 'store'])->middleware([AdminTransport::class, RequireAdministrator::class, 'throttle:10,1'])->name('reports.store');
Route::get('/reports/archive/{report}/download', [ReportArchiveController::class, 'download'])->whereUlid('report')->name('reports.download');
Route::get('/india/state/{state}', [OverviewController::class, 'index'])->name('states.show');
Route::get('/india/villages', [VillageController::class, 'index'])->name('villages.index');
Route::get('/india/village/{code}-{slug}', [VillageController::class, 'show'])->where('code', '(?:[0-9]{6}|[0-9]{8})')->where('slug', '[a-z0-9-]+')->name('villages.show');
Route::get('/india/{type}/{slug}', [PlaceController::class, 'show'])->whereIn('type', ['district', 'pc', 'ac'])->name('places.show');
Route::view('/india/sir', 'sir');
Route::get('/api/sir', [PlaceController::class, 'sir']);
Route::get('/api/census', fn (PlaceController $c) => response()->json($c->payload('census-pilibhit')));
Route::get('/api/geography', fn (PlaceController $c) => response()->json($c->payload('soi-up')));
Route::get('/api/maps/pilibhit-villages', function (): BinaryFileResponse {
    $path = storage_path('app/maps/pilibhit-villages.geojson');
    abort_unless(is_file($path), 404, 'Village map has not been built.');

    return response()->file($path, ['Content-Type' => 'application/geo+json']);
});

Route::prefix('admin/seo')->middleware([AdminTransport::class, RequireAdministrator::class])->group(function (): void {
    Route::get('/', [SeoController::class, 'index'])->name('seo.index');
    Route::post('/drafts', [SeoController::class, 'create'])->middleware('throttle:30,1')->name('seo.create');
    Route::get('/drafts/{batch}', [SeoController::class, 'draft'])->whereUlid('batch')->name('seo.draft');
    Route::post('/drafts/{batch}/save', [SeoController::class, 'save'])->whereUlid('batch')->name('seo.save');
    Route::post('/drafts/{batch}/apply', [SeoController::class, 'apply'])->whereUlid('batch')->name('seo.apply');
    Route::post('/revisions/{revision}/restore', [SeoController::class, 'restore'])->whereNumber('revision')->name('seo.restore');
});

Route::prefix('admin')->middleware(AdminTransport::class)->group(function (): void {
    Route::get('/login', [AdminAuthController::class, 'login'])->name('admin.login');
    Route::post('/login', [AdminAuthController::class, 'authenticate'])->middleware('throttle:15,1')->name('admin.authenticate');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');
    Route::get('/setup', [AdminAuthController::class, 'setup'])->middleware(LocalEditorOnly::class)->name('admin.setup');
    Route::post('/setup', [AdminAuthController::class, 'storeAdministrator'])->middleware([LocalEditorOnly::class, 'throttle:5,1'])->name('admin.setup.store');
});

Route::middleware([AdminTransport::class, RequireAdministrator::class])->group(function (): void {
    Route::get('/admin/authorities', [AuthorityReviewController::class, 'index'])->name('authorities.index');
    Route::get('/admin/authorities/history', [AuthorityReviewController::class, 'history'])->name('authorities.history');
    Route::post('/admin/authorities/replace', [AuthorityReviewController::class, 'replace'])->middleware('throttle:5,1')->name('authorities.replace');
    Route::post('/admin/authorities/{key}/check', [AuthorityReviewController::class, 'check'])->middleware('throttle:3,1')->name('authorities.check');
    Route::get('/admin', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/admin/account', [AdminAuthController::class, 'account'])->name('admin.account');
    Route::post('/admin/account/password', [AdminAuthController::class, 'updatePassword'])->middleware('throttle:5,1')->name('admin.password');
    Route::get('/admin/ai-settings', [AiSettingsController::class, 'index'])->name('ai.settings');
    Route::post('/admin/ai-settings/{provider}', [AiSettingsController::class, 'save'])->middleware('throttle:20,1')->name('ai.settings.save');
});

Route::prefix('admin/imports')->middleware([AdminTransport::class, RequireAdministrator::class])->group(function (): void {
    Route::get('/census-history', [CensusHistoryController::class, 'archive'])->name('census.archive');
    Route::get('/election-batches', [ElectionBatchController::class, 'index'])->name('election-batches.index');
    Route::post('/election-batches', [ElectionBatchController::class, 'store'])->middleware('throttle:5,1')->name('election-batches.store');
    Route::get('/election-batches/{batch}', [ElectionBatchController::class, 'show'])->whereUlid('batch')->name('election-batches.show');
    Route::post('/election-batches/{batch}/publish', [ElectionBatchController::class, 'publish'])->whereUlid('batch')->middleware('throttle:3,1')->name('election-batches.publish');
    Route::post('/election-batches/{batch}/retry', [ElectionBatchController::class, 'retry'])->whereUlid('batch')->middleware('throttle:5,1')->name('election-batches.retry');
    Route::post('/election-batches/{batch}/review/{code}', [ElectionBatchController::class, 'review'])->whereUlid('batch')->whereNumber('code')->middleware('throttle:10,1')->name('election-batches.review');
    Route::post('/election-archives/{archive}/extraction/{code}/review', [HistoricalExtractionController::class, 'review'])->where('archive', '[a-f0-9]{24}')->whereNumber('code')->middleware('throttle:20,1')->name('election-archives.review');
    Route::get('/election-archives/{archive}/extraction', [HistoricalExtractionController::class, 'show'])->where('archive', '[a-f0-9]{24}')->name('election-archives.extraction');
    Route::get('/election-archives/{archive}/{file}', [ElectionPublicationController::class, 'archiveFile'])->where('archive', '[a-f0-9]{24}')->where('file', '[a-f0-9]{24}-[a-z0-9]+')->name('election-archives.file');
    Route::get('/elections', [ElectionPublicationController::class, 'index'])->name('election-imports.index');
    Route::post('/elections', [ElectionPublicationController::class, 'store'])->middleware('throttle:10,1')->name('election-imports.store');
    Route::get('/elections/{draft}', [ElectionPublicationController::class, 'show'])->whereUlid('draft')->name('election-imports.show');
    Route::post('/elections/{draft}/publish', [ElectionPublicationController::class, 'publish'])->whereUlid('draft')->name('election-imports.publish');
    Route::post('/elections/{draft}/restore', [ElectionPublicationController::class, 'restore'])->whereUlid('draft')->name('election-imports.restore');
    Route::get('/elections/{draft}/files/{file}', [ElectionPublicationController::class, 'download'])->whereUlid('draft')->name('election-imports.download');
    Route::get('/', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/', [ImportController::class, 'store'])->name('imports.store');
    Route::post('/{connector}/fetch', [ImportController::class, 'fetch'])->whereNumber('connector')->middleware('throttle:10,1')->name('imports.fetch');
    Route::post('/{connector}/upload', [ImportController::class, 'upload'])->whereNumber('connector')->name('imports.upload');
    Route::post('/{connector}/schedule', [ImportController::class, 'schedule'])->whereNumber('connector')->name('imports.schedule');
    Route::get('/runs/{run}', [ImportController::class, 'show'])->whereNumber('run')->name('imports.run');
    Route::post('/runs/{run}/review', [ImportController::class, 'review'])->whereNumber('run')->name('imports.review');
    Route::get('/runs/{run}/download', [ImportController::class, 'download'])->whereNumber('run')->name('imports.download');
    Route::get('/runs/{run}/census', [CensusPublicationController::class, 'show'])->whereNumber('run')->name('imports.census');
    Route::post('/runs/{run}/census', [CensusPublicationController::class, 'publish'])->whereNumber('run')->name('imports.census.publish');
    Route::post('/publications/{publication}/restore', [CensusPublicationController::class, 'restore'])->whereNumber('publication')->name('imports.census.restore');
});
