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

    private function package(bool $wrongEntryHash = false, bool $extraFile = false,
        ?string $relativeOverride = null, ?string $bodyOverride = null, ?array $revision = null): array
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('archive.zip');
        $relative = $relativeOverride ?? 'election-archive/'.str_repeat('a', 24).'/extraction.json';
        $body = $bodyOverride ?? '{"source_url":"https://eci.gov.in/example","records":[{"votes":123}]}';
        $entry = ['path' => $relative, 'sha256' => $wrongEntryHash ? str_repeat('b', 64) : hash('sha256', $body),
            'bytes' => strlen($body)];
        if ($revision !== null) {
            $entry += $revision;
        }
        $category = explode('/', $relative, 2)[0];
        $manifest = ['version' => 1, 'category' => 'election-archive',
            'bucket' => ord(hash('sha256', $relative, true)[0]) % 8, 'buckets' => 8,
            'files' => [$entry]];
        $manifest['category'] = $category;
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

    public function test_revision_requires_exact_prior_snapshot_and_explicit_flag(): void
    {
        $relative = 'election-by-elections/results.json';
        $old = '{"records":[1],"unmapped":["needs_review"]}';
        $new = '{"records":[1],"unmapped":["source_navigation_table"]}';
        $previous = 'election-by-elections/results-'.hash('sha256', $old).'.json';
        $revision = ['replaces_sha256' => hash('sha256', $old), 'previous_path' => $previous];
        [$package, $sha] = $this->package(false, false, $relative, $new, $revision);
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha,
            '--allow-revision' => true, '--check' => true])->assertFailed();
        [$package, $sha] = $this->package(false, false, $relative, $old);
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha])->assertSuccessful();

        [$package, $sha] = $this->package(false, false, $relative, $new, $revision);
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha])->assertFailed();
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha,
            '--allow-revision' => true])->assertFailed();

        [$package, $sha] = $this->package(false, false, $previous, $old);
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha])->assertSuccessful();
        [$package, $sha] = $this->package(false, false, $relative, $new, $revision);
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha,
            '--allow-revision' => true, '--check' => true])->assertSuccessful();
        $this->assertSame($old, app(ArchiveFiles::class)->get($relative));
        $this->artisan('archive:import-json', ['package' => $package, '--sha256' => $sha,
            '--allow-revision' => true])->assertSuccessful();
        $this->assertSame($new, app(ArchiveFiles::class)->get($relative));
        $this->assertSame($old, app(ArchiveFiles::class)->get($previous));
        $this->assertDatabaseCount('archive_json_files', 2);
    }
}
