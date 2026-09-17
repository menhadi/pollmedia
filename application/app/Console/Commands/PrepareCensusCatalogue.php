<?php

namespace App\Console\Commands;

use App\Services\CensusCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrepareCensusCatalogue extends Command
{
    protected $signature = 'census:prepare {run? : Import run id; omit to prepare the latest supported imports} {--publish : Publish prepared data with discrepancy notes, without accepting an import baseline}';

    protected $description = 'Prepare national Census editions and optionally publish with discrepancy notes';

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
                if ($this->option('publish')) {
                    $record = DB::table('census_editions')->find($edition);
                    if ($record->status === 'draft') {
                        $current = (int) (DB::table('census_publications')->where('source_key', $record->source_key)->value('edition_id') ?? 0);
                        $catalogue->publish($edition, null, $current);
                        $this->info('Published '.$record->row_count.' records with '.$record->flag_count.' discrepancy notes.');
                    } else {
                        $this->info('Edition '.$edition.' remains '.$record->status.'; no publication change.');
                    }
                }
            } catch (Throwable $error) {
                $failed = true;
                $this->error('Import '.$run.': '.$error->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
