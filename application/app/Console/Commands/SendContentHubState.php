<?php

namespace App\Console\Commands;

use App\Services\ContentHubElectionCards;
use App\Services\ElectionGeographySummary;
use App\Services\HistoricalElectionAnalytics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SendContentHubState extends Command
{
    protected $signature = 'content-hub:send-state {state : State slug} {--kind=pc : pc or ac} {--channel=facebook} {--preview : Print payload without sending}';

    protected $description = 'Send real election charts and winner-list candidates to Content Hub';

    public function handle(ElectionGeographySummary $geography, HistoricalElectionAnalytics $analytics, ContentHubElectionCards $cards): int
    {
        $kind = $this->option('kind');
        $channel = $this->option('channel');
        if (! in_array($kind, ['pc', 'ac'], true) || ! in_array($channel, ['facebook', 'instagram', 'linkedin', 'x'], true)) {
            $this->error('Choose pc/ac and facebook, instagram, linkedin or x.');

            return self::FAILURE;
        }
        try {
            $state = $geography->state($this->argument('state'))['name'];
            $payload = $cards->payload($state, $kind, $analytics->forState($state, $kind), $channel);
        } catch (\Throwable) {
            $this->error('Could not prepare at least two substantive cards from validated history. Check the state and available data.');

            return self::FAILURE;
        }
        if ($this->option('preview')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $url = config('services.content_hub.url');
        $token = config('services.content_hub.token');
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) || ! is_string($token) || strlen($token) !== 64) {
            $this->error('Set CONTENT_HUB_URL and CONTENT_HUB_TOKEN in this application’s server settings.');

            return self::FAILURE;
        }
        try {
            $response = Http::withToken($token)->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(30)->post(rtrim($url, '/').'/api/v1/content', $payload);
            if (! $response->successful()) {
                $this->error('Content Hub rejected intake (HTTP '.$response->status().'). Check token, URL and payload.');

                return self::FAILURE;
            }
            $this->info('Sent '.count($payload['source_cards']).' source cards. Intake ID: '.(int) $response->json('id').'. Review and scheduling follow Content Hub rules.');
        } catch (\Throwable) {
            $this->error('Intake response unavailable. Rerunning unchanged data uses the same revision ID to avoid duplicates.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
