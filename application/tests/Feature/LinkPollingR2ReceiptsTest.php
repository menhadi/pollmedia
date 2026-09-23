<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LinkPollingR2ReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private function profile(bool $tested = true): int
    {
        return DB::table('pdf_storage_profiles')->insertGetId([
            'name' => 'R2 test', 'provider' => 'r2', 'bucket' => 'pollmedia', 'region' => 'auto',
            'endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com',
            'prefix' => 'pollmedia', 'credentials' => Crypt::encryptString('{"key":"test","secret":"test"}'),
            'tested_at' => $tested ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function source(string $id): array
    {
        $folder = str_repeat('b', 24);
        $sha = str_repeat('c', 64);
        $metadata = ['id' => $id, 'state' => 'EXAMPLE', 'folder' => $folder,
            'file' => $sha.'.pdf', 'source_url' => 'https://eci.gov.in/example.pdf'];
        DB::table('polling_source_documents')->insert(['id' => $id, 'state' => 'EXAMPLE',
            'sha256' => $sha, 'metadata' => json_encode($metadata),
            'created_at' => now(), 'updated_at' => now()]);

        return ['source_id' => $id, 'state' => 'EXAMPLE', 'sha256' => $sha, 'bytes' => 1234,
            'bucket' => 'pollmedia', 'endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com',
            'object_key' => 'pollmedia/polling-station-sources/'.$folder.'/'.$sha.'.pdf'];
    }

    private function receipts(array $entries): string
    {
        Storage::fake('local');
        Storage::disk('local')->put('receipts.jsonl', implode("\n", array_map('json_encode', $entries))."\n");

        return Storage::disk('local')->path('receipts.jsonl');
    }

    public function test_links_verified_receipts_idempotently_without_reimporting_pages(): void
    {
        $profile = $this->profile();
        $receipt = $this->source(str_repeat('d', 24));
        $path = $this->receipts([$receipt]);

        $this->artisan('polling:link-r2', ['--receipts' => $path, '--profile' => $profile])->assertSuccessful();
        $this->assertDatabaseHas('pdf_storage_files', [
            'path' => substr($receipt['object_key'], strlen('pollmedia/')),
            'sha256' => $receipt['sha256'], 'profile_id' => $profile,
            'object_key' => $receipt['object_key'],
        ]);
        $this->artisan('polling:link-r2', ['--receipts' => $path, '--profile' => $profile])->assertSuccessful();
        $this->assertDatabaseCount('pdf_storage_files', 1);
        $this->assertDatabaseCount('polling_source_pages', 0);
    }

    public function test_rolls_back_if_later_receipt_conflicts_with_imported_source(): void
    {
        $profile = $this->profile();
        $first = $this->source(str_repeat('d', 24));
        $bad = $this->source(str_repeat('e', 24));
        $bad['object_key'] = 'pollmedia/polling-station-sources/wrong.pdf';

        $this->artisan('polling:link-r2', ['--receipts' => $this->receipts([$first, $bad]),
            '--profile' => $profile])->assertFailed();
        $this->assertDatabaseCount('pdf_storage_files', 0);
    }

    public function test_rejects_untested_profile_and_duplicate_receipts(): void
    {
        $untested = $this->profile(false);
        $receipt = $this->source(str_repeat('d', 24));
        $path = $this->receipts([$receipt]);
        $this->artisan('polling:link-r2', ['--receipts' => $path, '--profile' => $untested])->assertFailed();
        $tested = $this->profile();
        $this->artisan('polling:link-r2', ['--receipts' => $this->receipts([$receipt, $receipt]),
            '--profile' => $tested])->assertFailed();
        $this->assertDatabaseCount('pdf_storage_files', 0);
    }

    public function test_reports_receipts_for_documents_not_yet_imported(): void
    {
        $profile = $this->profile();
        $receipt = $this->source(str_repeat('d', 24));
        DB::table('polling_source_documents')->delete();
        $path = $this->receipts([$receipt]);
        $this->artisan('polling:link-r2', ['--receipts' => $path, '--profile' => $profile])->assertSuccessful();
        $this->artisan('polling:link-r2', ['--receipts' => $path, '--profile' => $profile,
            '--state' => 'EXAMPLE'])->assertFailed();
        $this->assertDatabaseCount('pdf_storage_files', 0);
    }
}
