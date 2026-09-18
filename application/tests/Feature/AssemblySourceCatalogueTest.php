<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssemblySourceCatalogueTest extends TestCase
{
    public function test_source_download_requires_catalogue_membership_and_matching_checksum(): void
    {
        Storage::fake('local');
        $url = 'https://www.eci.gov.in/statistical-report/ae/2024/6';
        $archive = substr(hash('sha256', $url), 0, 24);
        $root = 'election-archive/'.$archive.'/';
        $body = '%PDF-official-test';
        Storage::disk('local')->put($root.'abc.pdf', $body);
        Storage::disk('local')->put($root.'manifest.json', json_encode(['url' => $url, 'status' => 'collected', 'files' => [['file' => 'abc.pdf', 'name' => 'Report.pdf', 'sha256' => hash('sha256', $body)]]]));
        $this->get('/india/elections/assembly/sources/'.$archive)->assertOk()->assertSee('Report.pdf');
        $this->get('/india/elections/assembly/sources/'.$archive.'?file=abc.pdf')->assertOk()->assertDownload('Report.pdf');
        $this->get('/india/elections/assembly/sources/'.$archive.'?file=other.pdf')->assertNotFound();
        $this->getJson('/india/elections/assembly/sources/'.$archive.'?file=../abc.pdf')->assertUnprocessable();
        Storage::disk('local')->put($root.'abc.pdf', 'changed');
        $this->get('/india/elections/assembly/sources/'.$archive.'?file=abc.pdf')->assertStatus(409);
    }

    public function test_national_and_historical_states_are_listed_without_claiming_extracted_results(): void
    {
        Storage::fake('local');
        $this->get('/india/elections/assembly/sources')->assertOk()->assertSee('427 report editions')
            ->assertSee('Bombay')->assertSee('Kerala')->assertSee('not necessarily downloaded or extracted');
        $this->get('/india/elections/assembly/sources?state=Kerala&year=2021')->assertOk()
            ->assertViewHas('entries', fn ($rows) => $rows->count() === 1 && $rows->first()['collected'] === 0);
        $this->get('/india/elections/assembly/sources?state=invalid')->assertRedirect();
    }
}
