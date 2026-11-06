<?php

namespace App\Jobs;

use App\Services\MapAssetStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class QueueStorageCategory implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public string $category, public string $location, public ?int $target, public int $maximumId, public int $user)
    {
        $this->onConnection('pdf_storage')->onQueue('map-storage');
    }

    /**
     * Execute the job.
     */
    public function handle(MapAssetStorage $storage): void
    {
        $query = $storage->categoryQuery($this->category)->where('id', '<=', $this->maximumId);
        $this->location === 'local' ? $query->whereNull('profile_id') : $query->whereNotNull('profile_id');
        $count = 0;
        foreach ($query->orderBy('id')->cursor() as $file) {
            if ($storage->category($file->path) === $this->category) {
                $storage->queue($file->id, $this->target, $this->user);
                $count++;
            }
        }
        Cache::put('map-category-status', $count.' eligible files checked; moves queued. Refresh Move progress below.', 86400);
    }

    public function failed(?\Throwable $error): void
    {
        Cache::put('map-category-status', 'Category queue stopped. Already queued files continue; submit the same category again to resume safely.', 86400);
    }
}
