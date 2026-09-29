<?php

namespace Tests\Feature;

use App\Services\ContentHubElectionCards;
use App\Services\ElectionGeographySummary;
use App\Services\HistoricalElectionAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentHubElectionCardsTest extends TestCase
{
    use RefreshDatabase;

    private function history(): array
    {
        $record = ['code' => 1, 'constituency_name' => 'Seat A', 'status' => 'validated', 'electors' => 1000, 'votes_polled' => 700, 'candidates' => [
            ['candidate_name' => 'Candidate A', 'party_at_election' => 'AAA', 'votes' => 400],
            ['candidate_name' => 'Candidate B', 'party_at_election' => 'BBB', 'votes' => 300],
        ]];
        $summary = app(HistoricalElectionAnalytics::class)->summarize([$record]);

        return [$summary + ['year' => 2014], $summary + ['year' => 2019], $summary + ['year' => 2024]];
    }

    public function test_builds_multiple_real_metrics_and_winner_cards_without_notes_filler(): void
    {
        config(['app.url' => 'https://pollmedia.org']);
        $payload = app(ContentHubElectionCards::class)->payload('Karnataka', 'pc', $this->history());
        $cards = $payload['source_cards'];
        $this->assertCount(5, $cards);
        $this->assertSame([70.0, 70.0, 70.0], $cards[0]['visual']['values']);
        $this->assertSame([57.14, 57.14, 57.14], $cards[1]['visual']['values']);
        $this->assertSame([100.0, 100.0, 100.0], $cards[3]['visual']['values']);
        $this->assertStringContainsString('Candidate A (AAA)', $cards[4]['visual']['rows'][0]);
        $this->assertStringContainsString('election=pc', $payload['source_url']);
        $this->assertSame($payload['external_id'], app(ContentHubElectionCards::class)->payload('Karnataka', 'pc', $this->history())['external_id']);
    }

    public function test_missing_party_values_stay_null_and_conflicting_editions_are_not_merged(): void
    {
        $history = $this->history();
        $history[1]['parties'] = [];
        $cards = app(ContentHubElectionCards::class)->payload('Karnataka', 'pc', $history)['source_cards'];
        $this->assertNull($cards[1]['visual']['values'][1]);
        $duplicate = $history[1];
        $duplicate['turnout'] = 99;
        $history[] = $duplicate;
        $payload = app(ContentHubElectionCards::class)->payload('Karnataka', 'pc', $history);
        $this->assertNull($payload['source_cards'][0]['visual']['values'][1]);
        $this->assertStringContainsString('Conflicting editions excluded for: 2019 turnout', $payload['body']);
    }

    public function test_command_sends_authenticated_intake_with_repeatable_revision_id(): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://pollmedia.org', 'services.content_hub.url' => 'https://hub.example', 'services.content_hub.token' => str_repeat('x', 64)]);
        $history = $this->history();
        $this->mock(ElectionGeographySummary::class)->shouldReceive('state')->with('karnataka')->andReturn(['name' => 'Karnataka']);
        $this->mock(HistoricalElectionAnalytics::class)->shouldReceive('forState')->with('Karnataka', 'pc')->andReturn($history);
        Http::fake(['https://hub.example/api/v1/content' => Http::response(['id' => 42], 201)]);
        $this->artisan('content-hub:send-state karnataka')->assertSuccessful();
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.str_repeat('x', 64)) && count($request['source_cards']) === 5 && $request['category'] === 'general');
        Http::assertSentCount(1);
    }

    public function test_preview_does_not_send_data_or_require_a_token(): void
    {
        Http::preventStrayRequests();
        $history = $this->history();
        $this->mock(ElectionGeographySummary::class)->shouldReceive('state')->andReturn(['name' => 'Karnataka']);
        $this->mock(HistoricalElectionAnalytics::class)->shouldReceive('forState')->andReturn($history);
        $this->artisan('content-hub:send-state karnataka --preview')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_unvalidated_winners_and_ties_are_excluded(): void
    {
        $record = ['code' => 1, 'constituency_name' => 'Seat', 'status' => 'validated', 'candidates' => [['candidate_name' => 'A', 'party_at_election' => 'AAA', 'votes' => 10], ['candidate_name' => 'B', 'party_at_election' => 'BBB', 'votes' => 10]]];
        $this->assertSame([], app(HistoricalElectionAnalytics::class)->summarize([$record])['winners']);
        $record['candidates'][0]['votes'] = 20;
        $record['status'] = 'needs_review';
        $this->assertSame([], app(HistoricalElectionAnalytics::class)->summarize([$record])['winners']);
    }
}
