<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ExtractModernAssembly extends Command
{
    protected $signature = 'imports:extract-modern-assembly {--year=2024 : Collected election year}';

    protected $description = 'Extract collected national Assembly workbooks with discrepancy notes';

    public function handle(): int
    {
        if (! preg_match('/^20[0-9]{2}$/', (string) $this->option('year'))) {
            $this->error('Use a four-digit election year.');

            return self::FAILURE;
        }
        $process = new Process([config('imports.python'), base_path('../pilot/extract_assembly_modern.py'), database_path('fixtures/eci-assembly-national.json'), Storage::disk('local')->path('election-archive'), '--year', $this->option('year')]);
        $process->setTimeout(900);
        $process->run(fn ($type, $buffer) => $this->output->write($buffer));

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
