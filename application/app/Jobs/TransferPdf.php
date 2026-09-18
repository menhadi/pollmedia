<?php

namespace App\Jobs;

use App\Services\PdfStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TransferPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public function __construct(public int $transferId)
    {
        $this->onQueue('pdf-storage');
        $this->onConnection('pdf_storage');
    }

    public function handle(PdfStorage $storage): void
    {
        try {
            $storage->transfer($this->transferId);
        } catch (\Throwable $error) {
            $this->failed($error);
        }
    }

    public function failed(?\Throwable $error): void
    {
        $transfer = DB::table('pdf_storage_transfers')->find($this->transferId);
        if ($transfer && str_starts_with($transfer->target_key ?? '', 'pdf-working-transfers/')) {
            Storage::disk('local')->delete($transfer->target_key);
        }
        DB::table('pdf_storage_transfers')->where('id', $this->transferId)->where('status', '!=', 'completed')
            ->update(['status' => 'failed', 'message' => 'Transfer stopped. Source was retained unless a verified destination was already activated. Check credentials, free space and file integrity before retrying.', 'updated_at' => now()]);
    }
}
