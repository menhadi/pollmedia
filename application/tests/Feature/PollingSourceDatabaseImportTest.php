<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PollingSourceDatabaseImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_database_view_opens_a_document_and_page_with_extracted_rows(): void
    {
        Storage::fake('local');
        Cache::forget('polling-source-summary');
        DB::table('polling_source_states')->insert(['name' => 'EXAMPLE',
            'metadata' => json_encode(['state' => 'EXAMPLE', 'url' => 'https://eci.gov.in/',
                'documents' => 2, 'pending_pages' => 0, 'errors' => []]),
            'created_at' => now(), 'updated_at' => now()]);
        $emptyId = str_repeat('a', 24);
        $mappedId = str_repeat('b', 24);
        foreach ([$emptyId => 0, $mappedId => 1] as $id => $rowCount) {
            $metadata = ['id' => $id, 'state' => 'EXAMPLE', 'name' => 'Report '.$rowCount,
                'folder' => str_repeat('c', 24), 'file' => str_repeat('d', 64).'.pdf',
                'sha256' => str_repeat('d', 64), 'source_url' => 'https://eci.gov.in/source.pdf',
                'discovered_on' => 'https://eci.gov.in/', 'polling_rows' => $rowCount];
            DB::table('polling_source_documents')->insert(['id' => $id, 'state' => 'EXAMPLE',
                'sha256' => $metadata['sha256'], 'metadata' => json_encode($metadata),
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([1 => [], 2 => [['polling_station' => 'Station One', 'valid_votes' => 5,
            'nota' => null, 'rejected_votes' => null, 'total_votes' => null,
            'notes' => [], 'candidate_votes' => []]]] as $page => $rows) {
            DB::table('polling_source_pages')->insert(['source_id' => $mappedId, 'page' => $page,
                'sha256' => str_repeat('e', 64),
                'metadata' => json_encode(['page' => $page, 'polling_rows' => count($rows), 'sheet' => null]),
                'payload' => json_encode(['polling_rows' => $rows, 'tables' => [], 'notes' => []]),
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->get('/india/elections/polling-stations?state=EXAMPLE')->assertOk()
            ->assertSee('Polling station Station One')
            ->assertViewHas('source', fn (array $source): bool => $source['id'] === $mappedId)
            ->assertViewHas('page', 2);
        $this->get('/india/elections/polling-stations?state=EXAMPLE&source='.$emptyId)->assertOk()
            ->assertDontSee('Polling station Station One')
            ->assertViewHas('source', fn (array $source): bool => $source['id'] === $emptyId);
        $this->get('/india/elections/polling-stations?state=EXAMPLE&source='.$mappedId.'&page=1')->assertOk()
            ->assertDontSee('Polling station Station One')->assertViewHas('page', 1);
    }

    public function test_large_state_document_choices_are_paginated(): void
    {
        Storage::fake('local');
        Cache::forget('polling-source-summary');
        DB::table('polling_source_states')->insert(['name' => 'EXAMPLE',
            'metadata' => json_encode(['state' => 'EXAMPLE', 'url' => 'https://eci.gov.in/',
                'documents' => 201, 'pending_pages' => 0, 'errors' => []]),
            'created_at' => now(), 'updated_at' => now()]);
        $documents = [];
        for ($number = 1; $number <= 201; $number++) {
            $id = sprintf('%024x', $number);
            $metadata = ['id' => $id, 'state' => 'EXAMPLE', 'name' => 'Report '.$number,
                'folder' => str_repeat('c', 24), 'file' => str_repeat('d', 64).'.pdf',
                'sha256' => str_repeat('d', 64), 'source_url' => 'https://eci.gov.in/source.pdf',
                'discovered_on' => 'https://eci.gov.in/', 'polling_rows' => 0];
            $documents[] = ['id' => $id, 'state' => 'EXAMPLE', 'sha256' => $metadata['sha256'],
                'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('polling_source_documents')->insert($documents);

        $this->get('/india/elections/polling-stations?state=EXAMPLE')->assertOk()
            ->assertSee('Documents 1–200 of 201')->assertSee('Next documents')->assertDontSee('Report 201');
        $this->get('/india/elections/polling-stations?state=EXAMPLE&source_page=2')->assertOk()
            ->assertSee('Documents 201–201 of 201')->assertSee('Previous documents')->assertSee('Report 201');
        $this->get('/india/elections/polling-stations?source='.sprintf('%024x', 201))->assertOk()
            ->assertSee('Report 201');
    }

    public function test_default_state_view_finds_mapped_rows_beyond_the_first_document_page(): void
    {
        Storage::fake('local');
        Cache::forget('polling-source-summary');
        DB::table('polling_source_states')->insert(['name' => 'EXAMPLE',
            'metadata' => json_encode(['state' => 'EXAMPLE', 'url' => 'https://eci.gov.in/',
                'documents' => 201, 'pending_pages' => 0, 'errors' => []]),
            'created_at' => now(), 'updated_at' => now()]);
        $documents = [];
        for ($number = 1; $number <= 201; $number++) {
            $id = sprintf('%024x', $number);
            $metadata = ['id' => $id, 'state' => 'EXAMPLE', 'name' => 'Report '.$number,
                'folder' => str_repeat('c', 24), 'file' => str_repeat('d', 64).'.pdf',
                'sha256' => str_repeat('d', 64), 'source_url' => 'https://eci.gov.in/source.pdf',
                'discovered_on' => 'https://eci.gov.in/', 'polling_rows' => $number === 201 ? 1 : 0];
            $documents[] = ['id' => $id, 'state' => 'EXAMPLE', 'sha256' => $metadata['sha256'],
                'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('polling_source_documents')->insert($documents);
        DB::table('polling_source_pages')->insert(['source_id' => sprintf('%024x', 201), 'page' => 1,
            'sha256' => str_repeat('e', 64),
            'metadata' => json_encode(['page' => 1, 'polling_rows' => 1, 'sheet' => null]),
            'payload' => json_encode(['polling_rows' => [['polling_station' => 'Station 201',
                'valid_votes' => null, 'nota' => null, 'rejected_votes' => null, 'total_votes' => null,
                'notes' => [], 'candidate_votes' => []]], 'tables' => [], 'notes' => []]),
            'created_at' => now(), 'updated_at' => now()]);

        $this->get('/india/elections/polling-stations?state=EXAMPLE')->assertOk()
            ->assertSee('Documents 201–201 of 201')->assertSee('Polling station Station 201')
            ->assertViewHas('source', fn (array $source): bool => $source['id'] === sprintf('%024x', 201));
        $this->get('/india/elections/polling-stations?state=EXAMPLE&source_page=1')->assertOk()
            ->assertSee('Documents 1–200 of 201')->assertDontSee('Polling station Station 201');
    }

    public function test_database_document_choices_are_scoped_to_the_selected_state(): void
    {
        Storage::fake('local');
        Cache::forget('polling-source-summary');
        foreach (['EXAMPLE', 'OTHER'] as $state) {
            DB::table('polling_source_states')->insert(['name' => $state,
                'metadata' => json_encode(['state' => $state, 'url' => 'https://eci.gov.in/',
                    'documents' => 1, 'pending_pages' => 0, 'errors' => []]),
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['EXAMPLE' => 'A report', 'OTHER' => 'B report'] as $state => $name) {
            $id = $state === 'EXAMPLE' ? str_repeat('a', 24) : str_repeat('b', 24);
            $metadata = ['id' => $id, 'state' => $state, 'name' => $name, 'folder' => str_repeat('c', 24),
                'file' => str_repeat('d', 64).'.pdf', 'sha256' => str_repeat('d', 64),
                'source_url' => 'https://eci.gov.in/source.pdf', 'discovered_on' => 'https://eci.gov.in/',
                'polling_rows' => 0];
            DB::table('polling_source_documents')->insert(['id' => $id, 'state' => $state,
                'sha256' => $metadata['sha256'], 'metadata' => json_encode($metadata),
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->get('/india/elections/polling-stations?state=EXAMPLE')->assertOk()
            ->assertSee('2 preserved source-document references')->assertSee('A report')->assertDontSee('B report');
        $this->get('/india/elections/polling-stations?source='.str_repeat('b', 24))->assertOk()
            ->assertSee('B report')->assertDontSee('A report');
        $this->get('/india/elections/polling-stations?state=EXAMPLE&source='.str_repeat('b', 24))->assertNotFound();
    }

    public function test_verified_tables_and_ocr_are_imported_and_served_from_database(): void
    {
        Storage::fake('local');
        $root = Storage::disk('local')->path('polling-station-sources');
        $folder = str_repeat('a', 24);
        $id = str_repeat('b', 24);
        $pdf = '%PDF-preserved source';
        $digest = hash('sha256', $pdf);
        $page = ['notes' => ['Check against original.'], 'tables' => [['number' => 1, 'cells' => [['1', '5']]]],
            'polling_rows' => [['polling_station' => '1', 'valid_votes' => 5, 'nota' => null,
                'rejected_votes' => null, 'total_votes' => null, 'notes' => ['Review value.'],
                'candidate_votes' => [['name' => 'Example candidate', 'votes' => 5]]]]];
        $pageBody = json_encode($page);
        $ocrBody = json_encode(['text' => 'Unverified source text', 'notes' => ['OCR requires review.']]);
        $pageFile = '1.json';
        $ocrFile = '1-'.substr(hash('sha256', $ocrBody), 0, 16).'.json';
        Storage::disk('local')->put('polling-station-sources/'.$folder.'/'.$digest.'.pdf', $pdf);
        Storage::disk('local')->put('polling-station-sources/'.$folder.'/'.$digest.'-tables/'.$pageFile, $pageBody);
        Storage::disk('local')->put('polling-station-sources/'.$folder.'/'.$digest.'-ocr/'.$ocrFile, $ocrBody);
        Storage::disk('local')->put('polling-station-sources/'.$folder.'/'.$digest.'-ocr/index.json', json_encode([
            'pages' => [['page' => 1, 'file' => $ocrFile, 'sha256' => hash('sha256', $ocrBody)]],
        ]));
        Storage::disk('local')->put('polling-station-sources/index.json', json_encode([
            'states' => [['state' => 'EXAMPLE', 'url' => 'https://eci.gov.in/', 'documents' => 1,
                'pending_pages' => 0, 'errors' => []]],
            'sources' => [['id' => $id, 'folder' => $folder, 'state' => 'EXAMPLE', 'name' => 'Official Form 20',
                'file' => $digest.'.pdf', 'sha256' => $digest, 'source_url' => 'https://eci.gov.in/source.pdf',
                'discovered_on' => 'https://eci.gov.in/', 'polling_rows' => 1,
                'pages' => [['page' => 1, 'file' => $pageFile, 'sha256' => hash('sha256', $pageBody),
                    'polling_rows' => 1, 'sheet' => null]]]],
        ]));
        $this->artisan('polling:import', ['--root' => $root])->assertSuccessful();
        $this->assertDatabaseCount('polling_source_documents', 1);
        $this->assertDatabaseCount('polling_source_pages', 1);
        $this->assertSame(1, count(json_decode(DB::table('polling_source_pages')->value('payload'), true)['polling_rows']));
        $this->get('/india/elections/polling-stations?source='.$id)->assertOk()
            ->assertSee('Example candidate')->assertSee('Unverified source text')
            ->assertSee('https://eci.gov.in/source.pdf', false);
        $this->get('/india/elections/polling-stations?source='.$id.'&download=1')->assertOk()
            ->assertDownload($digest.'.pdf')->assertHeader('Content-Type', 'application/pdf');
        $this->artisan('polling:import', ['--root' => $root])->assertSuccessful();
        $this->assertDatabaseCount('polling_source_pages', 1);
        $endpoint = 'https://'.str_repeat('c', 32).'.r2.cloudflarestorage.com';
        $profile = DB::table('pdf_storage_profiles')->insertGetId(['name' => 'R2 test', 'provider' => 'r2',
            'bucket' => 'pollmedia-pdfs', 'region' => 'auto', 'endpoint' => $endpoint, 'prefix' => 'pollmedia',
            'credentials' => Crypt::encryptString(json_encode(['key' => 'test', 'secret' => 'test'])),
            'tested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $receipt = Storage::disk('local')->path('r2-receipts.jsonl');
        Storage::disk('local')->put('r2-receipts.jsonl', json_encode(['source_id' => $id,
            'sha256' => $digest, 'bytes' => strlen($pdf), 'bucket' => 'pollmedia-pdfs',
            'endpoint' => $endpoint, 'object_key' => 'pollmedia/polling-station-sources/'.$folder.'/'.$digest.'.pdf'])."\n");
        $this->artisan('polling:import', ['--root' => $root, '--profile' => $profile,
            '--receipts' => $receipt])->assertSuccessful();
        $this->assertDatabaseHas('pdf_storage_files', ['path' => 'polling-station-sources/'.$folder.'/'.$digest.'.pdf',
            'sha256' => $digest, 'profile_id' => $profile]);
    }
}
