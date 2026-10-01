<?php

namespace App\Console\Commands;

use App\Services\ElectionReleaseImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('elections:sync-releases {--status : Show the most recent sync without fetching}')]
#[Description('Import supported new official Assembly editions and flag unsupported election reports')]
class SyncElectionReleases extends Command
{
    public function handle(ElectionReleaseImporter $importer): int
    {
        try {
            $result = $this->option('status') ? $importer->latest() : $importer->run();
            if ($result === null) {
                $this->warn('No election release sync has run yet.');

                return self::SUCCESS;
            }
            $this->info('Checked '.$result['checked_at'].'; imported '.count($result['imported']).'; needs review '.count($result['needs_review']).'.');
            foreach ($result['needs_review'] as $entry) {
                $this->warn(strtoupper($entry['kind']).' '.$entry['year'].' '.$entry['url'].' — '.$entry['reason']);
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Election release sync failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
