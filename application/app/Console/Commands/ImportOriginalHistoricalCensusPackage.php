<?php

namespace App\Console\Commands;

use App\Services\OriginalHistoricalCensusPackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('census:import-original-pca {package} {sha256} {--archive= : Existing writable archive directory} {--retrieved-at= : Original official retrieval timestamp} {--check : Validate without database writes} {--publish : Publish with original definitions and warning notes}')]
#[Description('Import source-separated original historical PCA measures with preserved PDF evidence')]
class ImportOriginalHistoricalCensusPackage extends Command
{
    public function handle(OriginalHistoricalCensusPackage $packages): int
    {
        try {
            $path = $this->argument('package');
            $sha = $this->argument('sha256');
            if ($this->option('check')) {
                $verified = $packages->verify($path, $sha);
                $this->line(json_encode(['source_key' => $verified['manifest']['source_key'],
                    'year' => $verified['manifest']['year'], 'rows' => count($verified['rows']),
                    'coverage' => $verified['manifest']['coverage'], 'database_writes' => false], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $directory = $this->option('archive');
            $retrievedAt = $this->option('retrieved-at');
            abort_unless(is_string($directory) && is_string($retrievedAt) && strtotime($retrievedAt) !== false,
                422, 'Original PCA import requires an archive directory and official retrieval timestamp.');
            $result = $packages->importDraftPackage($path, $sha, $directory, $retrievedAt, (bool) $this->option('publish'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
