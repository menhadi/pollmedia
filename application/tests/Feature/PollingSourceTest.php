<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PollingSourceTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_missing_collection_is_described_as_a_gap(): void
    {
        Storage::fake('local');
        $this->get('/india/elections/polling-stations')->assertOk()
            ->assertSee('collection gap');
    }

    public function test_source_pages_preserve_missing_votes_and_verify_integrity(): void
    {
        Storage::fake('local');
        $id = str_repeat('a', 24);
        $sha = str_repeat('b', 64);
        $data = ['notes' => ['Review source totals.'], 'tables' => [], 'polling_rows' => [
            ['polling_station' => '2(A)', 'valid_votes' => 0, 'nota' => null, 'rejected_votes' => null,
                'total_votes' => null, 'notes' => ['An unreadable cell remains missing.'],
                'candidate_votes' => [['name' => 'Candidate A', 'votes' => 0], ['name' => 'Candidate B', 'votes' => null]]],
        ]];
        $body = json_encode($data);
        $root = 'polling-station-sources/';
        $path = $root.$id.'/'.$sha.'-tables/1.json';
        Storage::disk('local')->put($path, $body);
        $ocrBody = json_encode(['text' => 'Unverified scanned text', 'notes' => ['OCR needs visual review.']]);
        $ocrName = '1-'.substr(hash('sha256', $ocrBody), 0, 16).'.json';
        $ocrPath = $root.$id.'/'.$sha.'-ocr/'.$ocrName;
        Storage::disk('local')->put($ocrPath, $ocrBody);
        Storage::disk('local')->put($root.'index.json', json_encode([
            'states' => [['state' => 'Example', 'url' => 'https://eci.gov.in/', 'documents' => 1, 'pending_pages' => 0, 'errors' => []]],
            'sources' => [['id' => $id, 'folder' => $id, 'sha256' => $sha, 'state' => 'Example', 'name' => 'Form 20',
                'source_url' => 'https://eci.gov.in/source.pdf', 'discovered_on' => 'https://eci.gov.in/', 'polling_rows' => 1,
                'pages' => [['page' => 1, 'file' => '1.json', 'sha256' => hash('sha256', $body), 'polling_rows' => 1,
                    'ocr' => ['file' => $ocrName, 'sha256' => hash('sha256', $ocrBody)]]]]],
        ]));
        $this->get('/india/elections/polling-stations')->assertOk()->assertSee('Polling station 2(A)')
            ->assertSee('>0<', false)->assertSee('Not read †')->assertSee('Review source totals.')
            ->assertSee('https://eci.gov.in/source.pdf', false);
        $this->get('/india/elections/polling-stations')->assertSee('Unverified scanned text')->assertSee('OCR needs visual review.');
        $this->get('/india/elections/polling-stations?page=2')->assertNotFound();
        $this->get('/india/elections/polling-stations?source='.str_repeat('c', 24))->assertNotFound();
        Storage::disk('local')->put($path, '{}');
        $this->get('/india/elections/polling-stations')->assertStatus(503);
        Storage::disk('local')->put($path, $body);
        Storage::disk('local')->put($ocrPath, '{}');
        $this->get('/india/elections/polling-stations')->assertStatus(503);
    }
}
