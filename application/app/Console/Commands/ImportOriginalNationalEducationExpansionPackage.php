<?php

namespace App\Console\Commands;

use App\Services\OriginalNationalEducationExpansionPackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('census:import-national-original-education-expansion {package} {sha256} {--archive= : Existing writable archive directory} {--retrieved-at= : Original official retrieval timestamp} {--check : Validate without database writes} {--publish : Publish with original definitions and warning notes} {--expected-current= : Published edition ID required for a strictly additive revision}')]
#[Description('Import the reviewed additive INDIA* and state original Census education subset with source notes')]
class ImportOriginalNationalEducationExpansionPackage extends Command
{
    public function handle(OriginalNationalEducationExpansionPackage $packages): int
    {
        try {
            $path = $this->argument('package');
            $sha = $this->argument('sha256');
            if ($this->option('check')) {
                $verified = $packages->verify($path, $sha);
                $this->line(json_encode(['source_key' => $verified['manifest']['source_key'],
                    'year' => $verified['manifest']['year'], 'rows' => count($verified['rows']),
                    'statistics' => $verified['statistics'], 'database_writes' => false], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $directory = $this->option('archive');
            $retrievedAt = $this->option('retrieved-at');
            abort_unless(is_string($directory) && is_string($retrievedAt) && strtotime($retrievedAt) !== false,
                422, 'Original education import requires an archive directory and official retrieval timestamp.');
            $expected = $this->option('expected-current');
            abort_unless($expected === null || (is_string($expected) && preg_match('/^(0|[1-9][0-9]*)$/', $expected)
                && filter_var($expected, FILTER_VALIDATE_INT) !== false && $this->option('publish')),
                422, 'Expected current edition requires --publish and a non-negative integer.');
            $result = $packages->importDraftPackage($path, $sha, $directory, $retrievedAt, (bool) $this->option('publish'),
                $expected === null ? null : (int) $expected);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
