<?php

namespace App\Console\Commands;

use App\Services\ElectionBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportStateElection extends Command
{
    protected $signature = 'imports:state-election {--user= : Administrator id recording this batch} {--year=2022 : Supported election year}';

    protected $description = 'Queue the verified Uttar Pradesh statewide election report pair for review';

    public function handle(ElectionBatch $service): int
    {
        $user = filter_var($this->option('user'), FILTER_VALIDATE_INT);
        if (! $user || ! DB::table('users')->where('id', $user)->where('is_admin', true)->exists()) {
            $this->error('Specify an existing administrator with --user.');

            return self::FAILURE;
        }
        $id = $service->create($user, (int) $this->option('year'));
        $this->info('Batch: '.$id);
        $this->line(route('election-batches.show', $id));

        return self::SUCCESS;
    }
}
