<?php

namespace App\Console\Commands;

use App\Services\ReportArchive;
use App\Services\ReportScopes;
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
    public function handle(ReportScopes $scopes, ReportArchive $archive): int
    {
        $lock = Cache::lock('reports:archive-due', 600);
        if (! $lock->get()) {
            $this->warn('Report generation is already running.');

            return self::FAILURE;
        }
        $failed = false;
        try {
            foreach (DB::table('report_scopes')->where('automatic', true)->orderBy('key')->get() as $area) {
                $scope = $area->key;
                $date = now($area->timezone);
                if (! $date->isSameDay($date->copy()->endOfQuarter()) || $date->format('H:i') < '23:50') {
                    continue;
                }
                foreach ($date->month === 12 ? ['quarterly', 'annual'] : ['quarterly'] as $edition) {
                    try {
                        $period = $edition === 'annual' ? (string) $date->year : 'Q'.$date->quarter.' '.$date->year;
                        if (DB::table('report_drafts')->where('scope', $scope)->where('edition', $edition)->where('period', $period)
                            ->where('generated_at', '>=', $date->copy()->startOfDay()->utc())->exists()) {
                            $this->info($edition.': period-end draft already saved.');

                            continue;
                        }
                        $id = $archive->save($scopes->draft($scope, $edition));
                        $this->info($scope.' '.$edition.': saved draft '.$id);
                    } catch (Throwable $error) {
                        report($error);
                        $failed = true;
                        $this->error($scope.' '.$edition.': generation failed; check source evidence and storage.');
                    }
                }
            }
        } finally {
            $lock->release();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
