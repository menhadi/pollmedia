<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ByElectionResultTest extends TestCase
{
    public function test_results_preserve_missing_votes_notes_and_official_source(): void
    {
        Storage::fake('local');
        $id = str_repeat('a', 24);
        $record = ['id' => $id, 'year' => 2000, 'state' => 'Historical State', 'kind' => 'ac', 'constituency' => 'Example',
            'period' => '2000', 'edition' => $id, 'candidate_count' => 2, 'status' => 'needs_review',
            'source_url' => 'https://old.eci.gov.in/report.xls', 'raw_table_file' => str_repeat('b', 64).'-tables.json',
            'notes' => ['Source votes require review.'], 'candidates' => [
                ['name' => 'A', 'party' => 'P', 'votes' => null, 'source_row' => 1],
                ['name' => 'B', 'party' => 'Q', 'votes' => 0, 'source_row' => 2],
            ]];
        $body = json_encode($record);
        $file = $id.'-'.substr(hash('sha256', $body), 0, 16).'.json';
        $root = 'election-by-elections/structured/';
        Storage::disk('local')->put($root.$file, $body);
        Storage::disk('local')->put($root.'index.json', json_encode(['records' => [$record + ['file' => $file, 'sha256' => hash('sha256', $body)]]]));
        $this->get('/india/elections/by-elections/results')->assertOk()->assertSee('Source votes require review.')
            ->assertSee('Not available †')->assertSee('>0<', false)->assertSee('https://old.eci.gov.in/report.xls', false)
            ->assertSee('Open printable by-election result report');
        $this->get('/india/elections/by-elections/results?year=2000&record='.$id.'&format=report')
            ->assertOk()->assertSee('Example')->assertSee('Source votes require review.')
            ->assertSee('Not available †')->assertSee('>0<', false)
            ->assertSee(hash('sha256', $body))->assertSee('https://old.eci.gov.in/report.xls', false);
        $this->get('/india/elections/by-elections/results?format=report')->assertNotFound();
        $this->get('/india/elections/by-elections/results?record='.str_repeat('c', 24))->assertNotFound();
        $record['year'] = null;
        $record['source_year_text'] = '24.1158';
        $body = json_encode($record);
        $file = $id.'-'.substr(hash('sha256', $body), 0, 16).'.json';
        Storage::disk('local')->put($root.$file, $body);
        Storage::disk('local')->put($root.'index.json', json_encode(['records' => [$record + ['file' => $file, 'sha256' => hash('sha256', $body)]]]));
        $this->get('/india/elections/by-elections/results?year=0')->assertOk()->assertSee('Year unclear †')->assertSee('24.1158');
        Storage::disk('local')->put($root.$file, '{}');
        $this->get('/india/elections/by-elections/results')->assertStatus(503);
    }
}
