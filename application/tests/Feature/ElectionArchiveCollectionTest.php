<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ElectionArchiveCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_modern_catalogue_file_ids_can_be_downloaded(): void
    {
        Storage::fake('local');
        $entry = app(ElectionArchive::class)->entries('pc', 2024)[0];
        $id = $entry['collection']['id'];
        $file = str_repeat('b', 24);
        $content = '%PDF-1.7 official catalogue test';
        Storage::disk('local')->put('election-archive/'.$id.'/'.$file.'.pdf', $content);
        Storage::disk('local')->put('election-archive/'.$id.'/manifest.json', json_encode(['url' => $entry['url'], 'status' => 'collected', 'files' => [['download_id' => $file, 'file' => $file.'.pdf', 'name' => 'Report.pdf', 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)]], 'errors' => []]));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/imports/election-archives/'.$id.'/'.$file)->assertOk()->assertDownload();
    }

    public function test_archived_files_show_collection_status_and_require_admin_and_valid_checksums(): void
    {
        Storage::fake('local');
        $service = app(ElectionArchive::class);
        $entry = $service->entries('ac', 2012)[0];
        $this->assertSame('not_collected', $entry['collection']['status']);
        $id = $entry['collection']['id'];
        $file = str_repeat('a', 24).'-123';
        $content = '%PDF-1.4 test';
        Storage::disk('local')->put('election-archive/'.$id.'/'.$file.'.pdf', $content);
        Storage::disk('local')->put('election-archive/'.$id.'/manifest.json', json_encode(['url' => $entry['url'], 'status' => 'collected', 'files' => [['download_id' => $file, 'file' => $file.'.pdf', 'name' => '2012 official report.pdf', 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)]], 'errors' => []]));
        $url = '/admin/imports/election-archives/'.$id.'/'.$file;
        $this->get($url)->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/imports/elections?archive_type=ac&archive_year=2012')->assertOk()->assertSee('2012 official report.pdf')->assertSee('collected');
        $this->get($url)->assertOk()->assertDownload();
        Storage::disk('local')->put('election-archive/'.$id.'/'.$file.'.pdf', 'changed');
        $this->get($url)->assertStatus(409);
    }
}
