<?php

namespace App\Console\Commands;

use App\Services\OfficialImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('imports:refresh {--due : Fetch only enabled sources whose check is due}')]
#[Description('Download and stage configured automatic official-data imports without publishing')]
class RefreshImports extends Command
{
    public function handle(OfficialImport $importer): int
    {
        $connectors = DB::table('import_connectors')->where('automatic', true)
            ->when($this->option('due'), fn ($query) => $query->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now())))->get();
        $failed = false;
        foreach ($connectors as $connector) {
            try {
                $id = $importer->run($connector->id);
                $status = DB::table('import_runs')->where('id', $id)->value('status');
                $this->line($connector->name.': '.$status.' (run '.$id.')');
                $failed = $failed || $status === 'failed';
            } catch (RuntimeException) {
                $this->warn($connector->name.': already running or unavailable');
                $failed = true;
            }
        }
        if ($connectors->isEmpty()) {
            $this->info('No automatic sources are due.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
