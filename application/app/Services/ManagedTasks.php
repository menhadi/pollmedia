<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManagedTasks
{
    public const FREQUENCIES = ['* * * * *' => 'Every minute', '*/5 * * * *' => 'Every five minutes', '*/15 * * * *' => 'Every 15 minutes', '0 * * * *' => 'Hourly', '0 6 * * *' => 'Daily at 06:00 IST'];

    public function definitions(): array
    {
        return [
            'imports' => ['command' => 'imports:refresh --due', 'purpose' => 'Check due official source connectors for updated files.', 'schedule' => '*/5 * * * *', 'enabled' => true],
            'reports' => ['command' => 'reports:archive-due', 'purpose' => 'Build queued downloadable report archives.', 'schedule' => '*/5 * * * *', 'enabled' => true],
            'queue' => ['command' => 'queue:work database --queue=imports --stop-when-empty --max-time=50 --timeout=60 --tries=1', 'purpose' => 'Process application import queue; stops after 50 seconds.', 'schedule' => '* * * * *', 'enabled' => true],
            'sources' => ['command' => 'sources:check', 'purpose' => 'Check configured official representative and authority sources.', 'schedule' => '0 6 * * *', 'enabled' => (bool) config('source-monitor.enabled')],
            'elections' => ['command' => 'elections:check-catalogue', 'purpose' => 'Check official ECI PC, AC and by-election catalogues for new links. No extraction or publication.', 'schedule' => '0 6 * * *', 'enabled' => true],
            'monitor' => ['command' => 'site:monitor', 'purpose' => 'Record database connectivity, failed queue count and open correction reports. No extraction.', 'schedule' => '*/5 * * * *', 'enabled' => true],
        ];
    }

    public function all(): array
    {
        $saved = app(SiteSettings::class)->get('tasks', []);
        $result = $this->definitions();
        foreach ($result as $key => &$task) {
            $task = array_replace($task, array_intersect_key($saved[$key] ?? [], array_flip(['enabled', 'schedule'])));
        }

        return $result;
    }

    public function record(string $key, string $status, ?string $details = null): void
    {
        if (Schema::hasTable('task_health')) {
            DB::table('task_health')->updateOrInsert(['key' => $key], ['status' => $status, 'details' => $details, 'checked_at' => now()]);
        }
    }
}
