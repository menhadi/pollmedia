<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionArchive;
use App\Services\HistoricalElectionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoricalElectionPublicTest extends TestCase
{
    use RefreshDatabase;

    private function edition(int $year = 2009, string $kind = 'pc'): array
    {
        $entry = app(ElectionArchive::class)->entries($kind, $year)[0];
        $id = $entry['collection']['id'];
        $root = 'election-archive/'.$id.'/';
        $hash = hash('sha256', 'official test source');
        $files = [];
        foreach (['detail', 'summary'] as $name) {
            $files[] = ['file' => $name.'.pdf', 'download_id' => $name, 'name' => $name, 'sha256' => $hash];
            Storage::disk('local')->put($root.$name.'.pdf', 'official test source');
        }
        Storage::disk('local')->put($root.'manifest.json', json_encode(['url' => $entry['url'], 'files' => $files]));
        $record = ['code' => 451, 'official_pc_code' => 26, 'state_code' => 'S24', 'constituency_name' => 'Pilibhit', 'name' => 'S24 / Pilibhit', 'status' => 'needs_review', 'error' => 'Source totals differ', 'detail_page' => 160, 'summary_page' => 451, 'candidates' => [['candidate_name' => 'Candidate One', 'party_at_election' => 'PARTY', 'votes' => 12345, 'general_votes' => null, 'postal_votes' => null]]];
        $other = array_replace($record, ['code' => 452, 'state_code' => 'S01', 'name' => 'S01 / Other', 'constituency_name' => 'Other']);
        if ($kind === 'ac') {
            unset($record['state_code'], $record['official_pc_code'], $record['constituency_name']);
            $record['code'] = 1;
            $record['name'] = 'SEOHARA';
            $other = array_replace($record, ['code' => 2, 'name' => 'OTHER']);
        }
        Storage::disk('local')->put($root.'extraction.json', json_encode(['source_url' => $entry['url'], 'source_file' => 'detail.pdf', 'source_sha256' => $hash, 'additional_sources' => [$files[1]], 'kind' => $kind, 'year' => $year, 'records' => [$record, $other]]));

        return [$id, $record, $hash, $entry['url']];
    }

    public function test_national_assembly_editions_keep_state_identity_notes_and_csv(): void
    {
        Storage::fake('local');
        [$old, , $hash] = $this->edition(2022, 'ac');
        $url = 'https://www.eci.gov.in/statistical-report/ae/2024/6';
        $id = substr(hash('sha256', $url), 0, 24);
        $root = 'election-archive/'.$id.'/';
        foreach (['detail.pdf', 'summary.pdf', 'manifest.json', 'extraction.json'] as $file) {
            Storage::disk('local')->put($root.$file, Storage::disk('local')->get('election-archive/'.$old.'/'.$file));
        }
        $manifest = json_decode(Storage::disk('local')->get($root.'manifest.json'), true);
        $manifest['url'] = $url;
        Storage::disk('local')->put($root.'manifest.json', json_encode($manifest));
        $data = json_decode(Storage::disk('local')->get($root.'extraction.json'), true);
        $data['source_url'] = $url;
        $data['year'] = 2024;
        foreach ($data['records'] as &$row) {
            $row['state_name'] = 'Haryana';
            $row['edition_notes'] = ['Original source coverage note'];
        }
        unset($row);
        Storage::disk('local')->put($root.'extraction.json', json_encode($data));
        $this->get(route('elections.assembly', ['edition' => $id, 'state' => 'Haryana', 'code' => 1]))
            ->assertOk()->assertSee('Original source coverage note')->assertSee('Source totals differ');
        $this->get(route('elections.assembly', ['edition' => $id, 'state' => 'Uttar Pradesh', 'code' => 1]))->assertNotFound();
        $this->get(route('elections.assembly', ['edition' => $id, 'state' => 'Haryana', 'format' => 'csv']))->assertOk()->assertDownload();
    }

    public function test_guests_browse_scoped_historical_records_and_official_sources(): void
    {
        Storage::fake('local');
        [$id, , , $source] = $this->edition();
        $this->get(route('elections.history'))->assertOk()->assertSee('2009 Vol I, II, III')->assertDontSee('Candidate One');
        $url = route('elections.history', ['edition' => $id, 'state' => 'S24', 'code' => 451]);
        $this->get($url)->assertOk()->assertSee('Candidate One')->assertSee('12,345')->assertSee('Not reported')->assertSee('id="data-note"', false)->assertSee('Source totals differ')->assertSee($source)->assertDontSee('Winner:')->assertDontSee('S01 / Other');
        $this->get(route('elections.history', ['edition' => $id, 'state' => 'S01', 'code' => 451]))->assertNotFound();
        $this->get(route('elections.history', ['edition' => $id, 'code' => 451]))->assertNotFound();
        $this->get(route('elections.history', ['edition' => str_repeat('a', 24)]))->assertNotFound();
        $this->assertDatabaseCount('places', 0);
        $this->assertDatabaseCount('election_contests', 0);
    }

    public function test_public_notes_follow_admin_reviews_and_source_changes(): void
    {
        config(['app.debug' => false]);
        Storage::fake('local');
        [$id, $record, $hash] = $this->edition();
        $reviews = app(HistoricalElectionReview::class);
        $reviews->save($id, $record, $hash, ['action' => 'accept', 'reason' => 'Checked source difference', 'fingerprint' => $reviews->fingerprint($record, $hash), 'review_id' => 0], User::factory()->create(['is_admin' => true])->id);
        $url = route('elections.history', ['edition' => $id, 'state' => 'S24', 'code' => 451]);
        $this->get($url)->assertOk()->assertDontSee('id="data-note"', false)->assertSee('Administrator accepted')->assertDontSee('Checked source difference');
        $stateUrl = route('elections.history', ['edition' => $id, 'state' => 'S24']);
        $this->get($stateUrl)->assertOk()->assertDontSee('id="state-note-451"', false)->assertSee('See candidate table')->assertDontSee('Checked source difference')
            ->assertSee('No established single-seat winners are available to chart')->assertViewHas('partySummary', fn (array $summary): bool => $summary['counted'] === 0 && $summary['under_review'] === 0 && $summary['other'] === 1);
        $path = 'election-archive/'.$id.'/extraction.json';
        $data = json_decode(Storage::disk('local')->get($path), true);
        $data['records'][0]['candidates'][0]['votes']++;
        Storage::disk('local')->put($path, json_encode($data));
        $this->get($url)->assertOk()->assertSee('id="data-note"', false)->assertDontSee('Administrator accepted');
        $this->get($stateUrl)->assertOk()->assertSee('id="state-note-451"', false)->assertSee('Under review')
            ->assertViewHas('partySummary', fn (array $summary): bool => $summary['under_review'] === 1 && $summary['other'] === 0);
        Storage::disk('local')->put('election-archive/'.$id.'/summary.pdf', 'changed');
        $this->get($url)->assertStatus(409)->assertDontSee('Candidate One');
    }

    public function test_coverage_counts_only_the_selected_edition_and_preserves_state_links(): void
    {
        Storage::fake('local');
        [$older] = $this->edition(2009);
        $this->edition(2014);
        $path = 'election-archive/'.$older.'/extraction.json';
        $data = json_decode(Storage::disk('local')->get($path), true);
        $data['records'][] = array_replace($data['records'][0], ['code' => 453, 'candidates' => []]);
        $data['records'][] = array_replace($data['records'][0], ['code' => 454, 'state_code' => '', 'candidates' => []]);
        Storage::disk('local')->put($path, json_encode($data));
        $this->get(route('elections.history', ['edition' => $older]))->assertOk()
            ->assertSee('State coverage in this edition')->assertSee('State not identified')
            ->assertSee(route('elections.history', ['edition' => $older, 'state' => 'S24']))
            ->assertViewHas('coverage', fn ($coverage): bool => $coverage->all() === [
                ['state' => '', 'tables' => 1, 'rows' => 0],
                ['state' => 'S01', 'tables' => 1, 'rows' => 1],
                ['state' => 'S24', 'tables' => 2, 'rows' => 1],
            ]);
        $this->get(route('elections.history', ['edition' => $older, 'state' => 'S24', 'code' => 451]))->assertOk()->assertDontSee('State coverage in this edition');
    }

    public function test_empty_archive_and_editions_remain_separate(): void
    {
        Storage::fake('local');
        $this->get(route('elections.history'))->assertOk()->assertSee('No extracted editions');
        [$older] = $this->edition(2009);
        [$newer] = $this->edition(2014);
        $this->get(route('elections.history', ['edition' => $older]))->assertOk()->assertViewHas('data', fn (array $data): bool => $data['year'] === 2009);
        $this->get(route('elections.history'))->assertOk()->assertViewHas('edition', $newer);
    }

    public function test_state_overview_scopes_winners_and_omits_unresolved_and_multi_seat_margins(): void
    {
        Storage::fake('local');
        [$id] = $this->edition();
        $path = 'election-archive/'.$id.'/extraction.json';
        $data = json_decode(Storage::disk('local')->get($path), true);
        $valid = array_replace($data['records'][0], ['code' => 453, 'constituency_name' => 'Verified seat', 'status' => 'validated', 'winner' => 'Candidate One', 'margin' => 123]);
        $data['records'][] = $valid;
        $data['records'][] = array_replace($valid, ['code' => 454, 'number_of_seats' => 2, 'margin' => 456]);
        Storage::disk('local')->put($path, json_encode($data));
        $this->get(route('elections.history', ['edition' => $id, 'state' => 'S24']))->assertOk()
            ->assertSee('Constituency results in S24')->assertSee('Candidate One')->assertSee('PARTY')->assertSee('123')
            ->assertSee('id="state-note-451"', false)->assertSee('Under review')->assertDontSee('S01 / Other')->assertDontSee('456')
            ->assertViewHas('stateResults', fn ($rows): bool => $rows->count() === 3 && $rows->firstWhere('code', 454)['winner_party'] === null)
            ->assertSee('Party-wise wins in available results')->assertSee('PARTY: 1 of 1 counted wins')
            ->assertViewHas('partySummary', fn (array $summary): bool => $summary['counted'] === 1 && $summary['under_review'] === 1 && $summary['other'] === 1 && $summary['parties']->all() === ['PARTY' => 1]);
    }

    public function test_assembly_archive_uses_its_own_editions_state_scope_and_links(): void
    {
        Storage::fake('local');
        [$pc] = $this->edition();
        [$ac] = $this->edition(2007, 'ac');
        $this->get(route('elections.assembly'))->assertOk()->assertSee('India Assembly election archive')->assertViewHas('edition', $ac);
        $state = route('elections.assembly', ['edition' => $ac, 'state' => 'Uttar Pradesh']);
        $record = route('elections.assembly', ['edition' => $ac, 'state' => 'Uttar Pradesh', 'code' => 1]);
        $this->get($state)->assertOk()->assertSee('SEOHARA')->assertSee($record)->assertSee('id="state-note-1"', false);
        $this->get($record)->assertOk()->assertSee('Candidate One')->assertSee('Official constituency code: 1')->assertSee('id="data-note"', false)->assertDontSee('present-day profile');
        $this->get(route('elections.assembly', ['edition' => $pc]))->assertNotFound();
        $this->get(route('elections.history', ['edition' => $ac]))->assertNotFound();
        $this->get(route('elections.assembly', ['edition' => $ac, 'state' => 'Other state', 'code' => 1]))->assertNotFound();
    }

    public function test_symbols_and_nota_are_preserved_in_public_tables_and_csv(): void
    {
        Storage::fake('local');
        [$id] = $this->edition();
        $path = 'election-archive/'.$id.'/extraction.json';
        $data = json_decode(Storage::disk('local')->get($path), true);
        $data['records'][0]['candidates'][0]['election_symbol'] = 'Example symbol';
        $data['records'][0]['candidates'][0]['source_page'] = 161;
        $data['records'][0]['candidates'][] = ['candidate_name' => 'None of the Above', 'party_at_election' => 'NOTA', 'is_nota' => true, 'votes' => 20];
        Storage::disk('local')->put($path, json_encode($data));
        $filters = ['edition' => $id, 'state' => 'S24', 'code' => 451];
        $this->get(route('elections.history', $filters))->assertOk()->assertSee('Election symbol')->assertSee('Example symbol')->assertSee('None of the Above');
        $csv = $this->get(route('elections.history', $filters + ['format' => 'csv']))->assertOk()->streamedContent();
        $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", trim($csv)));
        $headers = $lines[0];
        $this->assertSame('Example symbol', $lines[1][array_search('election_symbol', $headers)]);
        $this->assertSame('161', $lines[1][array_search('candidate_pdf_page', $headers)]);
        $this->assertSame('nota', $lines[2][array_search('row_type', $headers)]);
    }

    public function test_separate_election_rounds_keep_official_codes_and_distinct_public_records(): void
    {
        Storage::fake('local');
        [$id] = $this->edition(2007, 'ac');
        $path = 'election-archive/'.$id.'/extraction.json';
        $data = json_decode(Storage::disk('local')->get($path), true);
        foreach ($data['records'] as $index => &$record) {
            $record['code'] = ($index + 1) * 100000 + 1;
            $record['official_ac_code'] = 1;
            $record['name'] = $index === 0 ? 'Sample / February' : 'Sample / October';
            $record['election_round'] = $index === 0 ? 'February' : 'October';
            $record['source_document'] = $record['election_round'].'.pdf';
        }
        unset($record);
        Storage::disk('local')->put($path, json_encode($data));
        $filters = ['edition' => $id, 'state' => 'Uttar Pradesh', 'code' => 100001];
        $this->get(route('elections.assembly', $filters))->assertOk()->assertSee('Official constituency code: 1')->assertSee('Sample / February');
        $this->get(route('elections.assembly', array_replace($filters, ['code' => 200001])))->assertOk()->assertSee('Sample / October');
        $csv = $this->get(route('elections.assembly', $filters + ['format' => 'csv']))->assertOk()->streamedContent();
        $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", trim($csv)));
        $this->assertSame('1', $lines[1][array_search('official_constituency_code', $lines[0])]);
        $this->assertSame('February', $lines[1][array_search('election_round', $lines[0])]);
        $this->assertSame('February.pdf', $lines[1][array_search('source_document', $lines[0])]);
    }

    public function test_csv_preserves_notes_sources_scope_and_safe_spreadsheet_cells(): void
    {
        Storage::fake('local');
        [$id, , , $source] = $this->edition();
        $path = 'election-archive/'.$id.'/extraction.json';
        $data = json_decode(Storage::disk('local')->get($path), true);
        $data['records'][0]['candidates'][0]['candidate_name'] = '=1+1';
        Storage::disk('local')->put($path, json_encode($data));
        $filters = ['edition' => $id, 'state' => 'S24', 'code' => 451, 'format' => 'csv'];
        $response = $this->get(route('elections.history', $filters))->assertOk()->assertDownload('pollmedia-pc-2009-451.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('† Source totals differ', $csv);
        $this->assertStringContainsString($source, $csv);
        $this->assertStringNotContainsString('S01 / Other', $csv);
        $this->get(route('elections.history', array_replace($filters, ['state' => 'S01'])))->assertNotFound();
        $this->get(route('elections.history', ['format' => 'csv']))->assertNotFound();
        $record = $data['records'][0];
        $reviews = app(HistoricalElectionReview::class);
        $reviews->save($id, $record, $data['source_sha256'], ['action' => 'accept', 'reason' => 'Private review reason', 'fingerprint' => $reviews->fingerprint($record, $data['source_sha256']), 'review_id' => 0], User::factory()->create(['is_admin' => true])->id);
        $updated = $this->get(route('elections.history', $filters))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('†', $updated);
        $this->assertStringNotContainsString('Private review reason', $updated);
        $this->assertStringContainsString('accepted', $updated);
        Storage::disk('local')->put('election-archive/'.$id.'/summary.pdf', 'changed');
        $this->get(route('elections.history', $filters))->assertStatus(409);
    }

    public function test_state_csv_includes_all_scoped_tables_and_keeps_missing_candidates_explicit(): void
    {
        Storage::fake('local');
        [$id] = $this->edition();
        $path = 'election-archive/'.$id.'/extraction.json';
        $data = json_decode(Storage::disk('local')->get($path), true);
        $data['records'][] = array_replace($data['records'][0], ['code' => 453, 'name' => 'Empty seat', 'candidates' => []]);
        Storage::disk('local')->put($path, json_encode($data));
        $csv = $this->get(route('elections.history', ['edition' => $id, 'state' => 'S24', 'format' => 'csv']))->assertOk()->assertDownload('pollmedia-pc-2009-state-s24.csv')->streamedContent();
        $lines = explode("\r\n", trim(substr($csv, 3)));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Candidate One', $csv);
        $this->assertStringContainsString('Empty seat', $csv);
        $this->assertStringContainsString('no_candidate_rows', $csv);
        $this->assertStringNotContainsString('S01 / Other', $csv);
        [$ac] = $this->edition(2007, 'ac');
        $assembly = $this->get(route('elections.assembly', ['edition' => $ac, 'state' => 'Uttar Pradesh', 'format' => 'csv']))->assertOk()->assertDownload('pollmedia-ac-2007-state-uttar-pradesh.csv')->streamedContent();
        $this->assertStringContainsString('SEOHARA', $assembly);
        $this->assertStringContainsString('OTHER', $assembly);
        $this->get(route('elections.assembly', ['edition' => $ac, 'format' => 'csv']))->assertNotFound();
    }
}
