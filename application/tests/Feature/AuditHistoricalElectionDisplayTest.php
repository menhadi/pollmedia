<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditHistoricalElectionDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function importFixture(): string
    {
        $edition = str_repeat('a', 24);
        $records = [
            ['code' => 1, 'name' => 'Example One', 'status' => 'validated', 'electors' => 100, 'votes_polled' => 80, 'winner' => 'Candidate A', 'margin' => 20, 'candidates' => [
                ['candidate_name' => 'Candidate A', 'party_at_election' => 'AAA', 'votes' => 50],
                ['candidate_name' => 'Candidate B', 'party_at_election' => 'BBB', 'votes' => 30],
            ]],
            ['code' => 2, 'name' => 'Example Two', 'status' => 'needs_review', 'electors' => 100, 'votes_polled' => 60, 'candidates' => [
                ['candidate_name' => 'Candidate C', 'party_at_election' => 'CCC', 'votes' => 40],
                ['candidate_name' => 'Candidate D', 'party_at_election' => 'DDD', 'votes' => 20],
            ]],
            ['code' => 3, 'name' => 'Example Three', 'status' => 'needs_review', 'electors' => 100, 'votes_polled' => null, 'candidates' => []],
        ];
        $body = json_encode(['kind' => 'pc', 'year' => 2007, 'source_url' => 'https://www.eci.gov.in/example.pdf', 'source_sha256' => str_repeat('b', 64), 'records' => $records], JSON_THROW_ON_ERROR);
        $path = 'election-archive/'.$edition.'/extraction.json';
        $sha = hash('sha256', $body);
        DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'sha256' => $sha, 'bytes' => strlen($body), 'body' => $body]);
        foreach ($records as $record) {
            DB::table('historical_constituency_index')->insert(['edition_id' => $edition, 'record_code' => $record['code'], 'kind' => 'pc', 'year' => 2007, 'edition_label' => '2007', 'state_label' => 'Example State', 'constituency_name' => $record['name'], 'status' => $record['status'], 'has_warning' => $record['status'] !== 'validated', 'candidate_count' => count($record['candidates']), 'extraction_sha256' => $sha]);
        }

        return $edition;
    }

    public function test_audit_lists_every_source_blank_and_positive_value_hidden_by_display_rules(): void
    {
        $this->importFixture();
        $path = storage_path('app/election-display-audit-'.Str::random(16).'.csv');
        try {
            $this->assertSame(0, Artisan::call('archive:audit-election-display', ['--csv' => $path]));
            $this->assertStringContainsString('pc 2007 3 2 1 1 1 0 1 1 0', Artisan::output());
            $csv = file_get_contents($path);
            $this->assertStringContainsString('source_turnout_hidden', $csv);
            $this->assertStringContainsString('winner_hidden_with_candidate_votes', $csv);
            $this->assertStringContainsString('source_turnout_missing_or_zero', $csv);
            $this->assertStringContainsString('Example Two', $csv);
            $this->assertStringContainsString('Example Three', $csv);
        } finally {
            @unlink($path);
        }
    }

    public function test_audit_fails_if_index_does_not_match_the_preserved_extraction(): void
    {
        $edition = $this->importFixture();
        DB::table('historical_constituency_index')->where('edition_id', $edition)->where('record_code', 3)->delete();
        $path = storage_path('app/election-display-audit-'.Str::random(16).'.csv');
        $this->assertSame(1, Artisan::call('archive:audit-election-display', ['--csv' => $path]));
        $this->assertStringContainsString('index differs', Artisan::output());
        $this->assertFileDoesNotExist($path);
    }
}
