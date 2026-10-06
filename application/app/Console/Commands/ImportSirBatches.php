<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ImportSirBatches extends Command
{
    protected $signature = 'sir:import-batches {manifest : JSON array of file, pdf, sha256 entries}';

    protected $description = 'Resume checksummed official SIR imports, publishing each completed package';

    public function handle(): int
    {
        $manifest = realpath($this->argument('manifest'));
        if (! $manifest || filesize($manifest) > 5000000) {
            $this->error('A readable manifest below 5 MB is required.');

            return self::FAILURE;
        }
        try {
            $entries = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($entries) || ! array_is_list($entries) || count($entries) > 10000) {
                throw new \RuntimeException('Manifest must be an array of at most 10,000 packages.');
            }
            $failures = 0;
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($entry['file'], $entry['pdf'], $entry['sha256']) || ! preg_match('/^[a-f0-9]{64}$/', $entry['sha256'])) {
                    throw new \RuntimeException('Each entry requires file, pdf and SHA-256.');
                }
                $file = realpath(dirname($manifest).'/'.$entry['file']);
                $pdf = realpath(dirname($manifest).'/'.$entry['pdf']);
                if (! $file || ! $pdf) {
                    $this->error('Missing package: '.$entry['file']);
                    $failures++;

                    continue;
                }
                $lock = Cache::lock('sir-batch-manifest:'.$entry['sha256'], 600);
                if (! $lock->get()) {
                    $this->error('Package already importing: '.$entry['file']);
                    $failures++;

                    continue;
                }
                try {
                    // Idempotent importer keeps row IDs and manually reviewed values on resumption.
                    $failures += $this->call('sir:import-records', ['file' => $file, '--sha256' => $entry['sha256'], '--pdf' => $pdf]) === 0 ? 0 : 1;
                } finally {
                    $lock->release();
                }
            }

            return $failures ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
