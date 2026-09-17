<?php

namespace App\Console\Commands;

use App\Services\SourceChangeMonitor;
use Illuminate\Console\Command;

class CheckOfficialSources extends Command
{
    protected $signature = 'sources:check {--source= : Check one configured source key}';

    protected $description = 'Check official directory tables for changes without replacing published records';

    public function handle(SourceChangeMonitor $monitor): int
    {
        $sources = config('source-monitor.sources', []);
        $key = $this->option('source');
        if ($key && ! isset($sources[$key])) {
            $this->error('Unknown configured source.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($key ? [$key => $sources[$key]] : $sources as $sourceKey => $url) {
            $status = $monitor->check($sourceKey, $url);
            $this->line($sourceKey.': '.$status);
            $failed = $failed || $status === 'failed';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
