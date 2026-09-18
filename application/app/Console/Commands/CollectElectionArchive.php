<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class CollectElectionArchive extends Command
{
    protected $signature = 'imports:collect-election-archive {--kind=all : ac, pc or all} {--year= : Optional catalogue year} {--state= : Assembly state as recorded, or all for the national catalogue}';

    protected $description = 'Collect and checksum official historical election report files for every catalogue edition';

    public function handle(): int
    {
        if (! in_array($this->option('kind'), ['ac', 'pc', 'all'], true)) {
            $this->error('Choose ac, pc or all.');

            return self::FAILURE;
        }
        if ($this->option('state') && $this->option('kind') !== 'ac') {
            $this->error('Use --kind=ac with --state.');

            return self::FAILURE;
        }
        $catalogue = $this->option('state') ? 'eci-assembly-national.json' : 'eci-election-archive.json';
        $args = [config('imports.python'), base_path('../pilot/collect_election_archive.py'), database_path('fixtures/'.$catalogue), Storage::disk('local')->path('election-archive'), '--kind', $this->option('kind')];
        if ($this->option('year') !== null) {
            if (! preg_match('/^(19|20)\d{2}$/', (string) $this->option('year'))) {
                $this->error('Use a four-digit year.');

                return self::FAILURE;
            }
            array_push($args, '--year', $this->option('year'));
        }
        if ($this->option('state')) {
            array_push($args, '--state', $this->option('state'));
        }
        $process = new Process($args);
        $process->setTimeout(3600);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
