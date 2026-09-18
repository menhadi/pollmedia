<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PollingSourceTest extends TestCase
{
    public function test_preserved_original_is_public_while_table_extraction_is_pending(): void
    {
        Storage::fake('local');
        $id = str_repeat('a', 24);
        $body = '%PDF-preserved official source';
        $sha = hash('sha256', $body);
        $root = 'polling-station-sources/';
        $source = ['id' => $id, 'folder' => $id, 'file' => $sha.'.pdf', 'sha256' => $sha, 'state' => 'Example',
            'name' => 'Official results', 'source_url' => 'https://eci.gov.in/source.pdf', 'discovered_on' => 'https://eci.gov.in/', 'pages' => [], 'polling_rows' => 0];
        Storage::disk('local')->put($root.$id.'/'.$source['file'], $body);
        Storage::disk('local')->put($root.'index.json', json_encode(['states' => [], 'sources' => [$source]]));
        $this->get('/india/elections/polling-stations')->assertOk()->assertSee('published with warnings')->assertSee('Table extraction is still pending.')->assertSee('Download preserved original');
        $url = '/india/elections/polling-stations?source='.$id.'&download=1';
        $this->get($url)->assertOk()->assertDownload($sha.'.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        Storage::disk('local')->put($root.$id.'/'.$source['file'], 'changed');
        $this->get($url)->assertStatus(503);
    }

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
                'pages' => [['page' => 1, 'sheet' => 'Source worksheet', 'file' => '1.json', 'sha256' => hash('sha256', $body), 'polling_rows' => 1,
                    'ocr' => ['file' => $ocrName, 'sha256' => hash('sha256', $ocrBody)]]]]],
        ]));
        $this->get('/india/elections/polling-stations')->assertOk()->assertSee('Polling station 2(A)')
            ->assertSee('>0<', false)->assertSee('Not read †')->assertSee('Review source totals.')
            ->assertSee('https://eci.gov.in/source.pdf', false)->assertSee('Source worksheet');
        $this->get('/india/elections/polling-stations')->assertSee('Unverified scanned text')->assertSee('OCR needs visual review.');
        $index = json_decode(Storage::disk('local')->get($root.'index.json'), true);
        $pageManifestBody = json_encode(['pages' => $index['sources'][0]['pages']]);
        $pageManifestName = $sha.'-pages-'.substr(hash('sha256', $pageManifestBody), 0, 16).'.json';
        $pageManifestPath = $root.$id.'/'.$pageManifestName;
        Storage::disk('local')->put($pageManifestPath, $pageManifestBody);
        $index['sources'][0]['pages'] = [];
        $index['sources'][0]['page_count'] = 1;
        $index['sources'][0]['page_manifest'] = ['file' => $pageManifestName, 'sha256' => hash('sha256', $pageManifestBody)];
        Storage::disk('local')->put($root.'index.json', json_encode($index));
        $this->get('/india/elections/polling-stations')->assertOk()->assertSee('Polling station 2(A)')->assertSee('Unverified scanned text');
        Storage::disk('local')->put($pageManifestPath, '{}');
        $this->get('/india/elections/polling-stations')->assertStatus(503);
        Storage::disk('local')->put($pageManifestPath, $pageManifestBody);
        $this->get('/india/elections/polling-stations?page=2')->assertNotFound();
        $this->get('/india/elections/polling-stations?source='.str_repeat('c', 24))->assertNotFound();
        Storage::disk('local')->put($path, '{}');
        $this->get('/india/elections/polling-stations')->assertStatus(503);
        Storage::disk('local')->put($path, $body);
        Storage::disk('local')->put($ocrPath, '{}');
        $this->get('/india/elections/polling-stations')->assertStatus(503);
    }
}
