<?php

namespace App\Console\Commands;

use App\Services\ArchiveFiles;
use App\Services\ElectionArchive;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ExtractLokSabhaArchive extends Command
{
    protected $signature = 'imports:extract-lok-sabha {--year=all : Official Lok Sabha year or all collected editions}';

    protected $description = 'Extract national Lok Sabha candidate tables with state-scoped identities';

    public function handle(ElectionArchive $archive): int
    {
        $year = (string) $this->option('year');
        $entries = collect($archive->catalogue()['pc'])->filter(fn (array $row): bool => $year === 'all' || substr($row[0], 0, 4) === $year);
        if ($entries->isEmpty()) {
            $this->error('No official Lok Sabha edition matches this year.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($entries as $entry) {
            $editionYear = (int) substr($entry[0], 0, 4);
            $this->info($entry[0]);
            $id = substr(hash('sha256', $entry[1]), 0, 24);
            $manifest = app(ArchiveFiles::class)->path('election-archive/'.$id.'/manifest.json');
            if (! is_file($manifest)) {
                $this->error('Collect the official source reports first.');

                $failed = true;

                continue;
            }
            $adapter = $editionYear <= 1999 ? 'legacy' : ($editionYear >= 2014 ? 'modern' : (string) $editionYear);
            $process = new Process([config('imports.python'), base_path('../pilot/extract_pc_'.$adapter.'.py'), $manifest]);
            $process->setTimeout(300);
            app(ArchiveFiles::class)->withDirectory('election-archive/'.$id, fn () => $process->run(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            }));

            $failed = ! $process->isSuccessful() || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
