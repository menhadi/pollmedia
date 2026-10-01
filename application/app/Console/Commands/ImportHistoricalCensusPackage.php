<?php

namespace App\Console\Commands;

use App\Services\HistoricalCensusPackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('census:import-historical {package} {sha256} {--archive= : Existing writable archive directory} {--check : Validate without database writes} {--publish : Publish source editions with their warning notes}')]
#[Description('Import source-separated historical Census draft editions while preserving originals and notes')]
class ImportHistoricalCensusPackage extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(HistoricalCensusPackage $packages): int
    {
        try {
            $path = $this->argument('package');
            $sha256 = $this->argument('sha256');
            if ($this->option('check')) {
                $this->line(json_encode($packages->coverage($packages->verify($path, $sha256)), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $directory = $this->option('archive');
            abort_unless(is_string($directory) && is_dir($directory)
                && disk_free_space($directory) >= 10 * 1024 ** 3 + filesize($path), 422, 'Historical import requires an archive directory and 10 GiB disk reserve.');
            $result = $packages->importDraftPackage($path, $sha256, $directory, (bool) $this->option('publish'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
