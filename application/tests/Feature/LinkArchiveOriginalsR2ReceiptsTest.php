<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use App\Services\PdfStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LinkArchiveOriginalsR2ReceiptsTest extends TestCase
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

    private function receipt(string $name = 'report.xlsx'): array
    {
        $path = 'election-archive/'.str_repeat('b', 24).'/'.$name;

        return ['source_id' => $path, 'category' => 'election-archive',
            'sha256' => hash('sha256', 'preserved workbook'), 'bytes' => strlen('preserved workbook'),
            'bucket' => 'pollmedia', 'object_key' => 'pollmedia/archive-source-originals/'.$path,
            'source_url' => 'https://old.eci.gov.in/files/file/report/', 'url_scope' => 'page',
            'endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com'];
    }

    private function receipts(array $entries): array
    {
        Storage::fake('local');
        $body = implode("\n", array_map('json_encode', $entries))."\n";
        Storage::disk('local')->put('original-receipts.jsonl', $body);

        return [Storage::disk('local')->path('original-receipts.jsonl'), hash('sha256', $body)];
    }

    public function test_links_verified_originals_and_reads_workbook_from_r2(): void
    {
        $profile = $this->profile();
        $receipt = $this->receipt();
        [$path, $sha] = $this->receipts([$receipt]);
        $options = ['--receipts' => $path, '--sha256' => $sha, '--profile' => $profile];
        $this->artisan('archive:link-originals', $options)->assertSuccessful();
        $this->artisan('archive:link-originals', $options)->assertSuccessful();
        $this->assertDatabaseCount('archive_original_files', 1);
        $this->assertDatabaseHas('archive_original_files', ['path' => $receipt['source_id'],
            'sha256' => $receipt['sha256'], 'source_url' => $receipt['source_url'],
            'url_scope' => 'page', 'profile_id' => $profile]);

        Storage::fake('cloud-test');
        Storage::disk('cloud-test')->put($receipt['object_key'], 'preserved workbook');
        $pdfs = Mockery::mock(PdfStorage::class)->makePartial();
        $pdfs->shouldReceive('remote')->with($profile)->andReturn(Storage::disk('cloud-test'));
        $this->app->instance(PdfStorage::class, $pdfs);
        $this->app->forgetInstance(ArchiveFiles::class);
        $files = app(ArchiveFiles::class);
        $this->assertTrue($files->exists($receipt['source_id']));
        $this->assertSame('preserved workbook', $files->get($receipt['source_id']));
        Storage::disk('cloud-test')->put($receipt['object_key'], 'changed workbook');
        try {
            $files->get($receipt['source_id']);
            $this->fail('Changed R2 bytes must fail checksum verification.');
        } catch (RuntimeException $error) {
            $this->assertSame('Archived file checksum differs.', $error->getMessage());
        }
        Storage::disk('cloud-test')->put($receipt['object_key'], 'preserved workbook');
        $working = $files->path($receipt['source_id']);
        $this->assertStringEndsWith('.xlsx', $working);
        $this->assertSame('preserved workbook', file_get_contents($working));
        $files->cleanup();
        $directory = dirname($receipt['source_id']);
        $files->withDirectory($directory, function () use ($receipt): void {
            $this->assertTrue(Storage::disk('local')->exists($receipt['source_id']));
            $this->assertSame('preserved workbook', Storage::disk('local')->get($receipt['source_id']));
        });
        Storage::disk('local')->assertMissing($receipt['source_id']);
    }

    public function test_rejects_untested_profile_and_bad_checksum(): void
    {
        [$path, $sha] = $this->receipts([$this->receipt()]);
        $this->artisan('archive:link-originals', ['--receipts' => $path, '--sha256' => $sha,
            '--profile' => $this->profile(false)])->assertFailed();
        $this->artisan('archive:link-originals', ['--receipts' => $path,
            '--sha256' => str_repeat('0', 64), '--profile' => $this->profile()])->assertFailed();
        $this->assertDatabaseCount('archive_original_files', 0);
    }

    public function test_rolls_back_duplicate_and_conflicting_receipts(): void
    {
        $profile = $this->profile();
        $first = $this->receipt();
        [$path, $sha] = $this->receipts([$first, $first]);
        $this->artisan('archive:link-originals', ['--receipts' => $path, '--sha256' => $sha,
            '--profile' => $profile])->assertFailed();
        $bad = $this->receipt('another.xlsx');
        $bad['object_key'] = $first['object_key'];
        [$path, $sha] = $this->receipts([$first, $bad]);
        $this->artisan('archive:link-originals', ['--receipts' => $path, '--sha256' => $sha,
            '--profile' => $profile])->assertFailed();
        $this->assertDatabaseCount('archive_original_files', 0);

    }
}
