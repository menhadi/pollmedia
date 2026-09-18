<?php

namespace App\Console\Commands;

use App\Services\ArchiveFiles;
use App\Services\ElectionArchive;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ExtractHistoricalElection extends Command
{
    protected $signature = 'imports:extract-historical-election {--year=2007 : Archived UP Assembly year or all}';

    protected $description = 'Extract historical candidates without linking historical codes to current places';

    public function handle(ElectionArchive $archive): int
    {
        $year = (string) $this->option('year');
        $supported = ['2022', '2017', '2012', '2007', '2002', '1996', '1993', '1991', '1989', '1985', '1980', '1977', '1974', '1969', '1967', '1962', '1957', '1951'];
        if ($year === 'all') {
            $failed = false;
            foreach ($supported as $edition) {
                $failed = $this->call(self::class, ['--year' => $edition]) !== self::SUCCESS || $failed;
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        }
        if (! in_array($year, $supported, true)) {
            $this->error('Choose an available Assembly year (1951–2022), or all.');

            return self::FAILURE;
        }
        $entry = collect($archive->catalogue()['ac'])->first(fn (array $row): bool => substr($row[0], 0, 4) === $year);
        $id = substr(hash('sha256', $entry[1]), 0, 24);
        $manifest = app(ArchiveFiles::class)->path('election-archive/'.$id.'/manifest.json');
        if (! is_file($manifest)) {
            $this->error('Collect the official edition before extraction.');

            return self::FAILURE;
        }
        $process = new Process([config('imports.python'), base_path('../pilot/extract_historical_elections.py'), $manifest]);
        $process->setTimeout(300);
        app(ArchiveFiles::class)->withDirectory('election-archive/'.$id, fn () => $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        }));

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
