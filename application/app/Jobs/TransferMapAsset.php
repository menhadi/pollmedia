<?php

namespace App\Jobs;

use App\Services\MapAssetStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class TransferMapAsset implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public function __construct(public int $transferId)
    {
        $this->onConnection('pdf_storage')->onQueue('map-storage');
    }

    public function handle(MapAssetStorage $storage): void
    {
        try {
            $storage->transfer($this->transferId);
        } catch (\Throwable $error) {
            $this->failed($error);
        }
    }

    public function failed(?\Throwable $error): void
    {
        $query = DB::table('map_storage_transfers')->where('id', $this->transferId);
        if ($query->value('status') === 'cleanup_pending') {
            $query->update(['message' => 'Destination verified and active. Source removal is pending; retry cleanup from this page.', 'updated_at' => now()]);
        } else {
            $query->where('status', '!=', 'completed')->update(['status' => 'failed', 'message' => 'Move stopped before activation. Source retained. Check connection, space and checksums before retrying.', 'updated_at' => now()]);
        }
    }
}
