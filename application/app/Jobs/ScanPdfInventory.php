<?php

namespace App\Jobs;

use App\Services\PdfStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ScanPdfInventory implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onConnection('pdf_storage')->onQueue('pdf-storage');
    }

    public function handle(PdfStorage $storage): void
    {
        Cache::lock('pdf-inventory', 1800)->block(2, function () use ($storage): void {
            Cache::put('pdf-inventory-status', 'Scanning local PDFs…', 86400);
            $count = $storage->scan();
            Cache::put('pdf-inventory-status', $count.' local PDFs checked at '.now()->toDateTimeString(), 86400);
        });
    }

    public function failed(?\Throwable $error): void
    {
        Cache::put('pdf-inventory-status', 'Inventory scan stopped. Existing records are safe; scan again to resume.', 86400);
    }
}
