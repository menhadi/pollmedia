<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionArchive;
use App\Services\HistoricalElectionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoricalExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_review_requires_admin_preserves_source_identity_and_checks_integrity(): void
    {
        Storage::fake('local');
        $entry = app(ElectionArchive::class)->entries('ac', 2007)[0];
        $id = $entry['collection']['id'];
        $root = 'election-archive/'.$id.'/';
        $file = $id.'-7507.pdf';
        $hash = hash('sha256', '%PDF test');
        Storage::disk('local')->put($root.$file, '%PDF test');
        Storage::disk('local')->put($root.'manifest.json', json_encode(['url' => $entry['url'], 'files' => [['file' => $file, 'download_id' => $id.'-7507', 'sha256' => $hash, 'bytes' => 9, 'name' => 'Official 2007 report']], 'status' => 'collected', 'errors' => []]));
        $record = ['code' => 1, 'name' => 'SEOHARA', 'status' => 'validated', 'winner' => 'YASH PAL SINGH', 'margin' => 14878, 'detail_page' => 447, 'summary_page' => 44, 'electors' => 278856, 'votes_polled' => 152515, 'valid_candidate_votes' => 152026, 'candidates' => [['candidate_name' => 'YASH PAL SINGH', 'party_at_election' => 'BSP', 'general_votes' => 47751, 'postal_votes' => 0, 'votes' => 47751]]];
        $flagged = array_replace($record, ['code' => 44, 'name' => 'PURANPUR', 'status' => 'needs_review', 'error' => 'Candidate and report totals differ']);
        $data = ['source_url' => $entry['url'], 'source_file' => $file, 'source_sha256' => $hash, 'year' => 2007, 'kind' => 'ac', 'records' => [$record, $flagged], 'validated_count' => 1, 'review_count' => 1];
        Storage::disk('local')->put($root.'extraction.json', json_encode($data));
        $url = '/admin/imports/election-archives/'.$id.'/extraction';
        $this->get($url)->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/imports/elections?archive_type=ac&archive_year=2007')->assertOk()->assertSee('Review extracted candidate tables');
        $this->get($url.'?code=1')->assertOk()->assertSee('SEOHARA')->assertSee('14,878')->assertSee('47,751')->assertSee('PDF page 447');
        $this->get($url.'?code=44')->assertOk()->assertSee('Candidate and report totals differ')->assertDontSee('Winner:');
        $reviewUrl = '/admin/imports/election-archives/'.$id.'/extraction/44/review';
        $fingerprint = app(HistoricalElectionReview::class)->fingerprint($flagged, $hash);
        $this->post($reviewUrl, ['action' => 'accept', 'reason' => 'Reviewed the official PDF differences', 'fingerprint' => $fingerprint, 'review_id' => 0])->assertRedirect($url.'?code=44');
        $this->get($url.'?code=44')->assertOk()->assertDontSee('id="data-note"', false)->assertSee('Admin accepted')->assertSee('Review history and original source issue');
        $this->get($url.'?code=999')->assertNotFound();
        $this->assertDatabaseCount('election_contests', 0);
        $this->assertDatabaseCount('places', 0);
        Storage::disk('local')->put($root.$file, 'modified');
        $this->get($url)->assertStatus(409);
    }

    public function test_2002_missing_components_are_not_shown_as_zero(): void
    {
        Storage::fake('local');
        $entry = app(ElectionArchive::class)->entries('ac', 2002)[0];
        $id = $entry['collection']['id'];
        $root = 'election-archive/'.$id.'/';
        $file = $id.'-7505.pdf';
        $hash = hash('sha256', '%PDF test');
        Storage::disk('local')->put($root.$file, '%PDF test');
        Storage::disk('local')->put($root.'manifest.json', json_encode(['url' => $entry['url'], 'files' => [['file' => $file, 'download_id' => $id.'-7505', 'sha256' => $hash]], 'status' => 'collected', 'errors' => []]));
        $record = ['code' => 1, 'name' => 'SEOHARA', 'status' => 'validated', 'winner' => 'QUTUBUDEEN', 'margin' => 2149, 'detail_page' => 443, 'summary_page' => 40, 'electors' => 272785, 'votes_polled' => 164007, 'valid_candidate_votes' => 163780, 'candidates' => [['candidate_name' => 'QUTUBUDEEN', 'party_at_election' => 'BSP', 'general_votes' => null, 'postal_votes' => null, 'votes' => 37853]]];
        Storage::disk('local')->put($root.'extraction.json', json_encode(['source_url' => $entry['url'], 'source_file' => $file, 'source_sha256' => $hash, 'year' => 2002, 'kind' => 'ac', 'records' => [$record], 'validated_count' => 1, 'review_count' => 0]));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/imports/election-archives/'.$id.'/extraction?code=1')->assertOk()->assertSee('Not reported')->assertSee('37,853')->assertSee('2,149')->assertSee('2002');
        $this->assertDatabaseCount('places', 0);
    }

    public function test_lok_sabha_view_checks_both_source_reports(): void
    {
        Storage::fake('local');
        $entry = app(ElectionArchive::class)->entries('pc', 2009)[0];
        $id = $entry['collection']['id'];
        $root = 'election-archive/'.$id.'/';
        $hash = hash('sha256', '%PDF test');
        $files = [];
        foreach (['detail', 'summary'] as $name) {
            $file = $id.'-'.$name.'.pdf';
            Storage::disk('local')->put($root.$file, '%PDF test');
            $files[] = ['file' => $file, 'download_id' => $id.'-'.$name, 'sha256' => $hash, 'name' => $name.' official PDF'];
        }
        Storage::disk('local')->put($root.'manifest.json', json_encode(['url' => $entry['url'], 'files' => $files, 'status' => 'collected', 'errors' => []]));
        $record = ['code' => 451, 'official_pc_code' => 26, 'name' => 'S24 / Pilibhit', 'status' => 'needs_review', 'error' => 'Source totals differ', 'detail_page' => 160, 'summary_page' => 451, 'candidates' => []];
        Storage::disk('local')->put($root.'extraction.json', json_encode(['source_url' => $entry['url'], 'source_file' => $files[0]['file'], 'source_sha256' => $hash, 'additional_sources' => [$files[1]], 'year' => 2009, 'kind' => 'pc', 'records' => [$record], 'validated_count' => 0, 'review_count' => 1]));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $url = '/admin/imports/election-archives/'.$id.'/extraction?code=451';
        $this->get($url)->assertOk()->assertSee('Lok Sabha / 2009')->assertSee('26 / S24 / Pilibhit')->assertSee('summary official PDF')->assertSee('† Data note:');
        Storage::disk('local')->put($root.$files[1]['file'], 'changed');
        $this->get($url)->assertStatus(409);
        $this->assertDatabaseCount('places', 0);
    }

    public function test_unsupported_year_is_not_silently_extracted_as_2007(): void
    {
        $this->artisan('imports:extract-historical-election', ['--year' => '1990'])->assertFailed();
    }
}
