<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LinkArchivedR2ReceiptsTest extends TestCase
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

    private function receipt(string $category = 'election-archive', string $folder = 'example'): array
    {
        $path = $category.'/'.$folder.'/report.pdf';

        return ['source_id' => $path, 'category' => $category, 'sha256' => str_repeat('c', 64),
            'bytes' => 1234, 'bucket' => 'pollmedia', 'object_key' => 'pollmedia/'.$path,
            'source_url' => $category === 'census-archive'
                ? 'https://censusindia.gov.in/nada/report.pdf' : 'https://old.eci.gov.in/files/file/report/',
            'endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com',
            'recovery_evidence' => null];
    }

    private function receipts(array $entries): array
    {
        Storage::fake('local');
        $body = implode("\n", array_map('json_encode', $entries))."\n";
        Storage::disk('local')->put('archive-receipts.jsonl', $body);

        return [Storage::disk('local')->path('archive-receipts.jsonl'), hash('sha256', $body)];
    }

    public function test_links_archive_and_census_pdfs_with_source_evidence_idempotently(): void
    {
        $profile = $this->profile();
        $election = $this->receipt();
        $election['recovery_evidence'] = ['download_number' => '5', 'download_page_sha256' => str_repeat('d', 64)];
        $census = $this->receipt('census-archive');
        [$path, $sha] = $this->receipts([$election, $census]);

        $options = ['--receipts' => $path, '--sha256' => $sha, '--profile' => $profile];
        $this->artisan('archive:link-r2', $options)->assertSuccessful();
        $this->artisan('archive:link-r2', $options)->assertSuccessful();
        $this->assertDatabaseCount('pdf_storage_files', 2);
        $this->assertDatabaseCount('archive_pdf_sources', 2);
        $this->assertDatabaseHas('pdf_storage_files', ['path' => $election['source_id'],
            'sha256' => $election['sha256'], 'profile_id' => $profile, 'object_key' => $election['object_key']]);
        $this->assertDatabaseHas('archive_pdf_sources', ['path_hash' => hash('sha256', $election['source_id']),
            'source_url' => $election['source_url']]);
    }

    public function test_rejects_wrong_checksum_and_untested_profile(): void
    {
        [$path, $sha] = $this->receipts([$this->receipt()]);
        $this->artisan('archive:link-r2', ['--receipts' => $path, '--sha256' => str_repeat('0', 64),
            '--profile' => $this->profile()])->assertFailed();
        $this->artisan('archive:link-r2', ['--receipts' => $path, '--sha256' => $sha,
            '--profile' => $this->profile(false)])->assertFailed();
        $this->assertDatabaseCount('pdf_storage_files', 0);
    }

    public function test_rolls_back_on_duplicate_or_tampered_later_receipt(): void
    {
        $profile = $this->profile();
        $first = $this->receipt();
        [$path, $sha] = $this->receipts([$first, $first]);
        $this->artisan('archive:link-r2', ['--receipts' => $path, '--sha256' => $sha,
            '--profile' => $profile])->assertFailed();
        $bad = $this->receipt('election-by-elections', 'other');
        $bad['object_key'] = 'pollmedia/election-archive/wrong.pdf';
        [$path, $sha] = $this->receipts([$first, $bad]);
        $this->artisan('archive:link-r2', ['--receipts' => $path, '--sha256' => $sha,
            '--profile' => $profile])->assertFailed();
        $this->assertDatabaseCount('pdf_storage_files', 0);
        $this->assertDatabaseCount('archive_pdf_sources', 0);
    }

    public function test_rejects_conflicting_existing_inventory_and_source_url(): void
    {
        $profile = $this->profile();
        $receipt = $this->receipt();
        [$path, $sha] = $this->receipts([$receipt]);
        $options = ['--receipts' => $path, '--sha256' => $sha, '--profile' => $profile];
        $this->artisan('archive:link-r2', $options)->assertSuccessful();
        DB::table('archive_pdf_sources')->where('path_hash', hash('sha256', $receipt['source_id']))
            ->update(['source_url' => 'https://old.eci.gov.in/other/']);
        $this->artisan('archive:link-r2', $options)->assertFailed();
        $this->assertDatabaseCount('pdf_storage_files', 1);
        $this->assertDatabaseCount('archive_pdf_sources', 1);

    }
}
