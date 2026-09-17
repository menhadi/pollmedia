<?php

namespace App\Services;

use App\Http\Controllers\CoverageReportController;
use App\Http\Controllers\ReportController;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class ReportScopes
{
    public function draft(string $key, string $edition): View
    {
        $scope = DB::table('report_scopes')->where('key', $key)->first();
        abort_unless($scope, 404);

        return match ($scope->adapter) {
            'coverage' => app(CoverageReportController::class)->draft($edition, $key),
            'pilibhit-snapshot' => app(ReportController::class)->draft($edition),
            default => abort(422, 'Unsupported report adapter.'),
        };
    }
}
