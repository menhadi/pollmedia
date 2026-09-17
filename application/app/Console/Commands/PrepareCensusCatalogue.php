<?php

namespace App\Console\Commands;

use App\Services\CensusCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrepareCensusCatalogue extends Command
{
    protected $signature = 'census:prepare {run? : Import run id; omit to prepare the latest supported imports}';

    protected $description = 'Prepare national Census editions for admin review without publishing';

    public function handle(CensusCatalogue $catalogue): int
    {
        $runs = $this->argument('run') ? [(int) $this->argument('run')] : DB::table('import_runs')
            ->whereIn('status', ['needs_review', 'accepted'])->whereIn('source_url', collect(config('census-sources'))->filter(fn ($source) => ! ($source['archive_only'] ?? false))->pluck('url'))
            ->selectRaw('MAX(id) as id')->groupBy('import_connector_id')->pluck('id')->all();
        $failed = false;
        foreach ($runs as $run) {
            try {
                $edition = $catalogue->prepare($run);
                $this->info('Import '.$run.': prepared Census edition '.$edition);
            } catch (Throwable $error) {
                $failed = true;
                $this->error('Import '.$run.': '.$error->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
