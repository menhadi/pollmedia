<?php

namespace App\Jobs;

use App\Services\MapAssetStorage;
use App\Services\StorageUsageAudit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ScanMapAssets implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct()
    {
        $this->onConnection('pdf_storage')->onQueue('map-storage');
    }

    public function handle(MapAssetStorage $storage): void
    {
        Cache::lock('map-asset-inventory', 1800)->block(2, function () use ($storage): void {
            Cache::put('map-inventory-status', 'Checking disk usage and eligible archive files...', 86400);
            app(StorageUsageAudit::class)->run();
            $count = count($storage->scan());
            Cache::put('map-inventory-status', $count.' local files checked at '.now()->toDateTimeString(), 86400);
        });
    }

    public function failed(?\Throwable $error): void
    {
        Cache::put('map-inventory-status', 'Scan stopped. Existing files are unchanged; scan again to resume.', 86400);
    }
}
