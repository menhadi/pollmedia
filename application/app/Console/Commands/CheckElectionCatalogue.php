<?php

namespace App\Console\Commands;

use App\Services\ElectionCatalogueMonitor;
use App\Services\ManagedTasks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('elections:check-catalogue {--status : Show the most recent official-source check without fetching}')]
#[Description('Record new official ECI PC, AC and by-election links without publishing unverified results')]
class CheckElectionCatalogue extends Command
{
    public function handle(ElectionCatalogueMonitor $monitor): int
    {
        try {
            $result = $this->option('status') ? $monitor->latest() : $monitor->check();
            if ($result === null) {
                $this->warn('No election catalogue check has run yet.');

                return self::SUCCESS;
            }
            if (! $this->option('status')) {
                app(ManagedTasks::class)->record('election_catalogue', $result['pending_count'] > 0 ? 'attention' : 'healthy',
                    json_encode(['source_sha256' => $result['source_sha256'], 'pending_count' => $result['pending_count']], JSON_THROW_ON_ERROR));
            }
            $this->line('Checked '.$result['checked_at'].'; PC '.$result['counts']['pc'].'; AC '.$result['counts']['ac'].'; by-election '.$result['counts']['be'].'; pending '.$result['pending_count'].'.');
            foreach ($result['pending'] as $entry) {
                $this->line(strtoupper($entry['kind']).' '.($entry['state_label'] ?? '').' '.($entry['year'] ?? '').' '.$entry['url']);
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            if (! $this->option('status')) {
                app(ManagedTasks::class)->record('election_catalogue', 'failed', $error->getMessage());
            }
            $this->error('Election catalogue check failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
