<?php

namespace App\Jobs;

use App\Services\ElectionReleaseImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncElectionReleasesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 21600;

    public int $tries = 1;

    public int $uniqueFor = 21600;

    public function uniqueId(): string
    {
        return 'election-release-sync';
    }

    public function handle(ElectionReleaseImporter $importer): void
    {
        $importer->run();
    }
}
