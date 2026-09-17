<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HistoricalElectionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HistoricalElectionReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_correction_keeps_nota_out_of_candidate_totals_and_winner_ranking(): void
    {
        $service = app(HistoricalElectionReview::class);
        $user = User::factory()->create(['is_admin' => true]);
        $rows = [
            ['candidate_name' => 'A', 'party_at_election' => 'INC', 'votes' => 20, 'general_votes' => 20, 'postal_votes' => 0],
            ['candidate_name' => 'B', 'party_at_election' => 'BJP', 'votes' => 10, 'general_votes' => 10, 'postal_votes' => 0],
            ['candidate_name' => 'NOTA', 'party_at_election' => 'NOTA', 'votes' => 50, 'general_votes' => 50, 'postal_votes' => 0],
        ];
        $raw = ['code' => 1, 'name' => 'Historical seat', 'status' => 'needs_review', 'candidates' => $rows];
        $hash = str_repeat('a', 64);
        $service->save('nota-archive', $raw, $hash, ['action' => 'correct', 'reason' => 'Checked the official totals', 'fingerprint' => $service->fingerprint($raw, $hash), 'review_id' => 0, 'name' => 'Historical seat', 'electors' => 100, 'votes_polled' => 80, 'valid_candidate_votes' => 30, 'candidates' => $rows], $user->id);
        $record = $service->apply('nota-archive', $raw, $hash);
        $this->assertSame('A', $record['winner']);
        $this->assertSame(10, $record['margin']);
        $this->assertSame(30, $record['valid_candidate_votes']);
        $this->assertTrue($record['candidates'][2]['is_nota']);
    }

    public function test_acceptance_correction_and_changed_source_keep_separate_audit_history(): void
    {
        $service = app(HistoricalElectionReview::class);
        $user = User::factory()->create(['is_admin' => true]);
        $raw = ['code' => 44, 'name' => 'PURANPUR', 'status' => 'needs_review', 'error' => 'Source totals differ', 'electors' => 100, 'votes_polled' => 80, 'valid_candidate_votes' => 70, 'candidates' => [['candidate_name' => 'A', 'party_at_election' => 'BSP', 'votes' => 50, 'general_votes' => null, 'postal_votes' => null], ['candidate_name' => 'B', 'party_at_election' => 'SP', 'votes' => 20, 'general_votes' => null, 'postal_votes' => null]]];
        $hash = str_repeat('a', 64);
        $input = ['action' => 'accept', 'reason' => 'Reviewed both official source tables', 'fingerprint' => $service->fingerprint($raw, $hash), 'review_id' => 0];
        $service->save('archive', $raw, $hash, $input, $user->id);
        $accepted = $service->apply('archive', $raw, $hash);
        $this->assertFalse($accepted['has_warning']);
        $this->assertSame('accepted', $accepted['status']);
        $this->assertTrue($service->apply('archive', $raw, str_repeat('b', 64))['has_warning']);
        $this->assertSame('needs_review', $raw['status']);
        $input = array_merge($input, ['action' => 'correct', 'review_id' => $accepted['review_id'], 'name' => 'Puranpur', 'electors' => 100, 'votes_polled' => 80, 'valid_candidate_votes' => 75, 'candidates' => [['candidate_name' => 'A', 'party_at_election' => 'BSP', 'votes' => 50, 'general_votes' => null, 'postal_votes' => null], ['candidate_name' => 'B', 'party_at_election' => 'SP', 'votes' => 25, 'general_votes' => null, 'postal_votes' => null]]]);
        $service->save('archive', $raw, $hash, $input, $user->id);
        $corrected = $service->apply('archive', $raw, $hash);
        $this->assertSame(75, $corrected['valid_candidate_votes']);
        $this->assertSame(25, $corrected['margin']);
        $this->assertFalse($corrected['has_warning']);
        $this->assertDatabaseCount('historical_election_reviews', 2);
        $this->assertDatabaseCount('election_contests', 0);
        $this->expectException(ValidationException::class);
        $service->save('archive', $raw, $hash, $input, $user->id);
    }
}
