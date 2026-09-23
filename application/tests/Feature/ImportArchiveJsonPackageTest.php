<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ImportArchiveJsonPackageTest extends TestCase
{
    use RefreshDatabase;

    private function package(bool $wrongEntryHash = false, bool $extraFile = false): array
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('archive.zip');
        $relative = 'election-archive/'.str_repeat('a', 24).'/extraction.json';
        $body = '{"source_url":"https://eci.gov.in/example","records":[{"votes":123}]}';
        $entry = ['path' => $relative, 'sha256' => $wrongEntryHash ? str_repeat('b', 64) : hash('sha256', $body),
            'bytes' => strlen($body)];
        $manifest = ['version' => 1, 'category' => 'election-archive',
            'bucket' => ord(hash('sha256', $relative, true)[0]) % 8, 'buckets' => 8,
            'files' => [$entry]];
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addFromString($relative, $body);
        if ($extraFile) {
            $archive->addFromString('unexpected.json', '{}');
        }
        $archive->addFromString('manifest.json', json_encode($manifest));
        $archive->close();

        return [$path, hash_file('sha256', $path), $relative, $body];
    }

    public function test_check_then_import_exact_json_idempotently(): void
    {
        [$package, $sha, $relative, $body] = $this->package();
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha,
            '--check' => true])->assertSuccessful();
        $this->assertDatabaseCount('archive_json_files', 0);
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha])->assertSuccessful();
        $this->assertDatabaseHas('archive_json_files', ['path' => $relative, 'sha256' => hash('sha256', $body),
            'source_url' => 'https://eci.gov.in/example']);
        $this->assertSame($body, app(ArchiveFiles::class)->get($relative));
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha])->assertSuccessful();
        $this->assertDatabaseCount('archive_json_files', 1);
    }

    public function test_rejects_changed_entry_even_when_package_hash_matches(): void
    {
        [$package, $sha] = $this->package(true);
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha])->assertFailed();
        $this->assertDatabaseCount('archive_json_files', 0);
    }

    public function test_rejects_unlisted_file_and_existing_conflict(): void
    {
        [$package, $sha, $relative] = $this->package(false, true);
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha])->assertFailed();
        [$package, $sha] = $this->package();
        DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $relative),
            'path' => $relative, 'category' => 'election-archive', 'sha256' => str_repeat('c', 64),
            'bytes' => 2, 'body' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha])->assertFailed();
        $this->assertDatabaseCount('archive_json_files', 1);
    }
}
