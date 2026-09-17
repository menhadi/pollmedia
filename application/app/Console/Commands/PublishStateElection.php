<?php

namespace App\Console\Commands;

use App\Services\StateElectionPublication;
use Illuminate\Console\Command;

class PublishStateElection extends Command
{
    protected $signature = 'imports:publish-state-election {batch} {--user= : Administrator ID}';

    protected $description = 'Publish a verified statewide election batch to local constituency pages';

    public function handle(StateElectionPublication $service): int
    {
        $service->publish((string) $this->argument('batch'), (int) $this->option('user'));
        $this->info('Statewide election pages published. Existing matching editions were preserved.');

        return self::SUCCESS;
    }
}
