<?php

namespace App\Console\Commands;

use App\Http\Controllers\CoverageReportController;
use App\Http\Controllers\ReportController;
use App\Services\ReportArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('reports:archive-due')]
#[Description('Save district, state and country coverage drafts at quarter and year end without publishing')]
class ArchiveDueReports extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReportController $reports, ReportArchive $archive, CoverageReportController $coverage): int
    {
        $date = now('Asia/Kolkata');
        if (! $date->isSameDay($date->copy()->endOfQuarter())) {
            $this->info('No period-end drafts are due.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('reports:archive-due', 600);
        if (! $lock->get()) {
            $this->warn('Report generation is already running.');

            return self::FAILURE;
        }
        try {
            foreach ($date->month === 12 ? ['quarterly', 'annual'] : ['quarterly'] as $edition) {
                foreach (['pilibhit', 'uttar-pradesh', 'india'] as $scope) {
                    $period = $edition === 'annual' ? (string) $date->year : 'Q'.$date->quarter.' '.$date->year;
                    if (DB::table('report_drafts')->where('scope', $scope)->where('edition', $edition)->where('period', $period)
                        ->where('generated_at', '>=', $date->copy()->startOfDay()->utc())->exists()) {
                        $this->info($edition.': period-end draft already saved.');

                        continue;
                    }
                    $id = $archive->save($scope === 'pilibhit' ? $reports->draft($edition) : $coverage->draft($edition, $scope));
                    $this->info($scope.' '.$edition.': saved draft '.$id);
                }
            }
        } catch (Throwable $error) {
            report($error);
            $this->error('Report generation failed. Check required source evidence and storage; no report was published.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
