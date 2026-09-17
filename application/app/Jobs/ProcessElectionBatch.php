<?php

namespace App\Jobs;

use App\Services\ElectionBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessElectionBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $batchId) {}

    public function handle(ElectionBatch $service): void
    {
        $service->process($this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ElectionBatch::class)->fail($this->batchId, 'Processing failed or timed out. The reports were not published. Check the archived pair and retry; technical details are in the worker log.');
    }
}
