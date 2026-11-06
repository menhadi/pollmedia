<?php

namespace App\Console\Commands;

use App\Services\MapAssetStorage;
use App\Services\StorageUsageAudit;
use Illuminate\Console\Command;

class RegisterMapAssets extends Command
{
    protected $signature = 'maps:storage {--release= : Register one published satellite release} {--apply-default : Queue moves to the selected destination} {--policy : Print the current non-secret storage policy} {--audit : Refresh disk usage report}';

    protected $description = 'Register satellite assets and apply the administrator-selected storage destination';

    public function handle(MapAssetStorage $storage): int
    {
        if ($this->option('audit')) {
            app(StorageUsageAudit::class)->run();
            $this->info('Disk usage report refreshed. No files moved.');
        }
        if ($this->option('policy')) {
            $this->line(json_encode($storage->policy()));

            return self::SUCCESS;
        }
        if ($this->option('apply-default') && ! $this->option('release')) {
            $this->error('Automatic moves require an explicit release. Use the admin page for existing files.');

            return self::FAILURE;
        }
        $ids = $storage->scan($this->option('release'));
        if ($this->option('apply-default')) {
            foreach ($ids as $id) {
                $storage->queue($id, $storage->policy()['profile_id']);
            }
        }
        $this->info(count($ids).' assets registered.');

        return self::SUCCESS;
    }
}
