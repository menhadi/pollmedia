<?php

namespace App\Console\Commands;

use App\Services\ManagedTasks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonitorApplication extends Command
{
    protected $signature = 'site:monitor';

    protected $description = 'Record lightweight database and queue health for the admin dashboard';

    public function handle(ManagedTasks $tasks): int
    {
        DB::select('select 1');
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $open = Schema::hasTable('data_feedback') ? DB::table('data_feedback')->whereIn('status', ['open', 'reviewing'])->count() : 0;
        $tasks->record('health', $failed ? 'attention' : 'healthy', json_encode(['failed_jobs' => $failed, 'open_reports' => $open]));
        $this->info('Application health recorded.');

        return self::SUCCESS;
    }
}
